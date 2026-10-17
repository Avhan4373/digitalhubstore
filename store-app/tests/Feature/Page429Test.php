<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStore;
use Tests\TestCase;

/**
 * Pelanggaran batas percobaan (throttle) harus memperlihatkan halaman
 * penjelasan berbahasa Indonesia + hitung mundur, bukan 429 kosong bawaan
 * Laravel — pembeli ikut kebingungan bila refleksnya menekan refresh susul
 * menyusul dan malah makin masuk limit.
 */
class Page429Test extends TestCase
{
    use BuildsStore;
    use RefreshDatabase;

    public function test_limit_lacak_memunculkan_halaman_429_gaya_storefront(): void
    {
        $payload = ['invoice' => 'INV261017XXXXXXX', 'whatsapp' => '081234567890'];

        for ($ke = 0; $ke < 10; $ke++) {
            $this->post(route('orders.track'), $payload)->assertRedirect();
        }

        $response = $this->post(route('orders.track'), $payload)->assertStatus(429);

        $response->assertSee('Terlalu sering mencoba');
        $response->assertSee('data-countdown', false);
        $response->assertSee(route('home'));
    }
}
