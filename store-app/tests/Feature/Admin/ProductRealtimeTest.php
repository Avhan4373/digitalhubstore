<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStore;
use Tests\TestCase;

/**
 * Aksi cepat admin dijawab JSON supaya halaman bisa diperbarui tanpa reload
 * ("realtime" sederhana).
 *
 * Dua hal yang dijaga di sini:
 *
 * 1. Bentuk jawabannya cukup untuk memperbarui halaman: `row` (render ulang
 *    baris oleh SERVER, bukan disusun ulang di JavaScript), `counts` (angka tab
 *    status), `is_active`/`trashed`/`aktif_varian` (penentu baris masih pantas
 *    tampil di tab yang sedang dibuka).
 * 2. Markup baris tetap berasal dari partial Blade yang sama dengan halaman
 *    daftar — termasuk `available_count` untuk badge stok, yang gampang hilang
 *    kalau relasinya tidak dimuat saat merender baris.
 *
 * Permintaan tanpa header JSON wajib tetap berperilaku seperti sebelumnya
 * (redirect `back()`), jadi form biasa tetap berfungsi tanpa JavaScript.
 */
class ProductRealtimeTest extends TestCase
{
    use BuildsStore;
    use RefreshDatabase;

    public function test_sembunyikan_produk_dijawab_json_dengan_baris_dan_angka_tab(): void
    {
        $product = $this->makeProduct(['is_active' => true]);

        $jawab = $this->actingAs($this->adminUser())
            ->postJson(route('admin.products.status', $product), ['is_active' => '0'])
            ->assertOk();

        $product->refresh();
        $this->assertFalse($product->is_active);

        $data = $jawab->json();
        $this->assertTrue($data['ok']);
        $this->assertFalse($data['is_active']);
        $this->assertFalse($data['trashed']);
        $this->assertSame(0, $data['aktif_varian']);
        $this->assertSame('Produk "'.$product->name.'" disembunyikan dari toko.', $data['message']);

        // Angka tab ikut dikirim supaya tidak ada angka basi setelah aksi cepat.
        $this->assertSame(1, $data['counts']['semua']);
        $this->assertSame(0, $data['counts']['aktif']);
        $this->assertSame(1, $data['counts']['nonaktif']);

        // Baris yang dirender server sudah dalam keadaan baru.
        $this->assertStringContainsString('data-product-row="'.$product->id.'"', $data['row']);
        $this->assertStringContainsString('data-product-status="0"', $data['row']);
        $this->assertStringContainsString('Nonaktif', $data['row']);
        $this->assertStringContainsString('Tampilkan', $data['row']);

        // Penanda yang dipakai JavaScript untuk menukar potongan baris.
        foreach (['data-product-identitas', 'data-product-ringkasan', 'data-product-aksi', 'data-product-panel-bar'] as $penanda) {
            $this->assertStringContainsString($penanda, $data['row']);
        }
    }

    public function test_baris_hasil_json_memuat_badge_stok_dan_ringkasan_varian(): void
    {
        $product = $this->makeProduct(['is_active' => true, 'sold_count' => 1200]);
        $varian = $this->makeVariant($product, [
            'name' => 'Varian Berstok',
            'price' => 25_000,
            'delivery_type' => Variant::DELIVERY_AUTO,
        ]);
        $this->fillStock($varian, ['akun-1', 'akun-2']);

        $data = $this->actingAs($this->adminUser())
            ->postJson(route('admin.products.status', $product), ['is_active' => '1'])
            ->assertOk()
            ->json();

        // `available_count` hanya ada kalau relasi varian dimuat dengan
        // withCount saat merender baris.
        $this->assertStringContainsString('stok 2', $data['row']);
        $this->assertStringContainsString('1 dijual', $data['row']);
        $this->assertStringContainsString('Rp 25.000', $data['row']);
        $this->assertStringContainsString('1.200', $data['row']);
    }

    public function test_simpan_cepat_varian_dijawab_json_dengan_harga_baru(): void
    {
        $product = $this->makeProduct();
        $varian = $this->makeVariant($product, ['name' => '1 Bulan', 'price' => 5_000, 'is_active' => true]);

        $data = $this->actingAs($this->adminUser())
            ->putJson(route('admin.variants.quick', $varian), ['price' => 7_500, 'is_active' => '1'])
            ->assertOk()
            ->json();

        $varian->refresh();
        $this->assertSame(7_500, $varian->price);

        $this->assertSame('Varian "1 Bulan" disimpan.', $data['message']);
        $this->assertSame(1, $data['aktif_varian']);
        $this->assertStringContainsString('data-variant-row="'.$varian->id.'"', $data['row']);
        $this->assertStringContainsString('value="7500"', $data['row']);
        $this->assertStringContainsString('Rp 7.500', $data['row']);
    }

