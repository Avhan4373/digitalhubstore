<?php

namespace Tests\Feature\Storefront;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStore;
use Tests\TestCase;

/**
 * Pesanan bersifat pribadi: nomor invoice saja tidak cukup untuk membukanya.
 * Situs asal membocorkan seluruh daftar pesanan lewat `GET /orders` tanpa login.
 *
 * Dua tingkat akses yang diuji di sini:
 *   1. `my_orders`      -> boleh melihat STATUS pesanan (nama, status, total).
 *   2. `order_unlocked` -> boleh melihat ISI pesanan (kredensial akun).
 * Dipisah karena browser bisa dipakai bergantian: di komputer warnet,
 * halaman status orang lain masih bisa terbuka, tapi akunnya tidak boleh.
 */
class OrderAccessTest extends TestCase
{
    use BuildsStore;
    use RefreshDatabase;

    private const KREDENSIAL = 'akun-rahasia@contoh.test|Password123';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('payment_mode', 'manual');
    }

    private function buatOrder(): Order
    {
        $variant = $this->makeVariant($this->makeProduct(), ['price' => 30_000]);

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload());

        return Order::query()->firstOrFail();
    }

    public function test_tamu_tidak_bisa_membuka_pesanan_orang_lain(): void
    {
        $order = $this->buatOrder();

        $this->flushSession();
        $this->get(route('orders.show', $order->invoice_no))->assertNotFound();
        $this->get(route('orders.pay', $order->invoice_no))->assertNotFound();
        $this->get(route('orders.invoice', $order->invoice_no))->assertNotFound();
        $this->get(route('orders.status', $order->invoice_no))->assertNotFound();
    }

    public function test_pembeli_bisa_membuka_pesanan_sendiri(): void
    {
        $order = $this->buatOrder();

        $this->get(route('orders.show', $order->invoice_no))->assertOk();
        $this->get(route('orders.pay', $order->invoice_no))->assertOk();
        $this->get(route('orders.invoice', $order->invoice_no))->assertOk();
    }

    public function test_status_json_tidak_membocorkan_data_pembeli(): void
    {
        $order = $this->buatOrder();

        $response = $this->getJson(route('orders.status', $order->invoice_no))->assertOk();

        $response->assertJsonStructure(['invoice', 'payment_status', 'order_status', 'is_paid', 'punya_isi', 'terbuka']);
        $response->assertJsonMissing(['buyer_wa', 'buyer_name', 'buyer_email']);
    }

    public function test_status_json_mengikuti_isi_dan_kunci_pesanan(): void
    {
        $order = $this->orderTerkirim();
        $this->sesiPemakaiLain($order);

        $this->getJson(route('orders.status', $order->invoice_no))
            ->assertOk()
            ->assertJsonPath('punya_isi', true)
            ->assertJsonPath('terbuka', false);

        $this->post(route('orders.unlock', $order->invoice_no), ['whatsapp' => '081234567890'])
            ->assertSessionHas('success');

        $this->getJson(route('orders.status', $order->invoice_no))
            ->assertOk()
            ->assertJsonPath('terbuka', true);
    }

    public function test_halaman_pesanan_membawa_kunci_live_dan_tautan_polling(): void
    {
        $order = $this->buatOrder();

        $page = $this->get(route('orders.show', $order->invoice_no))->assertOk();

        $rute = route('orders.status', $order->invoice_no);
        $page->assertSee('data-order-live="'.$rute.'"', false);
        $page->assertSee('data-live-key="'.$order->payment_status.'|'.$order->order_status.'|0|0"', false);
    }

    public function test_lacak_butuh_invoice_dan_whatsapp_yang_cocok(): void
    {
        $order = $this->buatOrder();

        $this->flushSession();

        $this->post(route('orders.track'), [
            'invoice' => $order->invoice_no,
            'whatsapp' => '089999999999', // nomor berbeda
        ])->assertSessionHas('error');

        // invoice yang tidak cocok tidak boleh ikut tersimpan di session
        $this->assertNotContains($order->invoice_no, (array) session('my_orders', []));

        $this->post(route('orders.track'), [
            'invoice' => $order->invoice_no,
            'whatsapp' => '081234567890',
        ])->assertRedirect(route('orders.show', $order->invoice_no));
    }

    public function test_lacak_hanya_membuka_pesanan_yang_cocok(): void
    {
        $order = $this->buatOrder();
        $this->flushSession();

        $lain = Order::create([
            'invoice_no' => 'INVLain00000001',
            'buyer_name' => 'Orang Lain',
            'buyer_wa' => '6281111111111',
            'subtotal' => 1_000,
            'discount' => 0,
            'total' => 1_000,
            'payment_method' => Order::METHOD_QRIS,
            'payment_status' => Order::PAYMENT_PENDING,
            'order_status' => Order::STATUS_NEW,
        ]);

        $this->post(route('orders.track'), [
            'invoice' => $order->invoice_no,
            'whatsapp' => '081234567890',
        ]);

        $this->get(route('orders.show', $order->invoice_no))->assertOk();
        $this->get(route('orders.show', $lain->invoice_no))->assertNotFound();
    }

    // ------------------------------------------------- isi pesanan (akun)

    /** Order dengan satu item yang sudah terkirim berisi kredensial akun. */
    private function orderTerkirim(): Order
    {
        $order = $this->buatOrder();
        $item = $order->items()->firstOrFail();
        $item->forceFill(['is_fulfilled' => true])->save();

        (new OrderDelivery([
            'order_item_id' => $item->id,
            'source' => OrderDelivery::SOURCE_MANUAL,
        ]))->setContentPlain(self::KREDENSIAL)->save();

        return $order->fresh(['items.deliveries']);
    }

    /**
     * Kasus browser bergantian: invoice sudah ada di session (halaman status
     * boleh dibuka) tapi isinya belum pernah dibuka.
     */
    private function sesiPemakaiLain(Order $order): void
    {
        $this->flushSession();
        $this->withSession(['my_orders' => [$order->invoice_no]]);
    }

    public function test_isi_pesanan_tidak_ikut_terkirim_sebelum_diverifikasi(): void
    {
        $order = $this->orderTerkirim();
        $this->sesiPemakaiLain($order);

        $page = $this->get(route('orders.show', $order->invoice_no))->assertOk();

        // Statusnya tetap boleh dilihat...
        $page->assertSee($order->invoice_no);
        $page->assertSee('Terkirim');
        $page->assertSee('Isi Pesanan Terkunci');

        // ...tapi kredensial akun TIDAK boleh ada di HTML.
        $page->assertDontSee(self::KREDENSIAL);
        $page->assertDontSee('Password123');
        $page->assertDontSee('Isi Pesanan Anda');
    }

    public function test_nomor_whatsapp_pembeli_ditampilkan_tersamar(): void
    {
        $order = $this->orderTerkirim();
        $this->sesiPemakaiLain($order);

        $page = $this->get(route('orders.show', $order->invoice_no))->assertOk();

        // Nomor WA adalah kunci membuka isi pesanan, jadi tidak boleh tampil utuh.
        $page->assertSee(mask_wa($order->buyer_wa));
        $page->assertDontSee('81234567890');

        $invoice = $this->get(route('orders.invoice', $order->invoice_no))->assertOk();
        $invoice->assertSee(mask_wa($order->buyer_wa));
        $invoice->assertDontSee('81234567890');
    }

    public function test_buka_isi_pesanan_butuh_nomor_whatsapp_yang_cocok(): void
    {
        $order = $this->orderTerkirim();
        $this->sesiPemakaiLain($order);

        $this->post(route('orders.unlock', $order->invoice_no), ['whatsapp' => '089999999999'])
            ->assertSessionHas('error');

        $this->get(route('orders.show', $order->invoice_no))
            ->assertOk()
            ->assertDontSee(self::KREDENSIAL);

        $this->post(route('orders.unlock', $order->invoice_no), ['whatsapp' => '081234567890'])
            ->assertSessionHas('success');

        $this->get(route('orders.show', $order->invoice_no))
            ->assertOk()
            ->assertSee(self::KREDENSIAL)
            ->assertSee('Isi Pesanan Anda');
    }

    public function test_lacak_yang_cocok_sekaligus_membuka_isi_pesanan(): void
    {
        $order = $this->orderTerkirim();
        $this->flushSession();

        $this->post(route('orders.track'), [
            'invoice' => $order->invoice_no,
            'whatsapp' => '081234567890',
        ])->assertRedirect(route('orders.show', $order->invoice_no));

        $this->get(route('orders.show', $order->invoice_no))
            ->assertOk()
            ->assertSee(self::KREDENSIAL);
    }

    public function test_percobaan_buka_isi_pesanan_dibatasi(): void
    {
        $order = $this->orderTerkirim();
        $this->sesiPemakaiLain($order);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('orders.unlock', $order->invoice_no), ['whatsapp' => '089999999999']);
        }

        // Percobaan ke-6 ditolak walau nomornya benar.
        $this->post(route('orders.unlock', $order->invoice_no), ['whatsapp' => '081234567890'])
            ->assertSessionHas('error');

        $this->get(route('orders.show', $order->invoice_no))
            ->assertOk()
            ->assertDontSee(self::KREDENSIAL);
    }

    public function test_isi_pesanan_tidak_bisa_dibuka_tanpa_invoice_di_session(): void
    {
        $order = $this->orderTerkirim();
        $this->flushSession();

        $this->post(route('orders.unlock', $order->invoice_no), ['whatsapp' => '081234567890'])
            ->assertNotFound();
    }

    /** Checkout hanya mencatat invoice untuk halaman status; isi akun tetap butuh verifikasi. */
    public function test_checkout_tidak_sekaligus_membuka_isi_pesanan(): void
    {
        $order = $this->buatOrder();

        // invoice tersimpan untuk halaman status...
        $this->assertContains($order->invoice_no, (array) session('my_orders', []));
        // ...tapi isi akun TIDAK otomatis terbuka di browser yang memesan.
        $this->assertFalse($this->app['App\Services\OrderAccess']->isUnlocked($order->invoice_no));
    }

    /** Bekas pemakai browser (warnet) tidak melihat isi akun yang sudah kedaluwarsa. */
    public function test_isi_pesanan_terkunci_ulang_setelah_tenggat(): void
    {
        $order = $this->orderTerkirim();
        $this->sesiPemakaiLain($order);

        $this->post(route('orders.unlock', $order->invoice_no), ['whatsapp' => '081234567890'])
            ->assertSessionHas('success');

        // masih di dalam masa buka → terlihat
        $this->travel(5)->minutes();
        $this->get(route('orders.show', $order->invoice_no))
            ->assertOk()
            ->assertSee(self::KREDENSIAL);

        // lewat tenggat 10 menit → terkunci ulang, harus verifikasi lagi
        $this->travel(6)->minutes();
        $this->get(route('orders.show', $order->invoice_no))
            ->assertOk()
            ->assertSee('Isi Pesanan Terkunci')
            ->assertDontSee(self::KREDENSIAL);
    }
}
