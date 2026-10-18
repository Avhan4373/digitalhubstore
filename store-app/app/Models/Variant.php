<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Variant extends Model
{
    use HasFactory;

    public const DELIVERY_MANUAL = 'manual';
    public const DELIVERY_AUTO = 'auto';
    public const DELIVERY_FILE = 'file';

    protected $fillable = [
        'product_id', 'external_id', 'name', 'price', 'original_price', 'stock_original',
        'delivery_type', 'delivery_instruction', 'delivery_links', 'description',
        'terms', 'payment_type', 'is_active', 'sort_order',
        'supplier_name', 'supplier_sku', 'supplier_url', 'cost_price', 'required_fields',
        // tampilan nama khusus bot WA (kosong = pakai `name`)
        'bot_label',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'original_price' => 'integer',
            'stock_original' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'delivery_links' => 'array',
            'cost_price' => 'integer',
            'required_fields' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    public function availableStockItems(): HasMany
    {
        return $this->stockItems()->where('status', StockItem::STATUS_AVAILABLE);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ------------------------------------------------------------------ logic
    /**
     * Butuh stok fisik? Hanya mode `auto`. Mode manual/file tidak dibatasi
     * inventaris sehingga nilainya `null` (tak terbatas).
     */
    public function requiresStock(): bool
    {
        return $this->delivery_type === self::DELIVERY_AUTO;
    }

    public function availableStock(): int
    {
        if (! $this->requiresStock()) {
            return 0;
        }

        if ($this->relationLoaded('availableStockItems')) {
            return $this->availableStockItems->count();
        }

        return (int) $this->availableStockItems()->count();
    }

    public function isPurchasable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return ! $this->requiresStock() || $this->availableStock() > 0;
    }

    public function hasDiscount(): bool
    {
        return $this->original_price > 0 && $this->original_price > $this->price;
    }

    public function discountPercent(): int
    {
        if (! $this->hasDiscount()) {
            return 0;
        }

        return (int) round((1 - $this->price / $this->original_price) * 100);
    }

    public function deliveryLabel(): string
    {
        return match ($this->delivery_type) {
            self::DELIVERY_AUTO => 'Kirim otomatis',
            self::DELIVERY_FILE => 'Unduh file/link',
            default => 'Kirim via admin',
        };
    }

    // ---------------------------------------------------- reseller / supplier
    /** Ada referensi produk di supplier (mis. itemku) untuk dibeli manual? */
    public function hasSupplier(): bool
    {
        return filled($this->supplier_name) || filled($this->supplier_url) || filled($this->supplier_sku);
    }

    /** Margin kotor per unit: harga jual - harga modal. */
    public function margin(): int
    {
        return $this->price - $this->cost_price;
    }

    public function marginPercent(): int
    {
        if ($this->price <= 0) {
            return 0;
        }

        return (int) round($this->margin() / $this->price * 100);
    }

    /**
     * Label data yang wajib diminta ke pembeli, mis. ["Player ID", "Zone ID"].
     *
     * @return list<string>
     */
    public function requiredFieldLabels(): array
    {
        return collect($this->required_fields ?? [])
            ->map(fn ($label) => trim((string) $label))
            ->filter()
            ->values()
            ->all();
    }
}
