<?php

namespace App\Console\Commands;

use App\Models\Coupon;
use App\Models\Review;
use App\Models\StockItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kosongkan seluruh data transaksi supaya toko mulai dari nol.
 *
 * Yang dihapus: pesanan (termasuk yang ter-soft-delete), item pesanan, isi
 * pengiriman (kredensial terenkripsi), log aktivitas, ulasan yang menempel ke
 * pesanan, dan seluruh stok (termasuk sisa stok contoh).
 *
 * Yang DIPERTAHANKAN: katalog (produk/varian/kategori/gambar), ulasan hasil
 * scrape (tidak punya `order_id`), pengaturan toko, dan akun admin.
 *
 * Pakai `--with-reviews` untuk sekaligus membuang ulasan hasil scrape. Itu
 * penting sebelum go-live: ulasan scrape adalah testimoni pihak lain yang
 * tidak pernah benar-benar membeli di toko ini.
 *
 * Pakai `--reset-sold` untuk mengembalikan angka "terjual" ke 0. Angka itu juga
 * hasil scrape (tidak bisa diverifikasi), jadi sebelum go-live tidak boleh
 * menampilkan angka penjualan yang bukan milik toko ini.
 *
 * Dipakai sebelum go-live: `php artisan pesanan:bersihkan --force`.
 */
class PurgeOrders extends Command
{
    protected $signature = 'pesanan:bersihkan
                            {--force : Jangan minta konfirmasi (wajib untuk skrip/produksi)}
                            {--keep-stock : Pertahankan stok yang masih tersedia}
                            {--with-reviews : Hapus juga ulasan hasil scrape (tanpa order_id)}
                            {--reset-sold : Kembalikan angka terjual semua produk ke 0}';

    protected $description = 'Hapus semua pesanan, pengiriman, ulasan, log, dan stok (katalog tetap)';

    public function handle(): int
    {
        $orders = DB::table('orders')->count();
        $terhapus = DB::table('orders')->whereNotNull('deleted_at')->count();
        $items = DB::table('order_items')->count();
        $deliveries = DB::table('order_deliveries')->count();
        $reviews = Review::query()->whereNotNull('order_id')->count();
        $ulasanKatalog = Review::query()->whereNull('order_id')->count();
        $stock = StockItem::query()->count();
        $logs = DB::table('activity_logs')->count();
        $buangUlasan = (bool) $this->option('with-reviews');
        $resetTerjual = (bool) $this->option('reset-sold');
        $totalTerjual = (int) DB::table('products')->sum('sold_count');

        $this->table(['Data', 'Jumlah'], [
            ['Pesanan', $orders.' (di antaranya '.$terhapus.' di tempat sampah)'],
            ['Item pesanan', $items],
            ['Isi pengiriman', $deliveries],
            ['Ulasan pesanan (dihapus)', $reviews],
            ['Ulasan katalog', $buangUlasan ? $ulasanKatalog.' (DIHAPUS)' : $ulasanKatalog.' (dipertahankan)'],
            ['Stok', $stock],
            ['Log aktivitas', $logs],
            ['Angka terjual', $resetTerjual ? $totalTerjual.' -> 0 (DI-RESET)' : $totalTerjual.' (dipertahankan)'],
        ]);

        if (! $this->option('force') && ! $this->confirm('Hapus semua data di atas? Tindakan ini tidak bisa dibatalkan.', false)) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($buangUlasan, $resetTerjual) {
            // Urutan penting karena foreign key: anak dulu, induk belakangan.
            DB::table('order_deliveries')->delete();

            if (! $this->option('keep-stock')) {
                DB::table('stock_items')->delete();
            } else {
                DB::table('stock_items')->update([
                    'order_item_id' => null,
                    'status' => StockItem::STATUS_AVAILABLE,
                    'delivered_at' => null,
                    'updated_at' => now(),
                ]);
            }

            // Ulasan hasil scrape tidak punya order_id. Dengan --with-reviews
            // keduanya dibuang: testimoni scrape bukan pembeli toko ini.
            if ($buangUlasan) {
                Review::query()->delete();
            } else {
                Review::query()->whereNotNull('order_id')->delete();
            }

            DB::table('order_items')->delete();
            DB::table('orders')->delete();   // termasuk baris soft-deleted
            DB::table('activity_logs')->delete();

            // Kuota kupon dikembalikan karena tidak ada lagi pesanan yang memakainya.
            Coupon::query()->update(['used_count' => 0]);

            // Angka "terjual" hasil scrape tidak bisa diverifikasi dan bukan
            // penjualan toko ini. Tandai juga bukan estimasi lagi, supaya kalau
            // nanti diisi manual admin statusnya jelas.
            if ($resetTerjual) {
                DB::table('products')->update(['sold_count' => 0, 'sold_is_estimate' => false]);
            }
        });

        $pesan = 'Data transaksi dibersihkan. Katalog, pengaturan, dan akun admin tetap utuh.';

        if ($buangUlasan) {
            $pesan = 'Data transaksi + ulasan scrape dibersihkan. Katalog, pengaturan, dan akun admin tetap utuh.';
        }

        if ($resetTerjual) {
            $pesan .= ' Angka terjual semua produk di-reset ke 0.';
        }

        $this->info($pesan);

        return self::SUCCESS;
    }
}
