<?php

namespace Tests\Feature\Storefront;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStore;
use Tests\TestCase;

/**
 * Popup "Cek Transaksi" (`POST /cek-transaksi`) — meniru `openHistoryModal()`
 * situs asal, tetapi hasilnya dibatasi:
 *
 *   - pencarian HANYA lewat nomor invoice (situs asal mencari lewat nomor
 *     WhatsApp: siapa pun yang tahu nomor WA orang lain bisa membaca riwayat);
 *   - yang tampil hanya lembar pembelian — isi pesanan (akun/kredensial) tidak
 *     pernah ikut, dan invoice hasil cek TIDAK membuka akses apa pun;
 *   - email & nomor WhatsApp pembeli disamarkan (nomor WA adalah kunci untuk
 *     membuka isi pesanan);
 *   - percobaan dibatasi supaya nomor invoice tidak bisa ditebak-tebak.
 */
class CheckInvoiceTest extends TestCase
{
    use BuildsStore;
    use RefreshDatabase;

    private const KREDENSIAL = 'akun-cek@contoh.test|RahasiaCek123';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('payment_mode', 'manual');
    }

    /** Order lunas + terkirim, berisi kredensial akun yang tidak boleh bocor. */
    private function buatOrder(): Order
    {
        $variant = $this->makeVariant($this->makeProduct(), ['price' => 30_000]);

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload(['buyer_email' => 'pembeli-uji@contoh.test']));

        $order = Order::query()->firstOrFail();

        // Lembar yang diuji adalah pesanan yang sudah dibayar & terkirim.
        $order->forceFill([
            'payment_status' => Order::PAYMENT_PAID,
            'order_status' => Order::STATUS_DELIVERED,
            'paid_at' => now(),
        ])->save();

        $item = $order->items()->firstOrFail();
        $item->forceFill(['is_fulfilled' => true])->save();

        (new OrderDelivery([
            'order_item_id' => $item->id,
            'source' => OrderDelivery::SOURCE_MANUAL,
        ]))->setContentPlain(self::KREDENSIAL)->save();

        return $order->fresh(['items']);
    }

    public function test_cek_transaksi_menampilkan_lembar_pembelian(): void
    {
        $order = $this->buatOrder();
        $this->flushSession();

        $this->post(route('orders.check'), ['invoice' => $order->invoice_no])
            ->assertSessionHas('cek_hasil', $order->invoice_no);

        // Halaman berikutnya (dari mana pun popup dibuka) langsung memuat lembarnya.
        $page = $this->get(route('home'))->assertOk();

        $page->assertSee('Lembar Invoice Pembelian');
        $page->assertSee('E-Invoice Pembelian Resmi');
        $page->assertSee($order->invoice_no);
        $page->assertSee($order->buyer_name);
        $page->assertSee('Pesanan ini sudah sukses terverifikasi');
    }

    public function test_cek_transaksi_tidak_menampilkan_isi_akun(): void
    {
        $order = $this->buatOrder();
        $this->flushSession();

        $this->post(route('orders.check'), ['invoice' => $order->invoice_no]);
        $page = $this->get(route('home'))->assertOk();

        $page->assertDontSee(self::KREDENSIAL);
        $page->assertDontSee('RahasiaCek123');
    }

    public function test_cek_transaksi_tidak_membuka_akses_pesanan(): void
    {
        $order = $this->buatOrder();
        $this->flushSession();

        $this->post(route('orders.check'), ['invoice' => $order->invoice_no]);
        $this->get(route('home'))->assertOk();

        // Cek transaksi bukan cara membuka pesanan orang lain.
        $this->assertNotContains($order->invoice_no, (array) session('my_orders', []));
        $this->assertNotContains($order->invoice_no, (array) session('order_unlocked', []));

        $this->get(route('orders.show', $order->invoice_no))->assertNotFound();
        $this->get(route('orders.invoice', $order->invoice_no))->assertNotFound();
    }

    public function test_cek_transaksi_menyamarkan_kontak_pembeli(): void
    {
        $order = $this->buatOrder();
        $this->flushSession();

        $this->post(route('orders.check'), ['invoice' => $order->invoice_no]);
        $page = $this->get(route('home'))->assertOk();

        $page->assertSee(mask_email($order->buyer_email));
        $page->assertSee(mask_wa($order->buyer_wa));

        $page->assertDontSee('pembeli-uji@contoh.test');
        $page->assertDontSee('81234567890');
    }

    public function test_invoice_yang_tidak_dikenal_tidak_membuka_apa_pun(): void
    {
        $this->flushSession();

        $this->post(route('orders.check'), ['invoice' => 'INV999999TIDAKADA'])
            ->assertSessionHas('cek_error');

        $page = $this->get(route('home'))->assertOk();

        $page->assertDontSee('Lembar Invoice Pembelian');
        $page->assertSee('Invoice tidak ditemukan');
    }

    public function test_nomor_invoice_wajib_diisi(): void
    {
        $this->post(route('orders.check'), ['invoice' => ''])
            ->assertSessionHasErrors('invoice');
    }

    public function test_percobaan_cek_transaksi_dibatasi(): void
    {
        $order = $this->buatOrder();
        $this->flushSession();

        for ($i = 0; $i < 8; $i++) {
            $this->post(route('orders.check'), ['invoice' => 'INVTIDAKADA'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)]);
        }

        // Percobaan ke-9 ditolak walau nomor invoice-nya benar.
        $this->post(route('orders.check'), ['invoice' => $order->invoice_no])
            ->assertSessionHas('cek_error');

        $this->get(route('home'))->assertOk()->assertDontSee('Lembar Invoice Pembelian');
    }

    public function test_popup_cek_transaksi_tersedia_di_storefront(): void
    {
        $page = $this->get(route('home'))->assertOk();

        $page->assertSee('id="cek-modal"', false);
        $page->assertSee('data-modal-open="cek-modal"', false);
        $page->assertSee('action="'.route('orders.check').'"', false);
        $page->assertSee('Masukkan nomor invoice', false);
    }

    public function test_popup_menampilkan_pesanan_di_perangkat_ini(): void
    {
        $order = $this->buatOrder();

        $page = $this->get(route('home'))->assertOk();

        $page->assertSee('data-cek-card', false);
        $page->assertSee($order->invoice_no);
        $page->assertSee('data-modal-open="cek-modal"', false);

        // Klik kartu / "Lihat Invoice" HANYA membuka lembar pembelian lapis dua
        // di popup, tidak mengarahkan ke halaman pesanan. Tautan tetap ada
        // sebagai fallback tanpa JS.
        $page->assertSee('href="'.route('orders.show', $order->invoice_no).'" data-modal-open="invoice-preview-modal-0"', false);
        $page->assertSee('href="'.route('orders.invoice', $order->invoice_no).'" data-modal-open="invoice-preview-modal-0"', false);

        // Lembar per kartu ikut terdirender — isinya tanpa kredensial akun.
        $html = $page->getContent();

        $this->assertStringContainsString('Lembar Invoice Pembelian', $html);
        $this->assertStringNotContainsString(self::KREDENSIAL, $html);
    }
}
