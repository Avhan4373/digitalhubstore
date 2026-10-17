<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

/**
 * Keranjang berbasis session.
 *
 * Hanya menyimpan `variant_id => qty`. Harga TIDAK pernah disimpan di session,
 * selalu dibaca ulang dari database saat menghitung total — supaya harga tidak
 * bisa dimanipulasi dari sisi klien (celah yang ada di situs asal, di mana
 * harga dihitung di browser).
 */
class Cart
{
    private const KEY = 'cart.items';

    public const MAX_QTY_PER_ITEM = 20;
    public const MAX_ITEMS = 20;

    /** @return array<int,int> variant_id => qty */
    public function raw(): array
    {
        $items = Session::get(self::KEY, []);

        return is_array($items) ? array_filter(array_map('intval', $items)) : [];
    }

    public function count(): int
    {
        return array_sum($this->raw());
    }

    public function isEmpty(): bool
    {
        return $this->raw() === [];
    }

    /**
     * Isi keranjang lengkap dengan model varian + produk.
     *
     * @return Collection<int, array{variant: Variant, product: Product, qty: int, subtotal: int}>
     */
    public function items(): Collection
    {
        $raw = $this->raw();

        if ($raw === []) {
            return collect();
        }

        $variants = Variant::query()
            ->with('product.category')
            ->whereIn('id', array_keys($raw))
            ->get()
            ->keyBy('id');

        $out = collect();

        foreach ($raw as $variantId => $qty) {
            $variant = $variants->get($variantId);

            // varian/produk dihapus atau dinonaktifkan -> buang dari keranjang
            if (! $variant || ! $variant->is_active || ! $variant->product?->is_active) {
                $this->remove($variantId);
                continue;
            }

            $qty = max(1, min($qty, self::MAX_QTY_PER_ITEM));

            $out->push([
                'variant' => $variant,
                'product' => $variant->product,
                'qty' => $qty,
                'subtotal' => $variant->price * $qty,
            ]);
        }

        return $out;
    }

    public function subtotal(): int
    {
        return (int) $this->items()->sum('subtotal');
    }

    public function totalQty(): int
    {
        return (int) $this->items()->sum('qty');
    }

    public function has(string $itemType, int $id): bool
    {
        $items = $this->items();

        return $items->contains(fn (array $row) => $row['variant']->id === $id);
    }

    /** @return array{ok: bool, message: string} */
    public function add(int $variantId, int $qty = 1): array
    {
        $qty = max(1, min($qty, self::MAX_QTY_PER_ITEM));
        $variant = Variant::query()->with('product')->find($variantId);

        if (! $variant || ! $variant->is_active || ! $variant->product?->is_active) {
            return ['ok' => false, 'message' => 'Produk tidak tersedia.'];
        }

        $raw = $this->raw();

        if (! isset($raw[$variantId]) && count($raw) >= self::MAX_ITEMS) {
            return ['ok' => false, 'message' => 'Keranjang sudah penuh.'];
        }

        if ($variant->price <= 0) {
            return ['ok' => false, 'message' => 'Harga varian ini belum diset admin.'];
        }

        $target = min(($raw[$variantId] ?? 0) + $qty, self::MAX_QTY_PER_ITEM);

        if ($variant->requiresStock()) {
            $available = $variant->availableStock();
            if ($available <= 0) {
                return ['ok' => false, 'message' => 'Stok varian ini sedang kosong.'];
            }
            $target = min($target, $available);
        }

        $raw[$variantId] = $target;
        Session::put(self::KEY, $raw);

        return ['ok' => true, 'message' => $variant->product->name.' — '.$variant->name.' ditambahkan.'];
    }

    public function setQty(int $variantId, int $qty): void
    {
        $raw = $this->raw();

        if ($qty <= 0) {
            unset($raw[$variantId]);
        } else {
            $raw[$variantId] = min($qty, self::MAX_QTY_PER_ITEM);
        }

        Session::put(self::KEY, $raw);
    }

    public function remove(int $variantId): void
    {
        $raw = $this->raw();
        unset($raw[$variantId]);
        Session::put(self::KEY, $raw);
    }

    public function clear(): void
    {
        Session::forget(self::KEY);
        Session::forget('cart.coupon');
    }

    // ------------------------------------------------------------------ kupon
    public function couponCode(): ?string
    {
        return Session::get('cart.coupon');
    }

    public function setCoupon(?string $code): void
    {
        $code === null ? Session::forget('cart.coupon') : Session::put('cart.coupon', $code);
    }
}
