<?php

namespace Tests\Feature\Storefront;

use App\Models\Order;
use App\Models\Review;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStore;
use Tests\TestCase;

/**
 * Ulasan: hanya pembeli yang benar-benar memesan boleh mengulas, dan isi
 * ulasan tidak pernah dieksekusi sebagai HTML (stored XSS di situs asal).
 */
class ReviewTest extends TestCase
{
    use BuildsStore;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('payment_mode', 'manual');
        Setting::put('auto_approve_reviews', false);
    }

    private function buatOrderDenganProduk(): array
    {
        $product = $this->makeProduct(['name' => 'Canva Uji']);
        $variant = $this->makeVariant($product, ['price' => 10_000]);

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload());

        return [$product, Order::query()->firstOrFail()];
    }

    public function test_ulasan_tanpa_invoice_yang_cocok_ditolak(): void
    {
        [$product] = $this->buatOrderDenganProduk();

        $this->post(route('reviews.store', $product->slug), [
            'user_name' => 'Penyusup',
            'rating' => 5,
            'comment' => 'Ulasan tanpa membeli.',
            'invoice' => 'INVPALSU123456',
        ])->assertSessionHasErrors('invoice');

        $this->assertSame(0, Review::query()->count());
    }

    public function test_ulp_tanpa_file_dan_ulasan_masuk_moderasi(): void
    {
        [$product, $order] = $this->buatOrderDenganProduk();

        $this->post(route('reviews.store', $product->slug), [
            'user_name' => 'Pembeli Uji',
            'rating' => 5,
            'comment' => 'Barangnya sesuai, prosesnya cepat.',
            'invoice' => $order->invoice_no,
        ])->assertSessionHasNoErrors();

        $review = Review::query()->firstOrFail();

        $this->assertSame(Review::STATUS_PENDING, $review->status);
        $this->assertSame($order->id, $review->order_id);

        // belum tayang di halaman publik
        $this->get(route('products.show', $product->slug))
            ->assertOk()
            ->assertDontSee('Barangnya sesuai, prosesnya cepat.');
    }

    public function test_satu_invoice_hanya_boleh_mengulas_sekali(): void
    {
        [$product, $order] = $this->buatOrderDenganProduk();

        $payload = [
            'user_name' => 'Pembeli Uji',
            'rating' => 4,
            'comment' => 'Ulasan pertama saya.',
            'invoice' => $order->invoice_no,
        ];

        $this->post(route('reviews.store', $product->slug), $payload)->assertSessionHasNoErrors();
        $this->post(route('reviews.store', $product->slug), $payload)->assertSessionHasErrors('invoice');

        $this->assertSame(1, Review::query()->count());
    }

    public function test_komentar_berisi_skrip_di_escape_saat_dirender(): void
    {
        [$product, $order] = $this->buatOrderDenganProduk();

        $xss = '<script>alert("xss")</script>';

        Review::create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'user_name' => 'Penyerang',
            'rating' => 5,
            'comment' => $xss,
            'status' => Review::STATUS_APPROVED,
        ]);

        $html = $this->get(route('products.show', $product->slug))->assertOk()->getContent();

        // tag tidak boleh muncul sebagai HTML
        $this->assertStringNotContainsString($xss, $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_form_ulasan_tidak_syarang_di_dalam_form_keranjang(): void
    {
        [$product] = $this->buatOrderDenganProduk();

        $html = $this->get(route('products.show', $product->slug))
            ->assertOk()
            ->assertSee('Tulis Ulasan Anda')
            ->getContent();

        // Form ulasan sebelumnya bersarang di dalam <form> keranjang: penutup
        // `</form>` ulasan menutup form keranjang lebih awal sehingga validasi
        // wajib rating ikut memblokir tombol beli. Pastikan struktur html sekarang:
        // </form> keranjang muncul SEBELUM <form ulasan dibuka, dan kontrol bar
        // aksi (di luar form) dihubungkan lewat atribut form="form-beli".
        $akhirFormKeranjang = strpos($html, '</form>');
        $formUlasan = strpos($html, route('reviews.store', $product->slug));

        $this->assertNotFalse($akhirFormKeranjang);
        $this->assertNotFalse($formUlasan);
        $this->assertLessThan($formUlasan, $akhirFormKeranjang);

        $isiFormKeranjang = substr($html, 0, $akhirFormKeranjang);

        // field wajib ulasan tidak boleh berada di dalam form keranjang
        $this->assertStringNotContainsString('name="rating"', $isiFormKeranjang);
        $this->assertStringNotContainsString('name="user_name"', $isiFormKeranjang);
        $this->assertStringNotContainsString('name="invoice"', $isiFormKeranjang);

        // bar aksi tetap terhubung ke form keranjang tanpa bersarang
        $this->assertStringContainsString('form="form-beli"', $html);
    }

    public function test_form_ulasan_hanya_tampil_untuk_pembeli_produk_tersebut(): void
    {
        // pembeli Canva: invoice produk ini ada di session, tapi belum mengulas
        [$product] = $this->buatOrderDenganProduk();

        $produkLain = $this->makeProduct(['name' => 'Netflix Uji']);
        $this->makeVariant($produkLain, ['price' => 15_000]);

        // pembeli produk lain tidak ditawarkan form ulasan di produk ini
        $this->get(route('products.show', $produkLain->slug))
            ->assertOk()
            ->assertDontSee('Tulis Ulasan Anda');

        // pembeli produk ini tetap bisa mengulas tanpa mengetik apa pun dulu
        $this->get(route('products.show', $product->slug))
            ->assertOk()
            ->assertSee('Tulis Ulasan Anda');

        // sesi kosong (belum pernah membeli) juga tidak mendapat form
        $this->flushSession();

        $this->get(route('products.show', $product->slug))
            ->assertOk()
            ->assertDontSee('Tulis Ulasan Anda');
    }
}
