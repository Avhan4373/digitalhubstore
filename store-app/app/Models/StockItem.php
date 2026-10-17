<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class StockItem extends Model
{
    use HasFactory;

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'variant_id', 'payload', 'payload_hash', 'status',
        'order_item_id', 'delivered_at', 'note',
    ];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    // ------------------------------------------------------------------ crypto
    /**
     * Payload akun selalu disimpan terenkripsi. Menimpa atribut mentah dengan
     * helper ini supaya tidak ada jalur yang menulis polos.
     */
    public function setPayloadPlain(string $plain): self
    {
        $this->payload = Crypt::encryptString($plain);
        $this->payload_hash = hash('sha256', $plain);

        return $this;
    }

    public function payloadPlain(): ?string
    {
        if (! $this->payload) {
            return null;
        }

        try {
            return Crypt::decryptString($this->payload);
        } catch (\Throwable) {
            // data lama / key berganti: jangan pernah tampilkan ciphertext
            return null;
        }
    }

    // ------------------------------------------------------------- relations
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AVAILABLE);
    }
}
