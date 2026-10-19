<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Product;
use Illuminate\Http\JsonResponse;

/**
 * Aksi cepat di daftar produk (`products.status`, `products.restore`,
 * `variants.quick`) dijawab JSON kalau klien memintanya (`Accept:
 * application/json` dari `initAjaxForms()` di `resources/js/app.js`).
 *
 * Markup baris TIDAK dibuat ulang di JavaScript: server tetap merender partial
 * `admin.products._row` yang sama dengan halaman daftar, lalu halaman hanya
 * menukar potongan yang ditandai `data-product-*`. Jadi tampilan baris selalu
 * berasal dari satu sumber.
 *
 * Permintaan tanpa header JSON tetap memakai `back()` seperti sebelumnya
 * (form biasa / tanpa JavaScript tetap berfungsi).
 */
trait MerenderBarisProduk
{
    /** Relasi yang dibutuhkan `admin.products._row` — sama seperti daftar produk. */
    protected function muatBarisProduk(Product $product): Product
    {
        return $product->load([
            'category',
            'variants' => fn ($q) => $q->withCount([
                'stockItems as available_count' => fn ($s) => $s->available(),
            ]),
        ]);
    }

    /**
     * Angka tab status + "tanpa varian aktif".
     *
     * Sengaja global (bukan hasil filter pencarian) supaya sama dengan yang
     * dihitung `admin.products.index`.
     */
    protected function hitungTabProduk(): array
    {
        return [
            'semua' => Product::query()->count(),
            'aktif' => Product::query()->where('is_active', true)->count(),
            'nonaktif' => Product::query()->where('is_active', false)->count(),
            'sampah' => Product::query()->onlyTrashed()->count(),
            'tanpa_varian' => Product::query()
                ->where('is_active', true)
                ->whereDoesntHave('activeVariants')
                ->count(),
        ];
    }

    /**
     * Jawaban JSON standar untuk aksi cepat di daftar produk.
     *
     * `is_active` + `trashed` dipakai halaman untuk memutuskan apakah baris
     * masih pantas tampil di tab status yang sedang dibuka (mis. produk
     * disembunyikan saat tab "Aktif" aktif → barisnya dilepas).
     */
    protected function jawabBarisProduk(Product $product, string $message, bool $sampah = false): JsonResponse
    {
        $product = $this->muatBarisProduk($product);

        return response()->json([
            'ok' => true,
            'message' => $message,
            'row' => view('admin.products._row', [
                'product' => $product,
                'sampah' => $sampah,
            ])->render(),
            'counts' => $this->hitungTabProduk(),
            'is_active' => (bool) $product->is_active,
            'trashed' => $product->trashed(),
            // Dipakai filter "Tanpa varian aktif": baris dilepas begitu varian
            // pertamanya dijual.
            'aktif_varian' => $product->variants->where('is_active', true)->count(),
        ]);
    }
}
