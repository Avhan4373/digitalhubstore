<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Impor katalog hasil scrape (`data/seed/*.json` dari project scraper).
 *
 * Idempoten: dijalankan berulang tidak menduplikasi data, karena kunci
 * `external_id` (id di situs asal) dipakai sebagai acuan.
 *
 * Yang TIDAK diimpor: entri stok berisi kredensial akun pihak ketiga
 * (dibuang saat build_seed.py).
 */
class CatalogSeeder extends Seeder
{
    private string $seedDir;

    private string $imageDir;

    private string $publicDir;

    public function run(): void
    {
        $this->seedDir = $this->resolveSeedDir();
        $this->imageDir = base_path('../data/images');
        $this->publicDir = storage_path('app/public/products');

        if (! is_dir($this->seedDir)) {
            $this->command?->warn("Direktori seed tidak ada: {$this->seedDir}. Jalankan `python3 tools/build_seed.py` dulu.");

            return;
        }

        File::ensureDirectoryExists($this->publicDir);

        $this->seedCategories();
        $this->seedProducts();
        $this->seedVariants();
        $this->seedReviews();
    }

    private function resolveSeedDir(): string
    {
        return (string) config('catalog.seed_dir');
    }

    private function read(string $file): array
    {
        $path = $this->seedDir.'/'.$file;

        if (! File::exists($path)) {
            $this->command?->warn("Seed {$file} tidak ditemukan.");

            return [];
        }

        return json_decode(File::get($path), true) ?: [];
    }

