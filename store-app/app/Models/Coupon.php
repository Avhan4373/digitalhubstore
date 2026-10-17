<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use HasFactory;

    public const TYPE_FIXED = 'fixed';
    public const TYPE_PERCENT = 'percent';

    protected $fillable = [
        'code', 'description', 'type', 'value', 'min_amount', 'max_discount',
        'usage_limit', 'used_count', 'per_buyer_limit', 'starts_at',
        'expires_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'min_amount' => 'integer',
            'max_discount' => 'integer',
            'usage_limit' => 'integer',
            'used_count' => 'integer',
            'per_buyer_limit' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()));
    }

    /**
     * Hitung potongan untuk sebuah nominal.
     * Potongan TIDAK pernah melebihi nominal belanja (mencegah total negatif).
     */
    public function discountFor(int $amount): int
    {
        if ($amount < $this->min_amount) {
            return 0;
        }

        $discount = match ($this->type) {
            self::TYPE_PERCENT => (int) floor($amount * $this->value / 100),
            default => $this->value,
        };

        if ($this->type === self::TYPE_PERCENT && $this->max_discount > 0) {
            $discount = min($discount, $this->max_discount);
        }

        return max(0, min($discount, $amount));
    }

    public function isExhausted(): bool
    {
        return $this->usage_limit > 0 && $this->used_count >= $this->usage_limit;
    }

    public function usageBy(string $whatsapp): int
    {
        return $this->orders()->where('buyer_wa', $whatsapp)->count();
    }
}
