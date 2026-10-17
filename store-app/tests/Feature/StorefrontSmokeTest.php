<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStore;
use Tests\TestCase;

/**
 * Asap: halaman publik utama harus bisa dibuka tanpa error.
 */
class StorefrontSmokeTest extends TestCase
{
    use BuildsStore;
    use RefreshDatabase;

    public function test_halaman_publik_utama_terbuka(): void
    {
        Setting::put('store_name', 'Toko Uji');
        Setting::put('payment_mode', 'manual');

        $product = $this->makeProduct(['name' => 'Produk Uji Publik']);
        $this->makeVariant($product, ['price' => 9_000]);

        $this->get(route('home'))->assertOk()->assertSee('Produk Uji Publik');
        $this->get(route('catalog'))->assertOk();
        $this->get(route('products.show', $product->slug))->assertOk();
        $this->get(route('cart.index'))->assertOk();
        $this->get(route('orders.track.form'))->assertOk();
        $this->get(route('login'))->assertOk();
    }

    public function test_produk_nonaktif_tidak_tampil_di_katalog(): void
    {
        $product = $this->makeProduct(['name' => 'Produk Tersembunyi', 'is_active' => false]);
        $this->makeVariant($product);

        $this->get(route('catalog'))->assertOk()->assertDontSee('Produk Tersembunyi');
    }

    public function test_produk_tanpa_varian_aktif_tidak_bisa_dibeli(): void
    {
        $product = $this->makeProduct(['name' => 'Produk Kosong']);

        $this->get(route('products.show', $product->slug))->assertOk();
        $this->post(route('cart.add'), ['variant_id' => 99999])->assertSessionHasErrors('variant_id');
    }

    public function test_keranjang_menolak_varian_tanpa_harga(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), ['price' => 0]);

        $this->post(route('cart.add'), ['variant_id' => $variant->id])
            ->assertSessionHas('error');

        $this->assertTrue(app(\App\Services\Cart::class)->isEmpty());
    }
}
