<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Setting;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStore;
use Tests\TestCase;

/**
 * Alur reseller manual (mis. beli di itemku lalu teruskan ke pembeli):
 * referensi produk supplier + harga modal disimpan di varian, disalin ke
 * `order_items` saat dibeli, dan marginnya terlihat oleh admin.
 */
class SupplierPurchaseTest extends TestCase
{
    use BuildsStore;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('payment_mode', 'manual');
    }

    public function test_admin_menyimpan_referensi_supplier_dan_data_wajib(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), ['price' => 50_000]);

        $this->actingAs($this->adminUser())
            ->put(route('admin.variants.update', $variant), [
                'name' => $variant->name,
                'price' => 50_000,
                'delivery_type' => Variant::DELIVERY_MANUAL,
                'supplier_name' => 'itemku',
                'supplier_sku' => 'MLBB-86',
                'supplier_url' => 'https://www.itemku.com/id/diamond-mlbb',
                'cost_price' => 40_000,
                // baris kosong & duplikat harus dibuang
                'required_fields' => "Player ID\nZone ID\n\nPlayer ID",
                'is_active' => '1',
            ])
            ->assertRedirect();

        $variant->refresh();

        $this->assertSame('itemku', $variant->supplier_name);
        $this->assertSame('MLBB-86', $variant->supplier_sku);
        $this->assertSame(40_000, $variant->cost_price);
        $this->assertSame(['Player ID', 'Zone ID'], $variant->required_fields);
        $this->assertSame(10_000, $variant->margin());
        $this->assertSame(20, $variant->marginPercent());
    }

    public function test_tautan_supplier_harus_url_http_yang_valid(): void
    {
        $variant = $this->makeVariant($this->makeProduct());

        $this->actingAs($this->adminUser())
            ->put(route('admin.variants.update', $variant), [
                'name' => $variant->name,
                'price' => 10_000,
                'delivery_type' => Variant::DELIVERY_MANUAL,
                'supplier_url' => 'javascript:alert(1)',
            ])
            ->assertSessionHasErrors('supplier_url');

        $this->assertNull($variant->fresh()->supplier_url);
    }

    public function test_modal_dan_referensi_disuplik_ke_order_lalu_tidak_ikut_berubah(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), [
            'price' => 60_000,
            'cost_price' => 45_000,
            'supplier_name' => 'itemku',
            'supplier_sku' => 'SKU-1',
            'supplier_url' => 'https://www.itemku.com/id/produk-1',
            'required_fields' => ['Email'],
        ]);

        $this->withSession(['cart.items' => [$variant->id => 2]])
            ->post(route('checkout.store'), $this->checkoutPayload([
                'required_info' => [$variant->id => ['pembeli@contoh.test']],
            ]))
            ->assertRedirect();

        $item = Order::query()->firstOrFail()->items()->firstOrFail();

        $this->assertSame(45_000, $item->cost_price);
        $this->assertSame(90_000, $item->costTotal());
        $this->assertSame(30_000, $item->margin());
        $this->assertSame(['Email' => 'pembeli@contoh.test'], $item->required_info);

        // admin menaikkan modal & mengganti SKU varian...
        $variant->update(['cost_price' => 90_000, 'supplier_sku' => 'SKU-2']);

        // ...order lama tetap memakai salinan saat dibeli
        $item->refresh();
        $this->assertSame(45_000, $item->cost_price);
        $this->assertSame('SKU-1', $item->supplier_sku);
        $this->assertSame(30_000, $item->margin());
    }

    public function test_halaman_pesanan_menampilkan_supplier_data_pembeli_dan_margin(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), [
            'price' => 60_000,
            'cost_price' => 45_000,
            'supplier_name' => 'itemku',
            'supplier_sku' => 'SKU-1',
            'supplier_url' => 'https://www.itemku.com/id/produk-1',
            'required_fields' => ['Player ID'],
        ]);

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload([
                'required_info' => [$variant->id => ['11223344']],
            ]));

        $order = Order::query()->firstOrFail();

        $this->actingAs($this->adminUser())
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('itemku')
            ->assertSee('https://www.itemku.com/id/produk-1')
            ->assertSee('SKU-1')
            ->assertSee('Player ID')
            ->assertSee('11223344')
            ->assertSee('Margin kotor');
    }
}
