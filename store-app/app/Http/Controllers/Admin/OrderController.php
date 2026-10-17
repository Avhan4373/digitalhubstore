<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\DeliveryService;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly DeliveryService $delivery,
    ) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'bayar' => ['nullable', 'in:pending,paid,expired,failed,refunded'],
            'status' => ['nullable', 'in:new,processing,delivered,completed,cancelled'],
        ]);

        $orders = Order::query()
            ->withCount('items')
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($sub) => $sub
                    ->where('invoice_no', 'like', $like)
                    ->orWhere('buyer_name', 'like', $like)
                    ->orWhere('buyer_wa', 'like', $like)
                    ->orWhere('buyer_email', 'like', $like));
            })
            ->when($filters['bayar'] ?? null, fn ($q, $v) => $q->where('payment_status', $v)
                // Filter "Menunggu" harus sejalan dengan angka ringkasan & bell:
                // pesanan yang sudah dibatalkan tidak lagi dihitung belum bayar.
                ->when($v === Order::PAYMENT_PENDING && empty($filters['status']),
                    fn ($sub) => $sub->where('order_status', '!=', Order::STATUS_CANCELLED)))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('order_status', $v))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.orders.index', [
            'orders' => $orders,
            'filters' => $filters,
            'summary' => [
                'pending' => Order::query()->unpaid()->count(),
                'awaiting' => Order::query()->awaitingDelivery()->count(),
                'today' => Order::query()->where('created_at', '>=', today())->count(),
            ],
        ]);
    }

    /**
     * Sumber data "realtime" di panel admin: dibaca berulang (polling tiap
     * ±8 detik) oleh bell "Pesanan masuk" di layout admin. Ringan (dua
     * aggregate + 10 baris) dan tanpa implementasi websocket tambahan.
     */
    public function live()
    {
        $terbaru = Order::query()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Order $order): array => [
                'invoice' => $order->invoice_no,
                'buyer' => $order->buyer_name,
                'total' => (int) $order->total,
                'pembayaran' => $order->paymentLabel(),
                'status' => $order->statusLabel(),
                'lunas' => $order->isPaid(),
                'waktu' => $order->created_at->diffForHumans(),
                'momen' => $order->created_at->timestamp,
                'url' => route('admin.orders.show', $order->invoice_no),
            ]);

        return response()->json([
            'belum_bayar' => Order::query()->unpaid()->count(),
            'perlu_kirim' => Order::query()->awaitingDelivery()->count(),
            'terbaru' => $terbaru,
        ]);
    }

    public function show(Order $order)
    {
        $order->load(['items.variant.product', 'items.deliveries', 'coupon']);

        return view('admin.orders.show', ['order' => $order]);
    }

    /** Konfirmasi pembayaran manual (setara tombol verifikasi di situs asal). */
    public function markPaid(Request $request, Order $order)
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($order->isPaid()) {
            return back()->with('error', 'Pesanan ini sudah lunas.');
        }

        $order = $this->orders->markPaid($order, 'manual-'.auth()->id(), [
            'verified_by' => auth()->user()->email,
            'note' => $data['note'] ?? null,
        ], auth()->user());

        $pending = $order->items()->where('is_fulfilled', false)->count();

        return back()->with(
            'success',
            $pending === 0
                ? 'Pembayaran dikonfirmasi dan semua item terkirim otomatis.'
                : "Pembayaran dikonfirmasi. {$pending} item masih perlu dikirim manual."
        );
    }

    /** Kirim ulang otomatis (misal stok baru ditambahkan). */
    public function deliver(Order $order)
    {
        if (! $order->isPaid()) {
            return back()->with('error', 'Konfirmasi pembayaran dulu sebelum mengirim.');
        }

        if ($order->isCancelled()) {
            return back()->with('error', 'Pesanan sudah dibatalkan, tidak bisa dikirim.');
        }

        $stats = $this->delivery->attemptFulfill($order, auth()->user());

        return back()->with(
            'success',
            "Pengiriman diproses: {$stats['delivered']} item terkirim, {$stats['manual']} manual, {$stats['waiting_stock']} menunggu stok."
        );
    }

    /** Selesaikan pengiriman manual: admin menempel isi akun/link. */
    public function completeItem(Request $request, Order $order, OrderItem $item)
    {
        abort_unless($item->order_id === $order->id, 404);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:10000'],
        ]);

        $this->delivery->completeManually($order, $item, $data['content'], auth()->user());

        return back()->with('success', 'Item ditandai terkirim dan isinya tercatat untuk pembeli.');
    }

    public function cancel(Request $request, Order $order)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $this->orders->cancel($order, $data['reason'], auth()->user());

        return back()->with('success', 'Pesanan dibatalkan dan stok dikembalikan.');
    }

    public function updateNote(Request $request, Order $order)
    {
        $data = $request->validate(['admin_note' => ['nullable', 'string', 'max:1000']]);

        $order->update(['admin_note' => $data['admin_note']]);

        ActivityLog::record('order.note', 'Catatan admin diperbarui untuk '.$order->invoice_no, $order);

        return back()->with('success', 'Catatan admin disimpan.');
    }

    /** Bersihkan order pending yang kedaluwarsa & kembalikan stoknya. */
    public function expireStale()
    {
        $count = $this->orders->expireStaleOrders();

        return back()->with('success', $count === 0
            ? 'Tidak ada order kedaluwarsa.'
            : "{$count} order kedaluwarsa ditandai dan stoknya dikembalikan.");
    }
}
