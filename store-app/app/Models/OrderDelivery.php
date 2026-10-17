<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class OrderDelivery extends Model
{
    use HasFactory;

    public const SOURCE_STOCK = 'stock';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_LINK = 'link';

    protected $fillable = [
        'order_item_id', 'stock_item_id', 'content', 'source', 'delivered_at',
    ];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function setContentPlain(string $plain): self
    {
        $this->content = Crypt::encryptString($plain);

        return $this;
    }

    public function contentPlain(): ?string
    {
        try {
            return Crypt::decryptString($this->content);
        } catch (\Throwable) {
            return null;
        }
    }
}
