<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStore;
use Tests\TestCase;

/**
 * Struktur form halaman edit produk.
 *
 * HTML tidak mengizinkan form bersarang. Kalau form produk membungkus kartu
 * varian (yang berisi satu form per varian), browser menutup form produk lebih
 * awal: tombol "Simpan perubahan" kehilangan pemilik form dan field setelah
 * varian (gambar utama, SEO, "terjual") tidak ikut terkirim.
 *
 * Karena itu form produk hanya membungkus kartu "Data produk", dan kontrol di
 * kolom kanan dihubungkan lewat atribut `form="produk-form"`.
 */
class ProductFormStructureTest extends TestCase
{
    use BuildsStore;
    use RefreshDatabase;

    public function test_form_produk_tidak_membungkus_form_varian(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product);

        $html = $this->halamanEdit($product->id);

        // Tidak ada form lain di dalam form produk.
        $this->assertStringNotContainsString('<form', $this->isiFormProduk($html));

        // Tag form harus seimbang, tanda struktur tidak terpotong.
        $this->assertSame(substr_count($html, '<form'), substr_count($html, '</form>'));
    }

    public function test_field_kolom_kanan_dan_tombol_simpan_terhubung_ke_form_produk(): void
    {
        $product = $this->makeProduct();

        $html = $this->halamanEdit($product->id);

        // Field yang tinggal di luar form (kolom kanan) wajib punya pemilik.
        foreach (['is_active', 'is_best_seller', 'is_hot', 'sold_count', 'sort_order', 'cover', 'meta_title', 'meta_description'] as $nama) {
            $this->assertMatchesRegularExpression(
                '/form="produk-form"[^>]*name="'.$nama.'"|name="'.$nama.'"[^>]*form="produk-form"/',
                $html,
                "Field {$nama} harus terhubung ke form produk lewat atribut form=."
            );
        }

        // Tombol simpan tidak berada di dalam form, jadi wajib memakai atribut form=.
        $this->assertMatchesRegularExpression('/<button[^>]*form="produk-form"/', $html);
    }

    public function test_halaman_produk_baru_juga_tidak_punya_form_bersarang(): void
    {
        $html = $this->actingAs($this->adminUser())
            ->get(route('admin.products.create'))
            ->assertOk()
            ->getContent();

        $this->assertSame(substr_count($html, '<form'), substr_count($html, '</form>'));

        $this->assertStringNotContainsString('<form', $this->isiFormProduk($html));
    }

    /**
     * Isi (bukan tag pembuka) elemen `form#produk-form`.
     *
     * Dipakai untuk memastikan tidak ada form lain yang bersarang di dalamnya.
     */
    private function isiFormProduk(string $html): string
    {
        $mulai = strpos($html, '<form id="produk-form"');
        $this->assertNotFalse($mulai, 'Form produk harus punya id="produk-form".');

        $isi = strpos($html, '>', $mulai) + 1;
        $selesai = strpos($html, '</form>', $isi);
        $this->assertNotFalse($selesai, 'Form produk harus tertutup.');

        return substr($html, $isi, $selesai - $isi);
    }

    private function halamanEdit(int $id): string
    {
        $product = \App\Models\Product::query()->findOrFail($id);

        return $this->actingAs($this->adminUser())
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->getContent();
    }
}
