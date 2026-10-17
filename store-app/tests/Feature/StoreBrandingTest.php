<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStore;
use Tests\TestCase;

/**
 * Logo & wordmark toko harus mengikuti `store_name`, bukan hardcode
 * "DigitalHub" seperti situs asal. Kalau admin mengunggah logo sendiri,
 * gambar itu yang dipakai di semua tempat.
 */
class StoreBrandingTest extends TestCase
{
    use BuildsStore;
    use RefreshDatabase;

    public function test_monogram_dan_wordmark_mengikuti_nama_toko(): void
    {
        Setting::put('store_name', 'Creative Solutions');

        $this->assertSame('CS', Setting::logoMonogram());
        $this->assertSame(['Creative', 'Solutions'], Setting::logoWordmark());

        Setting::put('store_name', 'Toko');
        $this->assertSame('TO', Setting::logoMonogram(), 'satu kata diambil dua huruf pertama');
        $this->assertSame(['', 'Toko'], Setting::logoWordmark());
    }

    public function test_header_storefront_memakai_nama_toko_bukan_hardcode(): void
    {
        Setting::put('store_name', 'Creative Solutions');
        Setting::put('store_logo', '');
        Setting::put('payment_mode', 'manual');

        $page = $this->get(route('home'))->assertOk();

        $page->assertSee('Creative', false);
        $page->assertSee('Solutions', false);
        $page->assertSee('>CS<', false);

        // Sisa merek situs lama tidak boleh muncul lagi di markup.
        $page->assertDontSee('DIGITAL<span', false);
        $page->assertDontSee('>DH<', false);
    }

    public function test_logo_kustom_dipakai_saat_diunggah(): void
    {
        Setting::put('store_name', 'Creative Solutions');
        Setting::put('store_logo', 'settings/logo-uji.png');
        Setting::put('payment_mode', 'manual');

        $this->assertTrue(Setting::hasCustomLogo());
        $this->assertStringContainsString('settings/logo-uji.png', Setting::logoUrl());

        // Monogram tidak dirender kalau admin sudah punya logo sendiri.
        $this->get(route('home'))->assertOk()->assertDontSee('>CS<', false);
    }

    public function test_panel_pengaturan_menambal_kunci_logo_pada_instalasi_lama(): void
    {
        // Simulasikan instalasi lama: kunci logo belum ada sama sekali.
        Setting::query()->whereIn('key', ['store_logo', 'store_banner'])->delete();
        Setting::forget('store_logo');

        $this->assertFalse(Setting::query()->where('key', 'store_logo')->exists());

        $this->actingAs($this->adminUser())->get(route('admin.settings.edit'))->assertOk();

        $this->assertTrue(
            Setting::query()->where('key', 'store_logo')->exists(),
            'kunci store_logo harus dibuat ulang saat panel pengaturan dibuka',
        );
    }
}
