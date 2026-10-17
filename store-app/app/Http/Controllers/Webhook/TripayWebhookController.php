<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\TripayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Tripay.
 *
 * Berbeda dari situs asal yang membiarkan browser melakukan polling status
 * pembayaran (dan endpoint `/check-status`-nya bisa dipanggil siapa saja),
 * endpoint ini:
 *  - tidak menerima data apa pun dari pembeli (hanya dari gateway),
 *  - memverifikasi HMAC SHA-256 atas RAW body dengan private key,
 *  - idempoten (order lunas tidak dikirim dua kali).
 */
class TripayWebhookController extends Controller
{
    public function __construct(
        private readonly TripayService $tripay,
        private readonly OrderService $orders,
    ) {}

    public function handle(Request $request)
    {
        $raw = $request->getContent();
        $signature = $request->header('X-Callback-Signature');

        if (! $this->tripay->verifyCallback($raw, $signature)) {
            Log::warning('Tripay webhook: tanda tangan tidak valid', ['ip' => $request->ip()]);

            return response()->json(['success' => false, 'message' => 'Invalid signature'], 403);
        }

        $payload = json_decode($raw, true) ?: [];
        $event = $request->header('X-Callback-Event', $payload['event'] ?? '');
        $reference = (string) ($payload['reference'] ?? '');
        $merchantRef = (string) ($payload['merchant_ref'] ?? '');

        $order = Order::query()
            ->where('invoice_no', $merchantRef)
            ->when($merchantRef === '', fn ($q) => $q->where('gateway_reference', $reference))
            ->first();

        if (! $order) {
            Log::warning('Tripay webhook: order tidak ditemukan', ['merchant_ref' => $merchantRef]);

            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        // Hanya event pembayaran berhasil yang memicu pengiriman.
        $paidStatuses = ['PAID'];
        $status = strtoupper((string) ($payload['status'] ?? ''));

        if ($event === 'payment_status' && in_array($status, $paidStatuses, true)) {
            $this->orders->markPaid($order, $reference, $payload);
        } elseif (in_array($event, ['payment_status'], true) && in_array($status, ['EXPIRED', 'FAILED'], true)) {
            // Webhook bisa datang berulang (gateway retry). `cancel()` menolak
            // pesanan lunas/terkirim/sudah batal, jadi jangan sampai exception
            // itu membuat endpoint membalas 500 dan dipanggil ulang terus.
            if ($order->isPaid() || $order->isDelivered() || $order->isCancelled()) {
                Log::info('Tripay webhook: pembatalan dilewati', [
                    'invoice' => $order->invoice_no,
                    'status' => $order->order_status,
                    'payment' => $order->payment_status,
                ]);
            } else {
                $this->orders->cancel($order, 'Gateway: '.$status);
            }
        }

        Log::info('Tripay webhook diproses', [
            'invoice' => $order->invoice_no,
            'event' => $event,
            'status' => $status,
        ]);

        return response()->json(['success' => true]);
    }
}