    public function test_menonaktifkan_varian_terakhir_mengubah_angka_tab_tanpa_varian(): void
    {
        $product = $this->makeProduct(['is_active' => true]);
        $varian = $this->makeVariant($product, ['is_active' => true]);

        $data = $this->actingAs($this->adminUser())
            ->putJson(route('admin.variants.quick', $varian), ['price' => 10_000])
            ->assertOk()
            ->json();

        // Checkbox tidak dikirim = varian dinonaktifkan.
        $varian->refresh();
        $this->assertFalse($varian->is_active);

        $this->assertSame(0, $data['aktif_varian']);
        $this->assertSame(1, $data['counts']['tanpa_varian']);
        $this->assertStringContainsString('line-through', $data['row']);
    }

    public function test_pulihkan_produk_dijawab_json_sebagai_baris_biasa(): void
    {
        $product = $this->makeProduct();
        $product->delete();

        $data = $this->actingAs($this->adminUser())
            ->postJson(route('admin.products.restore', $product->id))
            ->assertOk()
            ->json();

        $this->assertFalse($data['trashed']);
        $this->assertSame(0, $data['counts']['sampah']);
        $this->assertStringContainsString('data-product-trashed="0"', $data['row']);
        // Baris bekas sampah kembali punya tombol Sembunyikan + Edit, bukan Pulihkan.
        $this->assertStringNotContainsString('Pulihkan', $data['row']);
        $this->assertStringContainsString('Edit', $data['row']);
    }

    public function test_validasi_gagal_dijawab_422_json(): void
    {
        $product = $this->makeProduct();

        $this->actingAs($this->adminUser())
            ->postJson(route('admin.products.status', $product), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');
    }

    public function test_tanpa_header_json_tetap_memakai_redirect_biasa(): void
    {
        $product = $this->makeProduct(['is_active' => true]);

        $this->actingAs($this->adminUser())
            ->post(route('admin.products.status', $product), ['is_active' => '0'])
            ->assertRedirect();

        $this->assertFalse($product->refresh()->is_active);
    }

    public function test_update_produk_dari_form_edit_dijawab_json(): void
    {
        $product = $this->makeProduct(['name' => 'Produk Lama']);

        $data = $this->actingAs($this->adminUser())
            ->putJson(route('admin.products.update', $product), [
                'name' => 'Produk Baru',
                'category_id' => $product->category_id,
                'is_active' => '1',
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($data['ok']);
        $this->assertSame('Perubahan produk disimpan.', $data['message']);
        $this->assertSame('Produk Baru', $data['name']);
        $this->assertSame('Produk Baru', $product->refresh()->name);
        // Halaman edit memakai ini untuk memperbarui pratinjau gambar utama.
        $this->assertArrayHasKey('cover_url', $data);
    }

    public function test_update_varian_dijawab_json_dengan_ringkasan_baru(): void
    {
        $product = $this->makeProduct();
        $varian = $this->makeVariant($product, [
            'name' => 'Nama Lama',
            'price' => 10_000,
            'delivery_type' => Variant::DELIVERY_AUTO,
            'cost_price' => 6_000,
        ]);
        $this->fillStock($varian, ['akun-1']);

        $data = $this->actingAs($this->adminUser())
            ->putJson(route('admin.variants.update', $varian), [
                'name' => 'Nama Baru',
                'price' => 12_000,
                'delivery_type' => Variant::DELIVERY_AUTO,
                'cost_price' => 6_000,
                'is_active' => '1',
            ])
            ->assertOk()
            ->json();

        $this->assertSame('Varian "Nama Baru" diperbarui.', $data['message']);
        $this->assertStringContainsString('data-variant-summary="'.$varian->id.'"', $data['summary']);
        $this->assertStringContainsString('Nama Baru', $data['summary']);
        $this->assertStringContainsString('Rp 12.000', $data['summary']);
        // Badge stok & margin ikut dirender ulang oleh server.
        $this->assertStringContainsString('stok 1', $data['summary']);
        $this->assertStringContainsString('margin', $data['summary']);
    }

    public function test_jawaban_json_tidak_memuat_data_modal_supplier(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product, [
            'delivery_type' => Variant::DELIVERY_MANUAL,
            'cost_price' => 40_000,
            'supplier_name' => 'itemku',
        ]);

        $data = $this->actingAs($this->adminUser())
            ->postJson(route('admin.products.status', $product), ['is_active' => '1'])
            ->assertOk()
            ->json();

        // Panel admin boleh melihat margin, tapi baris daftar tidak pernah
        // memuat harga modal mentah maupun SKU supplier.
        $this->assertStringNotContainsString('supplier_sku', $data['row']);
        $this->assertStringNotContainsString('cost_price', $data['row']);
    }
}