    private function seedCategories(): void
    {
        $rows = $this->read('categories.json');

        foreach ($rows as $row) {
            Category::updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'icon' => $this->iconFor($row['slug']),
                    'sort_order' => $row['sort_order'] ?? 0,
                    'is_active' => true,
                ]
            );
        }

        $this->command?->info('Kategori: '.count($rows));
    }

    private function seedProducts(): void
    {
        $rows = $this->read('products.json');
        $categories = Category::pluck('id', 'slug');
        $copied = 0;

        foreach ($rows as $row) {
            $cover = null;

            if (! empty($row['cover_image'])) {
                $source = $this->imageDir.'/'.basename($row['cover_image']);
                if (File::exists($source)) {
                    $target = $this->publicDir.'/'.basename($row['cover_image']);
                    if (! File::exists($target)) {
                        File::copy($source, $target);
                        $copied++;
                    }
                    $cover = 'products/'.basename($row['cover_image']);
                }
            }

            Product::updateOrCreate(
                ['external_id' => $row['external_id']],
                [
                    'category_id' => $categories[$row['category_slug']] ?? null,
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'description' => $row['description'],
                    'terms' => $row['terms'],
                    'warranty' => $row['warranty'],
                    'payment_type' => $row['payment_type'],
                    'cover_image' => $cover,
                    'is_best_seller' => $row['is_best_seller'],
                    'is_hot' => $row['is_hot'],
                    'is_active' => $row['is_active'],
                    'sold_count' => $row['sold_count'],
                    'sold_is_estimate' => true,
                    'rating_source' => $row['rating_source'],
                    'meta_title' => mb_substr($row['name'].' — murah & bergaransi', 0, 180),
                    'meta_description' => mb_substr(strip_tags($row['description']), 0, 250),
                    'sort_order' => $row['sort_order'],
                ]
            );
        }

        $this->command?->info('Produk: '.count($rows)." (gambar disalin: {$copied})");
    }

    private function seedVariants(): void
    {
        $rows = $this->read('variants.json');
        $products = Product::pluck('id', 'external_id');
        $instruction = 0;
        $skipped = 0;
        $rowCount = count($rows);

        foreach ($rows as $row) {
            $productId = $products[$row['product_external_id']] ?? null;

            if (! $productId) {
                $skipped++;
                continue;
            }

            $links = array_values(array_filter($row['delivery_links'] ?? []));
            $instructionText = trim((string) ($row['delivery_instruction'] ?? ''));

            // Mode pengiriman ditentukan dari bentuk data aslinya:
            // ada tautan -> kirim link; hanya teks -> manual via admin.
            // Tidak ada varian `auto` karena stok akun tidak diimpor.
            $deliveryType = $links !== [] ? Variant::DELIVERY_FILE : Variant::DELIVERY_MANUAL;

            if ($instructionText !== '') {
                $instruction++;
            }

            // Kunci memakai external_id, BUKAN (produk, nama): situs asal punya
            // dua varian ber-nama sama dengan harga berbeda (mis. "1 Bulan Share
            // Platinum Mobile Only" Rp 28.000 dan Rp 18.000). Memakai nama sebagai
            // kunci akan menimpa salah satunya.
            Variant::updateOrCreate(
                ['external_id' => $row['external_id']],
                [
                    'product_id' => $productId,
                    'name' => $row['name'],
                    'price' => $row['price'],
                    'original_price' => $row['original_price'],
                    'stock_original' => $row['stock_original'],
                    'delivery_type' => $deliveryType,
                    'delivery_instruction' => $instructionText ?: null,
                    'delivery_links' => $links ?: null,
                    'description' => $row['description'] ?: null,
                    'terms' => $row['terms'] ?: null,
                    'payment_type' => $row['payment_type'],
                    'is_active' => true,
                    'sort_order' => $row['sort_order'],
                ]
            );
        }

        $this->command?->info(
            'Varian: '.Variant::query()->whereNotNull('external_id')->count()
            ." dari {$rowCount} baris seed"
            ." (punya instruksi kirim: {$instruction}"
            .($skipped > 0 ? ", dilewati: {$skipped}" : '')
            .')'
        );
    }

    private function seedReviews(): void
    {
        $rows = $this->read('reviews.json');
        $products = Product::pluck('id', 'external_id');
        $saved = 0;

        foreach ($rows as $row) {
            $productId = $products[$row['product_external_id']] ?? null;

            if (! $productId || $row['rating'] < 1) {
                continue;
            }

            $created = $this->parseDate($row['created_at'] ?? null);

            $review = \App\Models\Review::updateOrCreate(
                ['external_id' => $row['external_id']],
                [
                    'product_id' => $productId,
                    'user_name' => $row['user_name'] ?: 'Pembeli',
                    'rating' => min(5, max(1, (int) $row['rating'])),
                    'comment' => $row['comment'],
                    'variant_name' => $row['variant_name'] ?: null,
                    'status' => $row['status'] ?? 'approved',
                    'created_at' => $created,
                    'updated_at' => $created,
                ]
            );

            if ($review->wasRecentlyCreated) {
                $saved++;
            }
        }

        $this->command?->info('Ulasan: '.count($rows)." (baru: {$saved}, status: approved)");
    }

    private function iconFor(string $slug): string
    {
        return match ($slug) {
            'ai' => 'sparkles',
            'stream' => 'play-circle',
            'editor' => 'wand-2',
            'landing' => 'layout-template',
            'template-web' => 'code-2',
            'meeting' => 'video',
            default => 'package',
        };
    }

    /**
     * Situs asal menyimpan sebagian ulasan dengan waktu relatif ("Baru saja").
     * Format seperti itu tidak bisa di-parse, jadi jatuh ke waktu sekarang dan
     * tetap menjaga urutan tampil.
     */
    private function parseDate(?string $value): \Carbon\Carbon
    {
        $value = trim((string) $value);

        $fallback = fn () => now()->subSeconds(random_int(60, 86400));

        if ($value === '') {
            return $fallback();
        }

        // Sebagian tanggal hasil scrape berupa teks relatif ("Baru saja", "2 hari lalu").
        // Hanya bentuk absolut yang diparse supaya tidak ada percobaan parse yang gagal.
        $looksAbsolute = preg_match('/^\d{4}-\d{2}-\d{2}/', $value)
            || preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}/', $value);

        if (! $looksAbsolute) {
            return $fallback();
        }

        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Throwable) {
            return $fallback();
        }
    }
}
