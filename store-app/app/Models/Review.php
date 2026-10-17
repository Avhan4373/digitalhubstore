<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'product_id', 'order_id', 'external_id', 'user_name', 'rating',
        'comment', 'variant_name', 'image_path', 'status', 'ip', 'admin_note',
    ];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function initial(): string
    {
        return mb_strtoupper(mb_substr($this->user_name !== '' ? $this->user_name : 'P', 0, 1));
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? storage_url($this->image_path) : null;
    }
}
