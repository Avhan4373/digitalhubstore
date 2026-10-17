<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'product_id', 'variant_id', 'product_name', 'variant_name',
        'unit_price', 'qty', 'subtotal', 'delivery_instruction', 'is_fulfilled',
        'cost_price', 'supplier_name', 'supplier_sku', 'supplier_url', 'required_info',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'qty' => 'integer',
            'subtotal' => 'integer',
            'is_fulfilled' => 'boolean',
            'cost_price' => 'integer',
            'supplier_name' => 'string',
            'supplier_sku' => 'string',
            'supplier_url' => 'string',
            'required_info' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(OrderDelivery::class);
    }

    /** Isi yang diterima pembeli (dekripsi payload). */
    public function deliveredContents(): array
    {
        return $this->deliveries
            ->map(fn (OrderDelivery $d) => $d->contentPlain())
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Data yang diminta ke pembeli (label => nilai), mis. ["Player ID" => "123"].
     *
     * @return array<string, string>
     */
    public function requiredInfoPairs(): array
    {
        $pairs = [];

        foreach ($this->required_info ?? [] as $label => $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $pairs[(string) $label] = $value;
            }
        }

        return $pairs;
    }

    /** Total harga modal untuk baris ini (hasil pembelian di supplier). */
    public function costTotal(): int
    {
        return $this->cost_price * $this->qty;
    }

    /** Margin kotor baris ini: subtotal - modal. */
    public function margin(): int
    {
        return $this->subtotal - $this->costTotal();
    }

    public function hasSupplier(): bool
    {
        return filled($this->supplier_name) || filled($this->supplier_url) || filled($this->supplier_sku);
    }

    public function setDeliveryInstructionAttribute($value): void
    {
        // instruksi pengiriman bisa memuat tautan privat -> simpan terenkripsi
        $this->attributes['delivery_instruction'] = $value === null ? null : Crypt::encryptString((string) $value);
    }

    public function deliveryInstructionPlain(): ?string
    {
        $raw = $this->attributes['delivery_instruction'] ?? null;
        if (! $raw) {
            return null;
        }

        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
