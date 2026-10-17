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
 * Dipakai sebelum go-live: `php artisan pesanan:bersihkan --force`.
 */
class PurgeOrders extends Command
{
    protected $signature = 'pesanan:bersihkan
                            {--force : Jangan minta konfirmasi (wajib untuk skrip/produksi)}
                            {--keep-stock : Pertahankan stok yang masih tersedia}';

    protected $description = 'Hapus semua pesanan, pengiriman, ulasan pesanan, log, dan stok (katalog tetap)';

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

        $this->table(['Data', 'Jumlah'], [
            ['Pesanan', $orders.' (di antaranya '.$terhapus.' di tempat sampah)'],
            ['Item pesanan', $items],
            ['Isi pengiriman', $deliveries],
            ['Ulasan pesanan (dihapus)', $reviews],
            ['Ulasan katalog (dipertahankan)', $ulasanKatalog],
            ['Stok', $stock],
            ['Log aktivitas', $logs],
        ]);

        if (! $this->option('force') && ! $this->confirm('Hapus semua data di atas? Tindakan ini tidak bisa dibatalkan.', false)) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        DB::transaction(function () {
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

            // Ulasan hasil scrape tidak punya order_id, jadi aman: hanya ulasan
            // yang benar-benar menempel ke pesanan yang ikut dihapus.
            Review::query()->whereNotNull('order_id')->delete();

            DB::table('order_items')->delete();
            DB::table('orders')->delete();   // termasuk baris soft-deleted
            DB::table('activity_logs')->delete();

            // Kuota kupon dikembalikan karena tidak ada lagi pesanan yang memakainya.
            Coupon::query()->update(['used_count' => 0]);
        });

        $this->info('Data transaksi dibersihkan. Katalog, ulasan, pengaturan, dan akun admin tetap utuh.');

        return self::SUCCESS;
    }
}
