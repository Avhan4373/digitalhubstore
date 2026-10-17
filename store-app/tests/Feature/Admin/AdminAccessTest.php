<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStore;
use Tests\TestCase;

/**
 * Panel admin wajib tertutup: situs asal membuka `/admin` tanpa password
 * sama sekali dan menyimpan kredensial di dalam file JS publik.
 */
class AdminAccessTest extends TestCase
{
    use BuildsStore;
    use RefreshDatabase;

    private ?string $invoiceDemo = null;

    /** @return list<string> */
    private function halamanAdmin(): array
    {
        return [
            route('admin.dashboard'),
            route('admin.products.index'),
            route('admin.orders.index'),
            route('admin.reviews.index'),
            route('admin.coupons.index'),
            route('admin.settings.edit'),
            route('admin.logs.index'),
            route('admin.users.index'),
        ];
    }

    private function buatPesananDemo(): void
    {
        $variant = $this->makeVariant($this->makeProduct(), ['price' => 30_000]);

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload());

        $this->invoiceDemo = Order::query()->firstOrFail()->invoice_no;
    }

    private function invoiceDemo(): string
    {
        return $this->invoiceDemo;
    }

    public function test_tamu_dialihkan_ke_login(): void
    {
        foreach ($this->halamanAdmin() as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        // endpoint realtime juga tertutup
        $this->get(route('admin.orders.live'))->assertRedirect(route('login'));
    }

    public function test_endpoint_live_mengembalikan_ringkasan_dan_daftar_pesanan(): void
    {
        $this->buatPesananDemo();

        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->getJson(route('admin.orders.live'))
            ->assertOk()
            ->assertJsonStructure(['belum_bayar', 'perlu_kirim', 'terbaru' => [['invoice', 'buyer', 'total', 'pembayaran', 'status', 'lunas', 'waktu', 'momen', 'url']]]);

        $payload = $response->json();

        $this->assertSame(1, $payload['belum_bayar']);
        $this->assertCount(1, $payload['terbaru']);
        $this->assertSame($this->invoiceDemo(), $payload['terbaru'][0]['invoice']);
        $this->assertFalse($payload['terbaru'][0]['lunas']);
        $this->assertSame(route('admin.orders.show', $this->invoiceDemo()), $payload['terbaru'][0]['url']);
    }

    public function test_staff_tidak_bisa_membuka_manajemen_pengguna(): void
    {
        $staff = $this->staffUser();

        $this->actingAs($staff)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_admin_bisa_membuka_semua_halaman(): void
    {
        $admin = $this->adminUser();

        foreach ($this->halamanAdmin() as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    /**
     * Layout admin harus menyediakan navigasi mobile (drawer + bottom nav)
     * berdampingan dengan sidebar desktop, dan semua tautan nav wajib sama
     * di kedua tempat supaya tidak ada menu yang hilang di HP.
     */
    public function test_layout_admin_punya_navigasi_mobile_lengkap(): void
    {
        $admin = $this->adminUser();

        $page = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $page->assertSee('data-admin-drawer', false);
        $page->assertSee('data-admin-drawer-backdrop', false);
        $page->assertSee('data-admin-bottomnav', false);
        $page->assertSee('data-admin-nav-toggle', false);
        $page->assertSee('viewport-fit=cover', false);

        // Tautan menu harus tersedia di sidebar desktop DAN drawer mobile:
        // setiap route muncul minimal dua kali di halaman.
        $html = $page->getContent();

        foreach ([
            route('admin.dashboard'),
            route('admin.products.index'),
            route('admin.orders.index'),
            route('admin.reviews.index'),
            route('admin.coupons.index'),
            route('admin.logs.index'),
        ] as $url) {
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($html, 'href="'.$url.'"'),
                'tautan '.$url.' harus ada di sidebar desktop dan drawer mobile',
            );
        }

        // Bottom nav hanya 4 pintasan + tombol "Lainnya" (menu penuh di drawer).
        $this->assertSame(2, substr_count($html, 'data-admin-nav-toggle'), 'hamburger header + tombol Lainnya di bottom nav');
    }

    public function test_login_gagal_tidak_membuat_sesi(): void
    {
        $this->adminUser();

        $this->post(route('login.store'), [
            'email' => 'admin-uji@example.test',
            'password' => 'password-salah',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_akun_nonaktif_tidak_bisa_login(): void
    {
        $admin = $this->adminUser();
        $admin->forceFill(['is_active' => false])->save();

        $this->post(route('login.store'), [
            'email' => 'admin-uji@example.test',
            'password' => 'password-uji-panjang',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_password_admin_tidak_pernah_ditampilkan_di_halaman_pengguna(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee('password-uji-panjang');
    }
}
