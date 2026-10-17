<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Setting;
use App\Models\StockItem;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsStore;
use Tests\TestCase;

/**
 * Pengiriman: varian otomatis mengambil stok yang sudah direservasi, isi
 * pengiriman disalin ke `order_deliveries` (terenkripsi), dan aksi admin
 * bersifat idempoten.
 */
class OrderFulfillmentTest extends TestCase
{
    use BuildsStore;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('payment_mode', 'manual');
    }

    private function orderOtomatis(int $qty = 2, int $stock = 2): array
    {
        $variant = $this->makeVariant($this->makeProduct(), [
            'delivery_type' => Variant::DELIVERY_AUTO,
            'price' => 20_000,
        ]);

        $payloads = [];

        for ($i = 1; $i <= $stock; $i++) {
            $payloads[] = "akun{$i}@contoh.test|Pass{$i}";
        }

        $this->fillStock($variant, $payloads);

        $this->withSession(['cart.items' => [$variant->id => $qty]])
            ->post(route('checkout.store'), $this->checkoutPayload());

        return [$variant, Order::query()->firstOrFail()];
    }

    public function test_konfirmasi_pembayaran_mengirim_stok_otomatis(): void
    {
        [$variant, $order] = $this->orderOtomatis();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.orders.paid', $order))
            ->assertRedirect();

        $order->refresh();

        $this->assertTrue($order->isPaid());
        $this->assertSame(Order::STATUS_DELIVERED, $order->order_status);
        $this->assertNotNull($order->delivered_at);

        $item = $order->items()->firstOrFail();
        $this->assertTrue($item->is_fulfilled);

        // setiap unit yang dikirim adalah kredensial yang berbeda
        $contents = $item->deliveredContents();
        $this->assertCount(2, $contents);
        $this->assertSame($contents, array_values(array_unique($contents)));
        $this->assertContains('akun1@contoh.test|Pass1', $contents);
        $this->assertContains('akun2@contoh.test|Pass2', $contents);

        // stok yang terkirim tidak bisa dikirim ulang
        $this->assertSame(0, StockItem::query()->where('status', StockItem::STATUS_AVAILABLE)->count());
        $this->assertSame(2, StockItem::query()->where('status', StockItem::STATUS_DELIVERED)->count());
        $this->assertSame(0, $variant->fresh()->availableStock());
    }

    /** Admin memberi tombol kirim isi akun langsung ke WA pembeli (draft siap tekan kirim). */
    public function test_halaman_admin_punya_tombol_kirim_isi_akun_ke_wa_pembeli(): void
    {
        [$variant, $order] = $this->orderOtomatis();
        $admin = $this->adminUser();

        // sebelum lunas: tidak ada isi akun yang terkirim → tombol tidak muncul
        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertDontSee('Kirim isi akun via WA');
        $this->assertNull($order->buyerWhatsAppLink());

        $this->actingAs($admin)->post(route('admin.orders.paid', $order))->assertRedirect();
        $order->refresh();

        // setelah terkirim: tombol muncul dan berisi draft isi akun
        $halaman = $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Kirim isi akun via WA')
            ->getContent();

        $tautan = $order->buyerWhatsAppLink();
        $this->assertNotNull($tautan);
        $this->assertStringStartsWith('https://wa.me/'.wa_normalize($order->buyer_wa).'?text=', $tautan);

        $drafIsi = rawurldecode(substr($tautan, strrpos($tautan, '=') + 1));
        $this->assertStringContainsString('Isi Pesanan Anda:', $drafIsi);
        $this->assertStringContainsString('akun1@contoh.test|Pass1', $drafIsi);
        $this->assertStringContainsString('No. Invoice: '.$order->invoice_no, $drafIsi);

        // tautan yang sama ada di halaman admin (rel, dilampirkan apa adanya)
        $this->assertStringContainsString(
            'href="'.Str::of($tautan)->replace(['&'], ['&amp;']).'"',
            $halaman,
        );
    }

    public function test_konfirmasi_pembayaran_dua_kali_tidak_menggandakan_pengiriman(): void
    {
        [$variant, $order] = $this->orderOtomatis();
        $admin = $this->adminUser();

        $this->actingAs($admin)->post(route('admin.orders.paid', $order));
        $this->actingAs($admin)->post(route('admin.orders.paid', $order->fresh()));

        $item = $order->fresh()->items()->firstOrFail();

        // 1 baris order (qty 2) dan tepat 2 catatan pengiriman — tidak digandakan
        $this->assertSame(1, $order->fresh()->items()->count());
        $this->assertSame(2, $item->qty);
        $this->assertSame(2, $item->deliveries()->count());
        $this->assertSame(2, StockItem::query()->where('status', StockItem::STATUS_DELIVERED)->count());
    }

    public function test_varian_manual_tetap_menunggu_admin_dan_isi_tercatat(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), ['price' => 15_000]);

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload());

        $order = Order::query()->firstOrFail();
        $admin = $this->adminUser();

        $this->actingAs($admin)->post(route('admin.orders.paid', $order));

        $order->refresh();
        $this->assertSame(Order::STATUS_PROCESSING, $order->order_status);
        $this->assertFalse($order->items()->first()->is_fulfilled);

        $item = $order->items()->first();

        $this->actingAs($admin)
            ->post(route('admin.orders.items.complete', [$order, $item]), [
                'content' => 'email@contoh.test|Rahasia',
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame(Order::STATUS_DELIVERED, $order->order_status);
        $this->assertSame('email@contoh.test|Rahasia', $item->fresh()->deliveredContents()[0]);
    }

    public function test_stok_kurang_saat_pengiriman_membuat_order_tertahan(): void
    {
        // Reservasi 1 unit, lalu stok dihapus paksa supaya pengiriman gagal.
        [$variant, $order] = $this->orderOtomatis(qty: 1, stock: 1);
        $admin = $this->adminUser();

        StockItem::query()->delete();

        $this->actingAs($admin)->post(route('admin.orders.paid', $order));

        $order->refresh();
        $this->assertTrue($order->isPaid());
        $this->assertSame(Order::STATUS_PROCESSING, $order->order_status);
        $this->assertFalse($order->items()->first()->is_fulfilled);
    }

    public function test_staf_tidak_bisa_membatalkan_order_sembarangan_tanpa_alasan(): void
    {
        [$variant, $order] = $this->orderOtomatis(qty: 1, stock: 1);
        $staff = $this->staffUser();

        $this->actingAs($staff)
            ->post(route('admin.orders.cancel', $order), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(Order::STATUS_NEW, $order->fresh()->order_status);
    }

    public function test_order_kedaluwarsa_ditandai_dan_stok_dikembalikan(): void
    {
        [$variant, $order] = $this->orderOtomatis(qty: 1, stock: 1);
        $admin = $this->adminUser();

        $order->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->actingAs($admin)->post(route('admin.orders.expire'))->assertRedirect();

        $order->refresh();

        $this->assertSame(Order::PAYMENT_EXPIRED, $order->payment_status);
        $this->assertSame(1, $variant->fresh()->availableStock());
        $this->assertSame(0, StockItem::query()->where('status', StockItem::STATUS_RESERVED)->count());
    }

    public function test_pembatalan_mengembalikan_stok_dan_menutup_akses_pembayaran(): void
    {
        [$variant, $order] = $this->orderOtomatis(qty: 1, stock: 1);
        $admin = $this->adminUser();

        $this->assertSame(0, $variant->fresh()->availableStock());

        $this->actingAs($admin)
            ->post(route('admin.orders.cancel', $order), ['reason' => 'Stok habis'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();

        $this->assertSame(Order::STATUS_CANCELLED, $order->order_status);
        $this->assertStringContainsString('Stok habis', (string) $order->admin_note);
        $this->assertSame(1, $variant->fresh()->availableStock(), 'stok reservasi harus kembali');
        $this->assertFalse($order->bisaDibayar());

        // Halaman pesanan pembeli: status batal, tanpa tombol bayar.
        $halaman = $this->get(route('orders.show', $order->invoice_no))->assertOk();
        $halaman->assertSee('Pesanan Dibatalkan');
        $halaman->assertDontSee(route('orders.pay', $order->invoice_no));

        // Halaman kasir: QRIS dan hitung mundur tidak boleh muncul lagi.
        $kasir = $this->get(route('orders.pay', $order->invoice_no))->assertOk();
        $kasir->assertSee('Pesanan Dibatalkan');
        $kasir->assertDontSee('Scan QRIS');
        $kasir->assertDontSee('data-countdown', false);
        $kasir->assertDontSee('data-poll-status', false);
    }

    public function test_pesanan_batal_tidak_dihitung_belum_bayar_dan_tidak_bisa_dilunasi(): void
    {
        [$variant, $order] = $this->orderOtomatis(qty: 1, stock: 1);
        $admin = $this->adminUser();

        $this->assertSame(1, Order::query()->unpaid()->count());

        $this->actingAs($admin)->post(route('admin.orders.cancel', $order), ['reason' => 'Pembeli minta batal']);

        $order->refresh();

        // Angka notifikasi & ringkasan admin tidak boleh lagi menyala.
        $this->assertSame(0, Order::query()->unpaid()->count(), 'pesanan batal bukan "belum bayar"');
        $this->assertSame(0, Order::query()->awaitingDelivery()->count());
        $this->assertSame('Dibatalkan', $order->paymentLabel(), 'label bayar mengikuti status batal');

        // Panel admin: baris pesanan tidak lagi berlabel "Menunggu pembayaran".
        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('Belum bayar: 0')
            ->assertDontSee('Menunggu pembayaran');

        // Endpoint live (bell realtime) ikut bersih.
        $this->actingAs($admin)
            ->getJson(route('admin.orders.live'))
            ->assertOk()
            ->assertJson(['belum_bayar' => 0, 'perlu_kirim' => 0]);

        // Konfirmasi pembayaran untuk pesanan batal harus ditolak.
        $this->actingAs($admin)
            ->post(route('admin.orders.paid', $order))
            ->assertSessionHasErrors('order');

        $this->assertFalse($order->fresh()->isPaid());
    }

    public function test_pesanan_lunas_tidak_bisa_dibatalkan_tanpa_refund(): void
    {
        [$variant, $order] = $this->orderOtomatis(qty: 1, stock: 1);
        $admin = $this->adminUser();

        $this->actingAs($admin)->post(route('admin.orders.paid', $order));
        $this->assertTrue($order->fresh()->isPaid());

        $this->actingAs($admin)
            ->post(route('admin.orders.cancel', $order), ['reason' => 'Coba batal'])
            ->assertSessionHasErrors('order');

        $this->assertNotSame(Order::STATUS_CANCELLED, $order->fresh()->order_status);

        // Form batal juga tidak dirender untuk pesanan lunas.
        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertDontSee(route('admin.orders.cancel', $order));
    }
}
