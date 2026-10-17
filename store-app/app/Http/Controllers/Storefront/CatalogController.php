<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class CatalogController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'kategori' => ['nullable', 'string', 'max:80'],
            'urut' => ['nullable', 'in:sesuai,terlaris,termurah,termahal,terbaru,rating'],
            'min' => ['nullable', 'integer', 'min:0'],
            'max' => ['nullable', 'integer', 'min:0'],
        ]);

        $category = null;

        if (! empty($filters['kategori'])) {
            $category = Category::query()->where('slug', $filters['kategori'])->first();
        }

        $query = Product::query()->visible()->with(['category', 'activeVariants']);

        if ($category) {
            $query->where('category_id', $category->id);
        }

        $query->search($filters['q'] ?? null)
            ->priceBetween($filters['min'] ?? null, $filters['max'] ?? null)
            ->sorted($filters['urut'] ?? 'sesuai');

        $products = $query->paginate(24)->withQueryString();

        return view('storefront.catalog', [
            'products' => $products,
            'categories' => Category::query()->active()->ordered()->get(),
            'activeCategory' => $category,
            'filters' => $filters,
            'query' => $filters['q'] ?? '',
        ]);
    }

    public function show(Product $product)
    {
        abort_unless($product->is_active, 404);

        $product->load(['category', 'activeVariants', 'images']);

        $product->increment('view_count');

        $reviews = $product->approvedReviews()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $summary = $product->reviewSummary();

        $related = Product::query()
            ->visible()
            ->with(['activeVariants'])
            ->where('category_id', $product->category_id)
            ->whereKeyNot($product->id)
            ->limit(4)
            ->get();

        // Ulasan hanya bisa dikirim untuk pesanan yang invoice-nya ada di
        // session pembeli (lihat ReviewController) — jadi flag ini untuk UI:
        // form ulasan hanya muncul untuk pembeli produk ini.
        $canReview = $this->canReview($product);

        return view('storefront.product', [
            'product' => $product,
            'reviews' => $reviews,
            'summary' => $summary,
            'related' => $related,
            'canReview' => $canReview,
            'reviewCooldown' => RateLimiter::tooManyAttempts('review:'.request()->ip(), 5),
        ]);
    }

    private function canReview(Product $product): bool
    {
        $invoices = order_access()->invoices();

        if ($invoices === []) {
            return false;
        }

        // Form ulasan hanya tampil untuk pembeli produk INI: harus ada pesanan
        // yang berisi produk ini dengan invoice di session, dan belum mengulas.
        return Order::query()
            ->whereIn('invoice_no', $invoices)
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->whereDoesntHave('reviews')
            ->exists();
    }
}
