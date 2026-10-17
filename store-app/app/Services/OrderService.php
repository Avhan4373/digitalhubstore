<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pembuatan & perubahan status pesanan.
 *
 * Prinsip: harga, potongan, dan stok SELALU dihitung ulang di server dari
 * database. Tidak ada satu pun angka yang dipercaya dari input klien.
 */
class OrderService
{
    public function __construct(
        private readonly Cart $cart,
        private readonly CouponService $coupons,
        private readonly DeliveryService $delivery,
    ) {}

    /**
     * @param  array{name: string, whatsapp: string, email?: ?string, note?: ?string}  $buyer
     * @param  array<int, array<int, mixed>>  $requiredInfo  jawaban data wajib, per id varian
     */
    public function createFromCart(array $buyer, string $paymentMethod, ?string $couponCode = null, array $requiredInfo = []): Order
    {
        $items = $this->cart->items();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'Keranjang Anda kosong.']);
        }

        $subtotal = (int) $items->sum('subtotal');

        return DB::transaction(function () use ($items, $buyer, $paymentMethod, $couponCode, $subtotal, $requiredInfo) {
            $coupon = null;
            $discount = 0;

            if ($couponCode) {
                $result = $this->coupons->validate(
                    $couponCode,
                    $subtotal,
                    $buyer['whatsapp']
                );

                if (! $result['ok']) {
                    throw ValidationException::withMessages(['coupon_code' => $result['message']]);
                }

                $coupon = $result['coupon'];
                $discount = $result['discount'];
            }

            $order = Order::create([
                'invoice_no' => Order::newInvoiceNumber(),
                'buyer_name' => $buyer['name'],
                'buyer_wa' => wa_normalize($buyer['whatsapp']),
                'buyer_email' => $buyer['email'] ?? null,
                'buyer_note' => $buyer['note'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => max(0, $subtotal - $discount),
                'coupon_id' => $coupon?->id,
                'coupon_code' => $coupon?->code,
                'payment_method' => $paymentMethod,
                'payment_status' => Order::PAYMENT_PENDING,
                'order_status' => Order::STATUS_NEW,
                'expires_at' => now()->addMinutes(Setting::orderTtlMinutes()),
                'ip' => request()->ip(),
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
            ]);

            foreach ($items as $row) {
                /** @var Variant $variant */
                $variant = $row['variant'];
                $qty = (int) $row['qty'];

                // Kunci varian supaya dua checkout bersamaan tidak
                // mengambil stok yang sama.
                $fresh = Variant::query()->lockForUpdate()->find($variant->id);

                if (! $fresh || ! $fresh->is_active) {
                    throw ValidationException::withMessages([
                        'cart' => $variant->name.' sudah tidak tersedia, silakan hapus dari keranjang.',
                    ]);
                }

                if ($fresh->requiresStock()) {
                    $available = StockItem::query()
                        ->where('variant_id', $fresh->id)
                        ->where('status', StockItem::STATUS_AVAILABLE)
                        ->lockForUpdate()
                        ->count();

                    if ($available < $qty) {
                        throw ValidationException::withMessages([
                            'cart' => 'Stok '.$fresh->name.' tinggal '.$available.' unit.',
                        ]);
                    }
                }

                $item = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $variant->product_id,
                    'variant_id' => $variant->id,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,
                    'unit_price' => $variant->price,
                    'qty' => $qty,
                    'subtotal' => $variant->price * $qty,
                    'delivery_instruction' => $variant->delivery_instruction
                        ?: $this->defaultInstruction($variant),
                    // salinan referensi supplier saat dibeli + data wajib pembeli
                    'cost_price' => $fresh->cost_price,
                    'supplier_name' => $fresh->supplier_name,
                    'supplier_sku' => $fresh->supplier_sku,
                    'supplier_url' => $fresh->supplier_url,
                    'required_info' => $this->collectRequiredInfo($fresh, $requiredInfo[$fresh->id] ?? []) ?: null,
                ]);

                $this->reserveStock($item, $fresh, $qty);
            }

            if ($coupon) {
                $coupon->increment('used_count');
            }

            ActivityLog::record('order.created', 'Pesanan '.$order->invoice_no.' dibuat', $order, [
                'total' => $order->total,
                'items' => $items->count(),
            ]);

            return $order->load('items');
        });
    }

    /** Tandai order lunas; kalau stok memadai, kirim otomatis. */
    public function markPaid(Order $order, ?string $reference = null, array $payload = [], ?User $actor = null): Order
    {
        if ($order->isPaid()) {
            return $order;
        }

        // Pesanan yang sudah dibatalkan tidak boleh dihidupkan lagi lewat
        // konfirmasi pembayaran — stoknya sudah dilepas ke toko.
        if ($order->isCancelled()) {
            throw ValidationException::withMessages([
                'order' => 'Pesanan sudah dibatalkan, tidak bisa dikonfirmasi lunas.',
            ]);
        }

        DB::transaction(function () use ($order, $reference, $payload) {
            $order->forceFill([
                'payment_status' => Order::PAYMENT_PAID,
                'paid_at' => now(),
                // Lunas tapi belum terkirim => masuk antrean pengiriman admin.
                'order_status' => $order->isDelivered() ? $order->order_status : Order::STATUS_PROCESSING,
                'gateway_reference' => $reference ?: $order->gateway_reference,
                'gateway_payload' => $payload ?: $order->gateway_payload,
            ])->save();
        });

        ActivityLog::record('order.paid', 'Pembayaran '.$order->invoice_no.' dikonfirmasi', $order, [
            'reference' => $reference,
        ], $actor);

        $this->delivery->attemptFulfill($order->fresh(), $actor);

        return $order->fresh();
    }

    public function cancel(Order $order, string $reason, ?User $actor = null): Order
    {
        if ($order->isDelivered()) {
            throw ValidationException::withMessages(['order' => 'Pesanan sudah terkirim, tidak bisa dibatalkan.']);
        }

        // Pesanan yang sudah dibayar tapi belum dikirim menyangkut uang pembeli:
        // admin harus mengembalikan dana dulu, bukan sekadar menutup pesanan.
        if ($order->isPaid()) {
            throw ValidationException::withMessages([
                'order' => 'Pesanan sudah lunas. Kembalikan dana lewat menu refund sebelum membatalkan.',
            ]);
        }

        if ($order->isCancelled()) {
            throw ValidationException::withMessages(['order' => 'Pesanan ini sudah dibatalkan sebelumnya.']);
        }

        DB::transaction(function () use ($order, $reason) {
            $this->releaseStock($order);

            $order->forceFill([
                'order_status' => Order::STATUS_CANCELLED,
                'admin_note' => trim(($order->admin_note ? $order->admin_note."\n" : '').'Dibatalkan: '.$reason),
            ])->save();
        });

        ActivityLog::record('order.cancelled', 'Pesanan '.$order->invoice_no.' dibatalkan', $order, ['reason' => $reason], $actor);

        return $order->fresh();
    }

    /** Batalkan semua order pending yang sudah lewat masa bayar. */
    public function expireStaleOrders(): int
    {
        $count = 0;

        Order::query()
            ->unpaid()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->chunkById(50, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    DB::transaction(function () use ($order) {
                        $this->releaseStock($order);
                        $order->forceFill(['payment_status' => Order::PAYMENT_EXPIRED])->save();
                    });
                    $count++;
                }
            });

        return $count;
    }

    // ------------------------------------------------------------------ stok
    /** Kunci stok untuk sebuah item (tidak menandai terkirim). */
    private function reserveStock(OrderItem $item, Variant $variant, int $qty): int
    {
        if (! $variant->requiresStock()) {
            return 0;
        }

        $ids = StockItem::query()
            ->where('variant_id', $variant->id)
            ->where('status', StockItem::STATUS_AVAILABLE)
            ->orderBy('id')
            ->limit($qty)
            ->lockForUpdate()
            ->pluck('id');

        if ($ids->count() < $qty) {
            throw ValidationException::withMessages([
                'cart' => 'Stok '.$variant->name.' tidak mencukupi saat checkout.',
            ]);
        }

        StockItem::query()->whereIn('id', $ids)->update([
            'status' => StockItem::STATUS_RESERVED,
            'order_item_id' => $item->id,
            'updated_at' => now(),
        ]);

        return $ids->count();
    }

    /** Kembalikan stok yang masih direservasi milik order ini. */
    public function releaseStock(Order $order): int
    {
        $itemIds = $order->items()->pluck('id');

        if ($itemIds->isEmpty()) {
            return 0;
        }

        return StockItem::query()
            ->whereIn('order_item_id', $itemIds)
            ->where('status', StockItem::STATUS_RESERVED)
            ->update([
                'status' => StockItem::STATUS_AVAILABLE,
                'order_item_id' => null,
                'updated_at' => now(),
            ]);
    }

    private function defaultInstruction(Variant $variant): string
    {
        return match ($variant->delivery_type) {
            Variant::DELIVERY_AUTO => 'Akun dikirim otomatis setelah pembayaran terverifikasi.',
            Variant::DELIVERY_FILE => 'Tautan unduhan tersedia di halaman ini setelah pembayaran.',
            default => 'Silakan hubungi admin untuk klaim pesanan Anda dengan menyertakan nomor invoice.',
        };
    }

    /**
     * Cocokkan jawaban pembeli dengan label yang diminta varian, berdasarkan
     * urutan. Label datang dari server, jadi klien tidak bisa menitipkan label
     * buatan sendiri — hanya posisi nilai yang dipakai.
     *
     * @param  array<int|string, mixed>  $submitted
     * @return array<string, string>
     */
    private function collectRequiredInfo(Variant $variant, array $submitted): array
    {
        $labels = $variant->requiredFieldLabels();

        if ($labels === []) {
            return [];
        }

        $values = array_values($submitted);
        $out = [];
        $missing = [];

        foreach ($labels as $i => $label) {
            $value = trim((string) ($values[$i] ?? ''));

            if ($value === '') {
                $missing[] = $label;

                continue;
            }

            $out[$label] = mb_substr($value, 0, 160);
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'required_info' => 'Lengkapi "'.implode('", "', $missing).'" untuk '.$variant->name.'.',
            ]);
        }

        return $out;
    }
}
