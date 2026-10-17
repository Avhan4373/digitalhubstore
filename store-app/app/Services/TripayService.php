<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integrasi Tripay (payment gateway).
 *
 * Berbeda dengan situs asal yang melakukan POLLING `/check-status` setiap
 * beberapa detik dari browser, di sini status pembayaran datang lewat
 * webhook resmi yang tanda tangannya diverifikasi.
 *
 * Kredensial dibaca dari tabel `settings` (terenkripsi), bukan hardcode di JS.
 */
class TripayService
{
    public function isConfigured(): bool
    {
        return Setting::gatewayEnabled() && $this->merchantCode() !== '';
    }

    public function baseUrl(): string
    {
        return Setting::get('tripay_mode', 'sandbox') === 'production'
            ? 'https://tripay.co.id/api/'
            : 'https://tripay.co.id/api-sandbox/';
    }

    private function merchantCode(): string
    {
        return trim((string) Setting::get('tripay_merchant_code', ''));
    }

    private function apiKey(): string
    {
        return trim((string) Setting::get('tripay_api_key', ''));
    }

    private function privateKey(): string
    {
        return trim((string) Setting::get('tripay_private_key', ''));
    }

    public function defaultChannel(): string
    {
        return (string) Setting::get('tripay_channel', 'QRIS');
    }

    /** Tanda tangan permintaan buat transaksi (skema resmi Tripay). */
    public function paymentSignature(string $merchantRef, int $amount): string
    {
        return hash_hmac('sha256', $this->merchantCode().$merchantRef.$amount, $this->privateKey());
    }

    /**
     * Verifikasi tanda tangan webhook.
     * SHA-256 HMAC atas RAW body dengan private key — dibandingkan
     * waktu-konstan supaya tidak bocor lewat timing attack.
     */
    public function verifyCallback(string $rawBody, ?string $signature): bool
    {
        if (! $signature || $this->privateKey() === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->privateKey());

        return hash_equals($expected, $signature);
    }

    /**
     * Buat transaksi dan kembalikan data pembayaran.
     *
     * @return array{ok: bool, message: string, data: array<string,mixed>}
     */
    public function createTransaction(Order $order, ?string $channel = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'Gateway pembayaran belum dikonfigurasi admin.', 'data' => []];
        }

        $channel = $channel ?: $this->defaultChannel();
        $merchantRef = $order->invoice_no;

        $payload = [
            'method' => $channel,
            'merchant_ref' => $merchantRef,
            'amount' => $order->total,
            'customer_name' => $order->buyer_name,
            'customer_email' => $order->buyer_email ?: 'noreply@example.com',
            'customer_phone' => $order->buyer_wa,
            'order_items' => $order->items->map(fn ($item) => [
                'sku' => (string) ($item->variant_id ?: $item->product_id),
                'name' => mb_substr($item->product_name.' - '.$item->variant_name, 0, 60),
                'price' => $item->unit_price,
                'quantity' => $item->qty,
            ])->all(),
            'callback_url' => route('webhook.tripay'),
            'return_url' => route('orders.show', $order->invoice_no),
            'expired_time' => ($order->expires_at ?? now()->addHour())->timestamp,
            'signature' => $this->paymentSignature($merchantRef, $order->total),
        ];

        try {
            $response = Http::withToken($this->apiKey())
                ->acceptJson()
                ->timeout(20)
                ->post($this->baseUrl().'transaction/create', $payload);

            $json = $response->json() ?? [];

            if (! ($json['success'] ?? false)) {
                Log::warning('Tripay create gagal', ['invoice' => $merchantRef, 'response' => $json]);

                return [
                    'ok' => false,
                    'message' => (string) ($json['message'] ?? 'Gateway menolak permintaan transaksi.'),
                    'data' => [],
                ];
            }

            return ['ok' => true, 'message' => 'Transaksi dibuat.', 'data' => $json['data'] ?? []];
        } catch (\Throwable $e) {
            Log::error('Tripay create error', ['invoice' => $merchantRef, 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'Gagal menghubungi gateway pembayaran.', 'data' => []];
        }
    }
}
