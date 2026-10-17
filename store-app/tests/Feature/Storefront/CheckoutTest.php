<?php

namespace Tests\Feature\Storefront;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Setting;
use App\Models\StockItem;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStore;
use Tests\TestCase;

/**
 * Integritas checkout: harga, potongan, dan stok harus dihitung di server.
 * Ini kelas bug yang membuat situs asal bisa dimanipulasi dari browser.
 */
class CheckoutTest extends TestCase
{
    use BuildsStore;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('payment_mode', 'manual');
    }

    public function test_total_dihitung_dari_harga_di_database_bukan_dari_input_klien(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), ['price' => 25_000]);

        $this->withSession(['cart.items' => [$variant->id => 3]])
            ->post(route('checkout.store'), $this->checkoutPayload([
                // Klien mencoba menitipkan harga & total palsu.
                'unit_price' => 1,
                'total' => 1,
                'subtotal' => 1,
            ]))
            ->assertRedirect();

        $order = Order::query()->firstOrFail();

        $this->assertSame(75_000, $order->subtotal);
        $this->assertSame(75_000, $order->total);
        $this->assertSame(25_000, $order->items()->first()->unit_price);
    }

    public function test_voucher_dihitung_ulang_di_server_dan_dibatasi_maksimal_potongan(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), ['price' => 50_000]);
        $this->makeCoupon([
            'code' => 'SETENGAH',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 50,
            'max_discount' => 5_000,
        ]);

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload([
                'coupon_code' => 'SETENGAH',
                'discount' => 45_000, // diabaikan, server menghitung sendiri
            ]))
            ->assertRedirect();

        $order = Order::query()->firstOrFail();

        $this->assertSame(5_000, $order->discount);
        $this->assertSame(45_000, $order->total);
        $this->assertSame('SETENGAH', $order->coupon_code);
    }

    public function test_voucher_tidak_dikenal_ditolak(): void
    {
        $variant = $this->makeVariant($this->makeProduct());

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload(['coupon_code' => 'GAADA']))
            ->assertSessionHasErrors('coupon_code');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_order_tidak_dibuat_kalau_stok_kurang(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), [
            'delivery_type' => Variant::DELIVERY_AUTO,
        ]);
        $this->fillStock($variant, ['akun-a@contoh.test|PassA']); // cuma 1

        $this->withSession(['cart.items' => [$variant->id => 2]])
            ->post(route('checkout.store'), $this->checkoutPayload())
            ->assertSessionHasErrors('cart');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, StockItem::query()->available()->count());
    }

    public function test_stok_dikunci_saat_checkout_dan_dilepas_kalau_dibatalkan(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), [
            'delivery_type' => Variant::DELIVERY_AUTO,
        ]);
        $this->fillStock($variant, ['a@contoh.test|PassA', 'b@contoh.test|PassB']);

        $this->withSession(['cart.items' => [$variant->id => 2]])
            ->post(route('checkout.store'), $this->checkoutPayload());

        $order = Order::query()->firstOrFail();

        $this->assertSame(0, StockItem::query()->available()->count());
        $this->assertSame(2, StockItem::query()->where('status', StockItem::STATUS_RESERVED)->count());

        app(\App\Services\OrderService::class)->cancel($order, 'uji batal');

        $this->assertSame(2, StockItem::query()->available()->count());
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->order_status);
    }

    public function test_honeypot_menolak_bot(): void
    {
        $variant = $this->makeVariant($this->makeProduct());

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload(['website' => 'http://spam.example']))
            ->assertSessionHasErrors('website');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_nomor_whatsapp_tidak_valid_ditolak(): void
    {
        $variant = $this->makeVariant($this->makeProduct());

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload(['buyer_wa' => '12345']))
            ->assertSessionHasErrors('buyer_wa');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_keranjang_kosong_tidak_bisa_checkout(): void
    {
        $this->withSession(['cart.items' => []])
            ->post(route('checkout.store'), $this->checkoutPayload())
            ->assertRedirect(route('cart.index'));

        $this->assertSame(0, Order::query()->count());
    }

    public function test_data_wajib_pembeli_tidak_boleh_kosong(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), [
            'required_fields' => ['Player ID', 'Zone ID'],
        ]);

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload([
                // Zone ID sengaja dibiarkan kosong
                'required_info' => [$variant->id => ['12345678']],
            ]))
            ->assertSessionHasErrors('required_info');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_label_data_wajib_selalu_diambil_dari_server(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), [
            'required_fields' => ['Player ID', 'Zone ID'],
        ]);

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload([
                // klien menitipkan nama label sendiri — harus diabaikan
                'required_info' => [
                    $variant->id => [
                        'Login ID' => '12345678',
                        'Server' => '2201',
                    ],
                ],
            ]))
            ->assertRedirect();

        $item = Order::query()->firstOrFail()->items()->firstOrFail();

        $this->assertSame(
            ['Player ID' => '12345678', 'Zone ID' => '2201'],
            $item->required_info
        );
    }
}
