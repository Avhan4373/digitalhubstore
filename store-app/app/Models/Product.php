<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'category_id', 'external_id', 'name', 'slug', 'description', 'terms',
        'warranty', 'payment_type', 'cover_image', 'is_best_seller', 'is_hot',
        'is_active', 'sold_count', 'sold_is_estimate', 'stock_mode_cache',
        'rating_source', 'view_count', 'meta_title', 'meta_description', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_best_seller' => 'boolean',
            'is_hot' => 'boolean',
            'is_active' => 'boolean',
            'sold_is_estimate' => 'boolean',
            'sold_count' => 'integer',
            'view_count' => 'integer',
            'sort_order' => 'integer',
            'stock_mode_cache' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ------------------------------------------------------------- relations
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activeVariants(): HasMany
    {
        return $this->variants()->where('is_active', true);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function approvedReviews(): HasMany
    {
        return $this->reviews()->where('status', Review::STATUS_APPROVED);
    }

    // ------------------------------------------------------------------ scope
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereHas('activeVariants');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('warranty', 'like', $like);
        });
    }

    public function scopePriceBetween(Builder $query, ?int $min, ?int $max): Builder
    {
        return $query
            ->when($min, fn (Builder $q) => $q->whereHas(
                'activeVariants',
                fn (Builder $v) => $v->where('price', '>=', $min)->where('price', '>', 0)
            ))
            ->when($max, fn (Builder $q) => $q->whereDoesntHave(
                'activeVariants',
                fn (Builder $v) => $v->where('price', '>', $max)
            ));
    }

    /**
     * Urutan katalog. Dipakai bersama halaman depan & halaman produk agar
     * perilakunya sama. Semua ekspresi SQL jalan di MySQL maupun SQLite.
     */
    public function scopeSorted(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'termurah' => $query->orderByRaw(
                '(SELECT MIN(price) FROM variants WHERE variants.product_id = products.id AND variants.is_active = 1) ASC'
            ),
            'termahal' => $query->orderByRaw(
                '(SELECT MAX(price) FROM variants WHERE variants.product_id = products.id AND variants.is_active = 1) DESC'
            ),
            'terbaru' => $query->orderByDesc('created_at'),
            'rating' => $query->withAvg(['approvedReviews' => fn ($q) => $q], 'rating')->orderByDesc('approved_reviews_avg_rating'),
            'terlaris' => $query->orderByDesc('is_best_seller')->orderByDesc('sold_count'),
            // "Paling Sesuai" = urutan katalog apa adanya (sama dengan urutan
            // produk di situs asal), jadi TIDAK ada subquery harga.
            default => $query->orderBy('sort_order')->orderBy('id'),
        };
    }

    // -------------------------------------------------------------- accessors
    public function coverUrl(): ?string
    {
        if (! $this->cover_image) {
            return null;
        }

        return storage_url($this->cover_image);
    }

    public function priceRange(): string
    {
        $min = $this->relationLoaded('activeVariants')
            ? (int) $this->activeVariants->min('price')
            : (int) $this->activeVariants()->min('price');
        $max = $this->relationLoaded('activeVariants')
            ? (int) $this->activeVariants->max('price')
            : (int) $this->activeVariants()->max('price');

        if ($min === 0 && $max === 0) {
            return 'Hubungi admin';
        }

        return $min === $max
            ? rupiah($min)
            : rupiah($min).' – '.rupiah($max);
    }

    public function lowestPrice(): int
    {
        return (int) $this->activeVariants()->min('price');
    }

    public function reviewSummary(): array
    {
        $row = $this->approvedReviews()
            ->selectRaw('COUNT(*) AS total, COALESCE(AVG(rating), 0) AS average')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'average' => round((float) ($row->average ?? 0), 1),
        ];
    }

    /** Stok nyata = jumlah stock_items tersedia. */
    public function realStock(): int
    {
        return (int) StockItem::query()
            ->where('status', StockItem::STATUS_AVAILABLE)
            ->whereIn('variant_id', $this->activeVariants()->select('id'))
            ->count();
    }
}
