<?php

namespace Tests\Feature\Webhook;

use App\Models\Order;
use App\Models\Setting;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStore;
use Tests\TestCase;

/**
 * Webhook Tripay: hanya permintaan dengan HMAC SHA-256 yang valid diterima.
 * Situs asal tidak punya verifikasi ini — endpoint `POST /` dan `/orders/*`
 * di sana menerima perubahan apa pun dari siapa pun.
 */
class TripayWebhookTest extends TestCase
{
    use BuildsStore;
    use RefreshDatabase;

    private const PRIVATE_KEY = 'kunci-privat-uji';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('payment_mode', 'manual');
        Setting::put('tripay_private_key', self::PRIVATE_KEY, 'string', 'gateway', 'Private key', secret: true);
    }

    private function orderPending(): Order
    {
        $variant = $this->makeVariant($this->makeProduct(), [
            'delivery_type' => Variant::DELIVERY_AUTO,
            'price' => 12_000,
        ]);
        $this->fillStock($variant, ['akun@contoh.test|Pass1']);

        $this->withSession(['cart.items' => [$variant->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload());

        return Order::query()->firstOrFail();
    }

    private function kirim(array $payload, ?string $signature): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            route('webhook.tripay'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CALLBACK_SIGNATURE' => (string) $signature,
                'HTTP_X_CALLBACK_EVENT' => (string) ($payload['event'] ?? ''),
            ],
            $body
        );
    }

    public function test_permintaan_tanpa_tanda_tangan_ditolak(): void
    {
        $order = $this->orderPending();

        $this->kirim([
            'event' => 'payment_status',
            'status' => 'PAID',
            'merchant_ref' => $order->invoice_no,
        ], null)->assertStatus(403);

        $this->assertFalse($order->fresh()->isPaid());
    }

    public function test_tanda_tangan_palsu_ditolak(): void
    {
        $order = $this->orderPending();

        $this->kirim([
            'event' => 'payment_status',
            'status' => 'PAID',
            'merchant_ref' => $order->invoice_no,
        ], str_repeat('a', 64))->assertStatus(403);

        $this->assertFalse($order->fresh()->isPaid());
    }

    public function test_tanda_tangan_valid_menandai_lunas_dan_mengirim_barang(): void
    {
        $order = $this->orderPending();

        $payload = [
            'event' => 'payment_status',
            'status' => 'PAID',
            'reference' => 'T1234567890',
            'merchant_ref' => $order->invoice_no,
        ];

        $signature = hash_hmac('sha256', json_encode($payload), self::PRIVATE_KEY);

        $this->kirim($payload, $signature)->assertOk()->assertJson(['success' => true]);

        $order->refresh();

        $this->assertTrue($order->isPaid());
        $this->assertSame('T1234567890', $order->gateway_reference);
        $this->assertSame(Order::STATUS_DELIVERED, $order->order_status);
        $this->assertSame('akun@contoh.test|Pass1', $order->items()->first()->deliveredContents()[0]);
    }

    public function test_webhook_ulang_tidak_mengirim_dua_kali(): void
    {
        $order = $this->orderPending();

        $payload = [
            'event' => 'payment_status',
            'status' => 'PAID',
            'reference' => 'T1234567890',
            'merchant_ref' => $order->invoice_no,
        ];

        $signature = hash_hmac('sha256', json_encode($payload), self::PRIVATE_KEY);

        $this->kirim($payload, $signature)->assertOk();
        $this->kirim($payload, $signature)->assertOk();

        $this->assertSame(1, $order->fresh()->items()->first()->deliveries()->count());
    }

    public function test_invoice_tidak_dikenal_dijawab_404(): void
    {
        $payload = [
            'event' => 'payment_status',
            'status' => 'PAID',
            'merchant_ref' => 'INVTIDAKADA0001',
        ];

        $signature = hash_hmac('sha256', json_encode($payload), self::PRIVATE_KEY);

        $this->kirim($payload, $signature)->assertStatus(404);
    }
}
