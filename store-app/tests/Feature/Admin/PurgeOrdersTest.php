<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Review;
use App\Models\Setting;
use App\Models\StockItem;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsStore;
use Tests\TestCase;

/**
 * `pesanan:bersihkan` dipakai sebelum go-live: membuang seluruh data transaksi
 * tanpa menyentuh katalog, ulasan hasil scrape, pengaturan, dan akun admin.
 */
class PurgeOrdersTest extends TestCase
{
    use BuildsStore;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('payment_mode', 'manual');
    }

    public function test_menghapus_pesanan_dan_ulasan_pesanan_tanpa_menyentuh_katalog(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), [
            'delivery_type' => Variant::DELIVERY_AUTO,
            'price' => 20_000,
        ]);
        $this->fillStock($variant, ['akun1@contoh.test|Pass1']);

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload());

        $order = Order::query()->firstOrFail();

        // Ulasan yang menempel ke pesanan + satu ulasan katalog (tanpa order_id).
        Review::query()->create([
            'product_id' => $variant->product_id,
            'order_id' => $order->id,
            'user_name' => 'Pembeli',
            'rating' => 5,
            'comment' => 'Mantap',
            'status' => Review::STATUS_APPROVED,
        ]);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, DB::table('order_items')->count());
        $this->assertSame(1, StockItem::query()->count());

        $produk = DB::table('products')->count();
        $varian = DB::table('variants')->count();
        $ulasanKatalog = Review::query()->whereNull('order_id')->count();
        $admin = DB::table('users')->count();

        $this->artisan('pesanan:bersihkan', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Order::query()->withTrashed()->count(), 'order di tempat sampah ikut dibersihkan');
        $this->assertSame(0, DB::table('order_items')->count());
        $this->assertSame(0, DB::table('order_deliveries')->count());
        $this->assertSame(0, StockItem::query()->count());
        $this->assertSame(0, DB::table('activity_logs')->count());
        $this->assertSame(0, Review::query()->whereNotNull('order_id')->count());

        // Katalog, ulasan scrape, dan akun admin tetap utuh.
        $this->assertSame($produk, DB::table('products')->count());
        $this->assertSame($varian, DB::table('variants')->count());
        $this->assertSame($ulasanKatalog, Review::query()->whereNull('order_id')->count());
        $this->assertSame($admin, DB::table('users')->count());
    }

    public function test_keep_stock_menyisakan_stok_tersedia(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), [
            'delivery_type' => Variant::DELIVERY_AUTO,
            'price' => 20_000,
        ]);
        $this->fillStock($variant, ['akun1@contoh.test|Pass1', 'akun2@contoh.test|Pass2']);

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload());

        $this->assertSame(1, StockItem::query()->where('status', StockItem::STATUS_RESERVED)->count());

        $this->artisan('pesanan:bersihkan', ['--force' => true, '--keep-stock' => true])->assertSuccessful();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, StockItem::query()->where('status', StockItem::STATUS_RESERVED)->count());
        $this->assertSame(2, $variant->fresh()->availableStock(), 'stok kembali tersedia, bukan dihapus');
    }

    public function test_with_reviews_menghapus_ulasan_hasil_scrape(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), [
            'delivery_type' => Variant::DELIVERY_AUTO,
            'price' => 20_000,
        ]);
        $this->fillStock($variant, ['akun1@contoh.test|Pass1']);

        // Dua ulasan scrape (tanpa order_id) + satu ulasan pesanan.
        foreach (['Pembeli Scrape A', 'Pembeli Scrape B'] as $nama) {
            Review::query()->create([
                'product_id' => $variant->product_id,
                'external_id' => 'scrape-'.$nama,
                'user_name' => $nama,
                'rating' => 5,
                'comment' => 'Ulasan hasil scrape',
                'status' => Review::STATUS_APPROVED,
            ]);
        }

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload());

        $order = Order::query()->firstOrFail();

        Review::query()->create([
            'product_id' => $variant->product_id,
            'order_id' => $order->id,
            'user_name' => 'Pembeli Asli',
            'rating' => 4,
            'comment' => 'Ulasan pesanan',
            'status' => Review::STATUS_APPROVED,
        ]);

        $this->assertSame(3, Review::query()->count());

        $this->artisan('pesanan:bersihkan', ['--force' => true, '--with-reviews' => true])
            ->assertSuccessful();

        $this->assertSame(0, Review::query()->count(), 'ulasan scrape dan ulasan pesanan sama-sama dibuang');
        $this->assertSame(0, Order::query()->count());

        // Katalog tetap utuh.
        $this->assertSame(1, DB::table('products')->count());
        $this->assertSame(1, DB::table('variants')->count());
    }

    public function test_tanpa_force_tidak_menghapus_apa_pun(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), [
            'delivery_type' => Variant::DELIVERY_AUTO,
            'price' => 20_000,
        ]);
        $this->fillStock($variant, ['akun1@contoh.test|Pass1']);

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload());

        $this->artisan('pesanan:bersihkan')
            ->expectsConfirmation('Hapus semua data di atas? Tindakan ini tidak bisa dibatalkan.', 'no')
            ->assertFailed();

        $this->assertSame(1, Order::query()->count());
    }
}
