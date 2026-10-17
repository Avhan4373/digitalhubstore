<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_EXPIRED = 'expired';

    public const PAYMENT_FAILED = 'failed';

    public const PAYMENT_REFUNDED = 'refunded';

    public const STATUS_NEW = 'new';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const METHOD_QRIS = 'qris_manual';

    public const METHOD_TRIPAY = 'tripay';

    protected $fillable = [
        'invoice_no', 'buyer_wa', 'buyer_name', 'buyer_email', 'buyer_note',
        'subtotal', 'discount', 'total', 'coupon_id', 'coupon_code',
        'payment_method', 'payment_status', 'order_status', 'gateway_reference',
        'gateway_payload', 'paid_at', 'delivered_at', 'expires_at', 'ip',
        'user_agent', 'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'gateway_payload' => 'array',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'invoice_no';
    }

    // ------------------------------------------------------------- relations
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    // ------------------------------------------------------------------ scope
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('payment_status', self::PAYMENT_PAID);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        // Pesanan yang sudah dibatalkan admin tidak boleh ikut dihitung sebagai
        // "belum bayar" — kalau tidak, notifikasi & pill admin terus menyala
        // untuk pesanan yang sudah ditutup.
        return $query->where('payment_status', self::PAYMENT_PENDING)
            ->where('order_status', '!=', self::STATUS_CANCELLED);
    }

    public function scopeAwaitingDelivery(Builder $query): Builder
    {
        return $query->where('payment_status', self::PAYMENT_PAID)
            ->whereIn('order_status', [self::STATUS_NEW, self::STATUS_PROCESSING]);
    }

    public function scopeForBuyer(Builder $query, string $whatsapp): Builder
    {
        return $query->where('buyer_wa', $whatsapp);
    }

    // ------------------------------------------------------------------ state
    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    public function isDelivered(): bool
    {
        return in_array($this->order_status, [self::STATUS_DELIVERED, self::STATUS_COMPLETED], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast() && ! $this->isPaid();
    }

    /** Pesanan dibatalkan admin — tidak boleh lagi ditawari pembayaran. */
    public function isCancelled(): bool
    {
        return $this->order_status === self::STATUS_CANCELLED;
    }

    /**
     * Masih bisa dibayar? Hanya pesanan yang belum lunas, belum dibatalkan,
     * dan belum lewat batas waktu. Dipakai halaman pesanan & kasir supaya
     * pesanan yang sudah dibatalkan/kedaluwarsa tidak menampilkan QRIS dan
     * hitung mundur yang menyesatkan.
     */
    public function bisaDibayar(): bool
    {
        return ! $this->isPaid()
            && ! $this->isCancelled()
            && $this->payment_status === self::PAYMENT_PENDING
            && ! $this->isExpired();
    }

    public function canBeDelivered(): bool
    {
        return $this->isPaid() && ! $this->isDelivered();
    }

    /**
     * Label status pembayaran untuk ditampilkan. Pesanan yang dibatalkan admin
     * selalu tampil "Dibatalkan" walau `payment_status` masih `pending` —
     * kalau tidak, baris pesanan menampilkan "Menunggu pembayaran" +
     * "Dibatalkan" sekaligus dan membingungkan admin/pembeli.
     */
    public function paymentLabel(): string
    {
        if ($this->isCancelled()) {
            return 'Dibatalkan';
        }

        return match ($this->payment_status) {
            self::PAYMENT_PAID => 'Lunas',
            self::PAYMENT_EXPIRED => 'Kedaluwarsa',
            self::PAYMENT_FAILED => 'Gagal',
            self::PAYMENT_REFUNDED => 'Dana dikembalikan',
            default => 'Menunggu pembayaran',
        };
    }

    /** Kelas warna badge untuk label pembayaran di atas. */
    public function paymentBadge(): string
    {
        return status_badge($this->isCancelled() ? self::STATUS_CANCELLED : $this->payment_status);
    }

    public function statusLabel(): string
    {
        return match ($this->order_status) {
            self::STATUS_PROCESSING => 'Diproses',
            self::STATUS_DELIVERED => 'Terkirim',
            self::STATUS_COMPLETED => 'Selesai',
            self::STATUS_CANCELLED => 'Dibatalkan',
            default => 'Baru',
        };
    }

    public function itemCount(): int
    {
        return (int) $this->items->sum('qty');
    }

    public static function newInvoiceNumber(): string
    {
        do {
            $invoice = 'INV'.now()->format('ymd').Str::upper(Str::random(5));
        } while (static::withTrashed()->where('invoice_no', $invoice)->exists());

        return $invoice;
    }

    /**
     * Tautan wa.me ke nomor WhatsApp pembeli berisi draft pengiriman isi akun —
     * dibuka admin untuk mengirim kredensial langsung ke yang memesan.
     * Null kalau tidak ada nomor WA atau belum ada isi akun yang terkirim.
     */
    public function buyerWhatsAppLink(): ?string
    {
        $digits = wa_normalize($this->buyer_wa);

        if ($digits === '') {
            return null;
        }

        $draft = $this->kirimIsiAkunDraft();

        if ($draft === null) {
            return null;
        }

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($draft);
    }

    /** Draft pesan WhatsApp isi akun; null kalau semua item belum terkirim. */
    private function kirimIsiAkunDraft(): ?string
    {
        $lines = [
            'Halo '.$this->buyer_name.', pengiriman pesanan Anda sudah selesai.',
            '',
            'No. Invoice: '.$this->invoice_no,
        ];

        $adaIsi = false;

        foreach ($this->items as $item) {
            $lines[] = '';
            $lines[] = $item->qty.' × '.$item->product_name.' ('.$item->variant_name.')';

            $isi = $item->deliveredContents();

            if ($isi === []) {
                continue;
            }

            $adaIsi = true;
            $lines[] = 'Isi Pesanan Anda:';

            foreach ($isi as $baris) {
                $lines[] = $baris;
            }
        }

        if (! $adaIsi) {
            return null;
        }

        $lines[] = '';
        $lines[] = 'Terima kasih sudah memesan di '.Setting::storeName().'!';

        return implode("\n", $lines);
    }
}
