<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\MerenderBarisProduk;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use MerenderBarisProduk;

    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'kategori' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:aktif,nonaktif,sampah'],
            'masalah' => ['nullable', 'in:tanpa_varian'],
            'urut' => ['nullable', 'in:urutan,nama,termurah,termahal,terjual,terbaru'],
        ]);

        $sampah = ($filters['status'] ?? null) === 'sampah';
        $urut = $filters['urut'] ?? 'urutan';

        $query = Product::query()
            ->when($sampah, fn ($q) => $q->onlyTrashed())
            // Semua varian (aktif & nonaktif) ikut dimuat: panel "kelola varian"
            // di daftar produk bisa mengubah harga/status tanpa pindah halaman.
            ->with([
                'category',
                'variants' => fn ($q) => $q->withCount([
                    'stockItems as available_count' => fn ($s) => $s->available(),
                ]),
            ])
            ->search($filters['q'] ?? null)
            ->when($filters['kategori'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when(($filters['status'] ?? null) === 'aktif', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'nonaktif', fn ($q) => $q->where('is_active', false))
            ->when(($filters['masalah'] ?? null) === 'tanpa_varian', fn ($q) => $q->whereDoesntHave('activeVariants'));

        // Urutan daftar. Subquery harga tetap portabel MySQL + SQLite.
        if ($urut === 'nama') {
            $query->orderBy('name')->orderBy('id');
        } elseif ($urut === 'termurah') {
            $query->orderByRaw(
                '(SELECT MIN(price) FROM variants WHERE variants.product_id = products.id AND variants.is_active = 1 AND variants.price > 0) ASC'
            );
        } elseif ($urut === 'termahal') {
            $query->orderByRaw(
                '(SELECT MAX(price) FROM variants WHERE variants.product_id = products.id AND variants.is_active = 1 AND variants.price > 0) DESC'
            );
        } elseif ($urut === 'terjual') {
            $query->orderByDesc('sold_count');
        } elseif ($urut === 'terbaru') {
            $query->orderByDesc('created_at');
        } else {
            $query->orderBy('sort_order')->orderByDesc('id');
        }

        $products = $query->paginate(20)->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'categories' => Category::query()->ordered()->get(),
            'filters' => $filters,
            'sampah' => $sampah,
            // Angka tab status dipakai bersama jawaban JSON aksi cepat.
            'counts' => $this->hitungTabProduk(),
        ]);
    }

    /**
     * Aktif/nonaktifkan produk langsung dari daftar (tanpa membuka form edit).
     *
     * Nilai tujuan dikirim eksplisit (`is_active` = 0/1), bukan dibalik di
     * server, supaya klik ganda / dua tab tidak menghasilkan status acak.
     */
    public function status(Request $request, Product $product)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $product->update(['is_active' => (bool) $data['is_active']]);

        ActivityLog::record(
            'product.status',
            'Produk "'.$product->name.'" '.($product->is_active ? 'diaktifkan' : 'dinonaktifkan'),
            $product
        );

        $pesan = 'Produk "'.$product->name.'" '.($product->is_active ? 'ditampilkan di toko.' : 'disembunyikan dari toko.');

        // Aksi cepat dari daftar: halaman memperbarui baris tanpa reload.
        if ($request->wantsJson()) {
            return $this->jawabBarisProduk($product, $pesan);
        }

        return back()->with('success', $pesan);
    }

    public function create()
    {
        return view('admin.products.form', [
            'product' => new Product(['is_active' => true, 'payment_type' => 'default']),
            'categories' => Category::query()->ordered()->get(),
            'variants' => collect(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $product = Product::create($data + [
            'slug' => $this->uniqueSlug($data['name']),
        ]);

        $this->storeCover($request, $product);

        ActivityLog::record('product.created', 'Produk "'.$product->name.'" dibuat', $product);

        return redirect()
            ->route('admin.products.edit', $product)
            ->with('success', 'Produk dibuat. Tambahkan varian beserta harganya.');
    }

    public function edit(Product $product)
    {
        return view('admin.products.form', [
            'product' => $product->load('images'),
            'categories' => Category::query()->ordered()->get(),
            'variants' => $product->variants()
                ->withCount(['stockItems as available_count' => fn ($q) => $q->available()])
                ->get(),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validated($request);

        if ($data['name'] !== $product->name) {
            $data['slug'] = $this->uniqueSlug($data['name'], $product->id);
        }

        $product->update($data);
        $this->storeCover($request, $product);

        ActivityLog::record('product.updated', 'Produk "'.$product->name.'" diperbarui', $product, [
            'changed' => array_keys($data),
        ]);

        // Form edit: simpan tanpa memuat ulang halaman (toast + pratinjau
        // gambar utama ikut diperbarui dari jawaban ini).
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Perubahan produk disimpan.',
                'name' => $product->name,
                'cover_url' => $product->coverUrl(),
            ]);
        }

        return back()->with('success', 'Perubahan produk disimpan.');
    }

    public function destroy(Product $product)
    {
        $name = $product->name;
        $product->delete(); // soft delete: order lama tetap utuh

        ActivityLog::record('product.deleted', 'Produk "'.$name.'" dinonaktifkan (soft delete)');

        return redirect()->route('admin.products.index')->with('success', 'Produk "'.$name.'" dihapus (bisa dipulihkan).');
    }

    public function restore(Request $request, int $id)
    {
        $product = Product::withTrashed()->findOrFail($id);
        $product->restore();

        ActivityLog::record('product.restored', 'Produk "'.$product->name.'" dipulihkan', $product);

        // Setelah dipulihkan produk tidak lagi "sampah", jadi barisnya dirender
        // sebagai baris biasa (tombol Sembunyikan/Edit kembali muncul).
        if ($request->wantsJson()) {
            return $this->jawabBarisProduk($product, 'Produk "'.$product->name.'" dipulihkan.');
        }

        return back()->with('success', 'Produk dipulihkan.');
    }

    // ---------------------------------------------------------------- helpers
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:20000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'warranty' => ['nullable', 'string', 'max:120'],
            'payment_type' => ['nullable', 'string', 'max:30'],
            'sold_count' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
            'is_best_seller' => ['nullable', 'boolean'],
            'is_hot' => ['nullable', 'boolean'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'meta_title' => ['nullable', 'string', 'max:180'],
            'meta_description' => ['nullable', 'string', 'max:255'],
        ]) + [
            'is_active' => $request->boolean('is_active'),
            'is_best_seller' => $request->boolean('is_best_seller'),
            'is_hot' => $request->boolean('is_hot'),
            'payment_type' => $request->input('payment_type', 'default'),
        ];
    }

    private function storeCover(Request $request, Product $product): void
    {
        if (! $request->hasFile('cover')) {
            return;
        }

        if ($product->cover_image) {
            Storage::disk('public')->delete($product->cover_image);
        }

        $product->forceFill([
            'cover_image' => $request->file('cover')->store('products', 'public'),
        ])->save();
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'produk';
        $slug = $base;
        $i = 2;

        while (Product::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
