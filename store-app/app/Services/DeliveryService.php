<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderItem;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

/**
 * Pengiriman produk setelah pembayaran terverifikasi.
 *
 * - varian `auto`   : ambil stok yang sudah direservasi, tandai delivered,
 *                     simpan salinan isi ke order_deliveries (terenkripsi)
 * - varian `file`   : kirim tautan unduhan
 * - varian `manual` : order tetap `processing` sampai admin menyelesaikan
 *                     pengiriman manual lewat WhatsApp (tidak bisa otomatis)
 */
class DeliveryService
{
    /** @return array{delivered: int, manual: int, waiting_stock: int} */
    public function attemptFulfill(Order $order, ?User $actor = null): array
    {
        $stats = ['delivered' => 0, 'manual' => 0, 'waiting_stock' => 0];

        if (! $order->isPaid()) {
            return $stats;
        }

        DB::transaction(function () use ($order, &$stats) {
            foreach ($order->items()->with('variant')->get() as $item) {
                if ($item->is_fulfilled) {
                    continue;
                }

                $variant = $item->variant;
                $type = $variant?->delivery_type ?? Variant::DELIVERY_MANUAL;

                $result = match ($type) {
                    Variant::DELIVERY_AUTO => $this->deliverFromStock($item, $stats),
                    Variant::DELIVERY_FILE => $this->deliverLinks($item, $stats),
                    default => 'manual',
                };

                if ($result === 'manual') {
                    $stats['manual']++;
                }
            }

            $order->refresh();
            $this->syncOrderStatus($order);
        });

        ActivityLog::record('order.fulfilled', 'Pengiriman '.$order->invoice_no.' diproses', $order, $stats, $actor);

        return $stats;
    }

    /** Admin menandai pengiriman manual selesai. */
    public function completeManually(Order $order, OrderItem $item, string $content, ?User $actor = null): void
    {
        DB::transaction(function () use ($order, $item, $content) {
            $delivery = new OrderDelivery([
                'order_item_id' => $item->id,
                'source' => OrderDelivery::SOURCE_MANUAL,
                'delivered_at' => now(),
            ]);
            $delivery->setContentPlain($content);
            $delivery->save();

            // kalau admin melampirkan akun, pakai stok yang direservasi
            $this->consumeReservedStock($item);

            $item->forceFill(['is_fulfilled' => true])->save();

            $order->refresh();
            $this->syncOrderStatus($order);
        });

        ActivityLog::record('order.item.manual', 'Item '.$item->id.' dikirim manual', $order, [], $actor);
    }

    // ------------------------------------------------------------- pengiriman
    /** @param array<string,int> $stats */
    private function deliverFromStock(OrderItem $item, array &$stats): string
    {
        $reserved = StockItem::query()
            ->where('order_item_id', $item->id)
            ->where('status', StockItem::STATUS_RESERVED)
            ->orderBy('id')
            ->get();

        if ($reserved->count() < $item->qty) {
            // kurang stok -> biarkan order tertahan, admin akan diberi tahu
            $stats['waiting_stock']++;

            return 'manual';
        }

        $payloads = [];

        foreach ($reserved as $stock) {
            $plain = $stock->payloadPlain();

            if ($plain === null) {
                continue; // key berubah / data rusak: jangan kirim ciphertext
            }

            $delivery = new OrderDelivery([
                'order_item_id' => $item->id,
                'stock_item_id' => $stock->id,
                'source' => OrderDelivery::SOURCE_STOCK,
                'delivered_at' => now(),
            ]);
            $delivery->setContentPlain($plain);
            $delivery->save();

            $stock->forceFill([
                'status' => StockItem::STATUS_DELIVERED,
                'delivered_at' => now(),
            ])->save();

            $payloads[] = $plain;
        }

        if ($payloads === []) {
            $stats['waiting_stock']++;

            return 'manual';
        }

        $item->forceFill(['is_fulfilled' => true])->save();
        $stats['delivered']++;

        return 'auto';
    }

    /** @param array<string,int> $stats */
    private function deliverLinks(OrderItem $item, array &$stats): string
    {
        $links = $item->variant?->delivery_links ?? [];

        if ($links === []) {
            return 'manual';
        }

        $content = "Tautan unduhan:\n".implode("\n", $links);
        if ($item->variant?->delivery_instruction) {
            $content .= "\n\n".$item->variant->delivery_instruction;
        }

        $delivery = new OrderDelivery([
            'order_item_id' => $item->id,
            'source' => OrderDelivery::SOURCE_LINK,
            'delivered_at' => now(),
        ]);
        $delivery->setContentPlain($content);
        $delivery->save();

        $this->consumeReservedStock($item);
        $item->forceFill(['is_fulfilled' => true])->save();
        $stats['delivered']++;

        return 'file';
    }

    private function consumeReservedStock(OrderItem $item): void
    {
        StockItem::query()
            ->where('order_item_id', $item->id)
            ->where('status', StockItem::STATUS_RESERVED)
            ->update([
                'status' => StockItem::STATUS_DELIVERED,
                'delivered_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function syncOrderStatus(Order $order): void
    {
        $items = $order->items()->get();
        $total = $items->count();
        $done = $items->where('is_fulfilled', true)->count();

        if ($total === 0 || $done === 0) {
            return;
        }

        if ($done >= $total) {
            $order->forceFill([
                'order_status' => Order::STATUS_DELIVERED,
                'delivered_at' => $order->delivered_at ?? now(),
            ])->save();

            return;
        }

        // Sebagian saja: tetap "Diproses" supaya tidak salah dibaca sebagai selesai.
        $order->forceFill([
            'order_status' => Order::STATUS_PROCESSING,
            'admin_note' => trim(
                ($order->admin_note ? $order->admin_note."\n" : '')
                .'Sebagian item belum terkirim ('.($total - $done).' dari '.$total.').'
            ),
        ])->save();
    }
}
