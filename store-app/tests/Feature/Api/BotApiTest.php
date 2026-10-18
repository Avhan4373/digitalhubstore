<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockItem;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API internal bot WhatsApp.
 *
 * Yang diuji di sini adalah kontrak dengan proses bot: bentuk respons,
 * perhitungan harga di server, dan yang paling penting — isi akun TIDAK
 * boleh keluar sebelum pesanan lunas dan nomor WA cocok.
 */
class BotApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'token-uji-bot';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bot.enabled' => true,
            'bot.token' => $this->token,
        ]);
    }

    private function sebagaiBot(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function buatVarian(array $atribut = [], array $produkAtribut = []): Variant
    {
        $kategori = Category::firstOrCreate(
            ['slug' => 'ai'],
            ['name' => 'AI', 'is_active' => true, 'sort_order' => 1],
        );

        $slug = $produkAtribut['slug'] ?? 'gemini-pro';

        $produk = Product::firstOrCreate(
            ['slug' => $slug],
            array_merge([
                'category_id' => $kategori->id,
                'name' => 'Gemini Pro',
                'slug' => $slug,
                'description' => 'Akses Gemini Pro penuh',
                'warranty' => 'Garansi Login',
                'is_active' => true,
            ], $produkAtribut),
        );

        return Variant::create(array_merge([
            'product_id' => $produk->id,
            'name' => 'Sharing 1 Bulan',
            'price' => 5000,
            'is_active' => true,
            'delivery_type' => Variant::DELIVERY_AUTO,
        ], $atribut));
    }

    /** Tambah stok tersedia sebanyak $jumlah. */
    private function isiStok(Variant $varian, int $jumlah = 1): void
    {
        foreach (range(1, $jumlah) as $i) {
            $item = new StockItem(['variant_id' => $varian->id, 'status' => StockItem::STATUS_AVAILABLE]);
            $item->setPayloadPlain("akun{$i}@contoh.test:rahasia{$i}");
            $item->save();
        }
    }

    // ------------------------------------------------------------------ auth

    public function test_tanpa_token_ditolak(): void
    {
        $this->getJson('/api/bot/katalog')->assertStatus(401);
    }

    public function test_token_salah_ditolak(): void
    {
        $this->withHeader('Authorization', 'Bearer salah')
            ->getJson('/api/bot/katalog')
            ->assertStatus(401);
    }

    public function test_respons_tidak_di_cache(): void
    {
        $this->buatVarian();

        $res = $this->sebagaiBot()->getJson('/api/bot/katalog')->assertOk();

        // Respons bisa memuat kredensial; jangan tersimpan di cache proxy.
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_bot_nonaktif_menolak_semua_permintaan(): void
    {
        config(['bot.enabled' => false]);

        $this->sebagaiBot()->getJson('/api/bot/katalog')->assertStatus(503);
    }

    public function test_token_kosong_menolak_semua_permintaan(): void
    {
        // Salah konfigurasi tidak boleh membuka endpoint ke publik.
        config(['bot.token' => '']);

        $this->withHeader('Authorization', 'Bearer ')
            ->getJson('/api/bot/katalog')
            ->assertStatus(503);
    }

    // --------------------------------------------------------------- katalog

    public function test_katalog_mengembalikan_produk_dan_harga(): void
    {
        $this->buatVarian(['price' => 5000], ['name' => 'Gemini Pro', 'slug' => 'gemini-pro']);
        $this->buatVarian(['name' => 'Private 1 Tahun', 'price' => 35000], ['name' => 'Gemini Pro', 'slug' => 'gemini-pro']);

        $res = $this->sebagaiBot()->getJson('/api/bot/katalog')->assertOk();

        $res->assertJsonPath('store.name', config('app.name'));
        $res->assertJsonPath('categories.0.name', 'AI');
        $res->assertJsonPath('categories.0.products.0.name', 'Gemini Pro');
        $res->assertJsonPath('categories.0.products.0.price_min', 5000);
        $res->assertJsonPath('categories.0.products.0.price_max', 35000);
    }

    public function test_katalog_menyertakan_nomor_whatsapp_toko(): void
    {
        $this->buatVarian();
        Setting::put('store_whatsapp', '6282395124106');

        $res = $this->sebagaiBot()->getJson('/api/bot/katalog')->assertOk();

        $this->assertSame('6282395124106', $res->json('store.whatsapp'));
    }

    public function test_katalog_menyertakan_nomor_owner_dan_qris_untuk_perintah_pay(): void
    {
        $this->buatVarian();
        Setting::put('owner_numbers', "082243890517\n08214369690");
        Setting::put('qris_image', 'settings/qris.jpg');
        Setting::put('qris_holder', 'Creative Solutions');

        $res = $this->sebagaiBot()->getJson('/api/bot/katalog')->assertOk();

        $this->assertSame(['6282243890517', '628214369690'], $res->json('store.owner_numbers'));
        $this->assertStringContainsString('settings/qris.jpg', (string) $res->json('store.qris_url'));
        $this->assertSame('Creative Solutions', $res->json('store.qris_holder'));
    }

    public function test_katalog_tidak_memuat_produk_tanpa_varian_aktif(): void
    {
        $this->buatVarian(['is_active' => false]);

        $res = $this->sebagaiBot()->getJson('/api/bot/katalog')->assertOk();

        $this->assertSame([], $res->json('categories.0.products'));
    }

    public function test_katalog_tidak_menyertakan_data_internal(): void
    {
        $this->buatVarian(['cost_price' => 2000, 'supplier_name' => 'Supplier Rahasia']);

        $res = $this->sebagaiBot()->getJson('/api/bot/katalog')->assertOk();

        $json = $res->json();
        $isi = json_encode($json);

        // Margin & supplier tidak boleh ikut ke proses bot.
        $this->assertStringNotContainsString('Supplier Rahasia', $isi);
        $this->assertStringNotContainsString('cost_price', $isi);
        $this->assertArrayNotHasKey('cost_price', $json['categories'][0]['products'][0]);
    }

    public function test_detail_produk_tidak_membocorkan_harga_modal(): void
    {
        $varian = $this->buatVarian(['cost_price' => 2000, 'supplier_name' => 'Supplier Rahasia']);
        $this->isiStok($varian);

        $isi = $this->sebagaiBot()->getJson('/api/bot/produk/gemini-pro')->assertOk()->getContent();

        $this->assertStringNotContainsString('Supplier Rahasia', $isi);
        $this->assertStringNotContainsString('cost_price', $isi);
    }

    public function test_detail_produk_memuat_varian_dan_stok(): void
    {
        $varian = $this->buatVarian(['price' => 5000]);
        $this->isiStok($varian);

        $res = $this->sebagaiBot()->getJson('/api/bot/produk/gemini-pro')->assertOk();

        $res->assertJsonPath('product.name', 'Gemini Pro');
        $res->assertJsonPath('product.warranty', 'Garansi Login');
        $res->assertJsonPath('variants.0.name', 'Sharing 1 Bulan');
        $res->assertJsonPath('variants.0.price', 5000);
        $res->assertJsonPath('variants.0.stock', 1);
        $res->assertJsonPath('variants.0.purchasable', true);
    }

    public function test_detail_produk_nonaktif_404(): void
    {
        $this->buatVarian([], ['is_active' => false]);

        $this->sebagaiBot()->getJson('/api/bot/produk/gemini-pro')->assertStatus(404);
    }

    // ---------------------------------------------------------------- pesanan

    public function test_pesanan_dibuat_dengan_harga_dari_server(): void
    {
        $varian = $this->buatVarian(['price' => 5000]);
        $this->isiStok($varian, 5);

        $res = $this->sebagaiBot()->postJson('/api/bot/pesanan', [
            'name' => 'Budi',
            'whatsapp' => '628111111111',
            'variant_id' => $varian->id,
            'qty' => 2,
            // Harga kiriman klien harus DIABAIKAN.
            'price' => 1,
            'total' => 1,
        ])->assertStatus(201);

        // 2 x 5000 — bukan 1 dari klien.
        $this->assertSame(10000, $res->json('order.total'));

        $order = Order::firstWhere('invoice_no', $res->json('order.invoice'));
        $this->assertSame(10000, (int) $order->total);
        $this->assertSame('whatsapp-bot', $order->user_agent);
    }

    public function test_pesanan_memotong_stok(): void
    {
        $varian = $this->buatVarian();
        $this->isiStok($varian, 2);

        $this->sebagaiBot()->postJson('/api/bot/pesanan', [
            'name' => 'Budi',
            'whatsapp' => '628111111111',
            'variant_id' => $varian->id,
            'qty' => 1,
        ])->assertStatus(201);

        $this->assertSame(1, StockItem::where('status', StockItem::STATUS_AVAILABLE)->count());
    }

    public function test_pesanan_ditolak_kalau_stok_habis(): void
    {
        $varian = $this->buatVarian();

        $this->sebagaiBot()->postJson('/api/bot/pesanan', [
            'name' => 'Budi',
            'whatsapp' => '628111111111',
            'variant_id' => $varian->id,
            'qty' => 1,
        ])->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_qty_dibatasi_maksimal_keranjang(): void
    {
        $varian = $this->buatVarian();
        $this->isiStok($varian, 30);

        $this->sebagaiBot()->postJson('/api/bot/pesanan', [
            'name' => 'Budi',
            'whatsapp' => '628111111111',
            'variant_id' => $varian->id,
            'qty' => 999,
        ])->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_pesanan_menolak_nomor_tidak_valid(): void
    {
        $varian = $this->buatVarian();
        $this->isiStok($varian);

        foreach (['abc', '123', '628', ''] as $nomor) {
            $this->sebagaiBot()->postJson('/api/bot/pesanan', [
                'name' => 'Budi',
                'whatsapp' => $nomor,
                'variant_id' => $varian->id,
            ])->assertStatus(422);
        }

        $this->assertSame(0, Order::count());
    }

    public function test_pesanan_wajib_nama_dan_nomor(): void
    {
        $varian = $this->buatVarian();

        $this->sebagaiBot()->postJson('/api/bot/pesanan', [
            'variant_id' => $varian->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'whatsapp']);
    }

    // ----------------------------------------------------------------- status

    public function test_status_menolak_nomor_yang_tidak_cocok(): void
    {
        $varian = $this->buatVarian();
        $invoice = $this->buatPesanan($varian, '628111111111');

        $this->sebagaiBot()->postJson('/api/bot/pesanan/status', [
            'invoice' => $invoice,
            'whatsapp' => '628999999999',
        ])->assertStatus(403);
    }

    public function test_status_tidak_membocorkan_isi_akun_sebelum_lunas(): void
    {
        $varian = $this->buatVarian();
        $invoice = $this->buatPesanan($varian, '628111111111', lunas: false);

        $res = $this->sebagaiBot()->postJson('/api/bot/pesanan/status', [
            'invoice' => $invoice,
            'whatsapp' => '628111111111',
        ])->assertOk();

        $this->assertSame([], $res->json('contents'));
        $this->assertFalse($res->json('order.is_paid'));
    }

    public function test_status_mengirim_isi_akun_setelah_lunas(): void
    {
        $varian = $this->buatVarian();
        $invoice = $this->buatPesanan($varian, '628111111111', lunas: true);

        $res = $this->sebagaiBot()->postJson('/api/bot/pesanan/status', [
            'invoice' => $invoice,
            'whatsapp' => '628111111111',
        ])->assertOk();

        $this->assertTrue($res->json('order.is_paid'));
        $this->assertNotEmpty($res->json('contents'));
        $this->assertSame('akun@contoh.test', $res->json('contents.0.contents.0'));
    }

    public function test_status_menyertakan_qris_selama_masih_bisa_dibayar(): void
    {
        Setting::put('qris_image', 'settings/qris.jpg');

        $varian = $this->buatVarian();
        $invoice = $this->buatPesanan($varian, '628111111111');

        $res = $this->sebagaiBot()->postJson('/api/bot/pesanan/status', [
            'invoice' => $invoice,
            'whatsapp' => '628111111111',
        ])->assertOk();

        // Pembeli sering kehilangan gambar QRIS; kirim ulang alamatnya.
        $this->assertStringContainsString('qris.jpg', (string) $res->json('payment.qris_url'));
    }

    public function test_status_tidak_menyertakan_qris_setelah_lunas(): void
    {
        Setting::put('qris_image', 'settings/qris.jpg');

        $varian = $this->buatVarian();
        $invoice = $this->buatPesanan($varian, '628111111111', lunas: true);

        $res = $this->sebagaiBot()->postJson('/api/bot/pesanan/status', [
            'invoice' => $invoice,
            'whatsapp' => '628111111111',
        ])->assertOk();

        $this->assertNull($res->json('payment'));
    }

    public function test_status_tidak_menyertakan_qris_untuk_pesanan_batal(): void
    {
        Setting::put('qris_image', 'settings/qris.jpg');

        $varian = $this->buatVarian();
        $invoice = $this->buatPesanan($varian, '628111111111');

        Order::where('invoice_no', $invoice)->update(['order_status' => Order::STATUS_CANCELLED]);

        $res = $this->sebagaiBot()->postJson('/api/bot/pesanan/status', [
            'invoice' => $invoice,
            'whatsapp' => '628111111111',
        ])->assertOk();

        $this->assertNull($res->json('payment'), 'pesanan batal tidak boleh menampilkan QRIS');
    }

    public function test_status_invoice_tidak_ada_404(): void
    {
        $this->sebagaiBot()->postJson('/api/bot/pesanan/status', [
            'invoice' => 'INV000000XXXXX',
            'whatsapp' => '628111111111',
        ])->assertStatus(404);
    }

    public function test_riwayat_hanya_pesanan_milik_nomor_itu(): void
    {
        $varian = $this->buatVarian();
        $this->buatPesanan($varian, '628111111111');
        $this->buatPesanan($varian, '628222222222');

        $res = $this->sebagaiBot()->postJson('/api/bot/pesanan/riwayat', [
            'whatsapp' => '628111111111',
        ])->assertOk();

        $this->assertCount(1, $res->json('orders'));
    }

    // ------------------------------------------------------------------ util

    private function buatPesanan(Variant $varian, string $wa, bool $lunas = false): string
    {
        $order = Order::create([
            'invoice_no' => Order::newInvoiceNumber(),
            'buyer_name' => 'Budi',
            'buyer_wa' => $wa,
            'subtotal' => $varian->price,
            'discount' => 0,
            'total' => $varian->price,
            'payment_method' => Order::METHOD_QRIS,
            'payment_status' => $lunas ? Order::PAYMENT_PAID : Order::PAYMENT_PENDING,
            'order_status' => $lunas ? Order::STATUS_DELIVERED : Order::STATUS_NEW,
            'paid_at' => $lunas ? now() : null,
            'expires_at' => now()->addHour(),
        ]);

        $item = $order->items()->create([
            'product_id' => $varian->product_id,
            'variant_id' => $varian->id,
            'product_name' => 'Gemini Pro',
            'variant_name' => $varian->name,
            'unit_price' => $varian->price,
            'qty' => 1,
            'subtotal' => $varian->price,
            'is_fulfilled' => $lunas,
        ]);

        if ($lunas) {
            $delivery = new OrderDelivery([
                'order_item_id' => $item->id,
                'source' => OrderDelivery::SOURCE_MANUAL,
                'delivered_at' => now(),
            ]);
            $delivery->setContentPlain('akun@contoh.test');
            $delivery->save();
        }

        return $order->invoice_no;
    }
}
