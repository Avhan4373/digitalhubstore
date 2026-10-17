<?php

namespace Tests\Feature\Concerns;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Variant;

/**
 * Helper pembuat data katalog untuk test.
 *
 * Dibuat manual (bukan factory) supaya kolom yang diuji terlihat eksplisit
 * dan test tetap terbaca sebagai dokumentasi perilaku.
 */
trait BuildsStore
{
    protected function makeProduct(array $overrides = []): Product
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'kategori-uji'],
            ['name' => 'Kategori Uji', 'is_active' => true]
        );

        static $seq = 0;
        $seq++;

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Produk Uji '.$seq,
            'slug' => 'produk-uji-'.$seq,
            'description' => 'Deskripsi produk uji.',
            'is_active' => true,
            'sold_count' => 0,
        ], $overrides));
    }

    protected function makeVariant(Product $product, array $overrides = []): Variant
    {
        return Variant::create(array_merge([
            'product_id' => $product->id,
            'name' => '1 Bulan',
            'price' => 10000,
            'delivery_type' => Variant::DELIVERY_MANUAL,
            'delivery_instruction' => 'Chat admin dengan nomor invoice.',
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    /** @param list<string> $payloads */
    protected function fillStock(Variant $variant, array $payloads): void
    {
        foreach ($payloads as $payload) {
            (new StockItem([
                'variant_id' => $variant->id,
                'status' => StockItem::STATUS_AVAILABLE,
            ]))->setPayloadPlain($payload)->save();
        }
    }

    protected function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'HEMAT10',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 10,
            'min_amount' => 0,
            'max_discount' => 0,
            'usage_limit' => 0,
            'per_buyer_limit' => 1,
            'is_active' => true,
        ], $overrides));
    }

    protected function adminUser(): User
    {
        return User::create([
            'name' => 'Admin Uji',
            'email' => 'admin-uji@example.test',
            'password' => 'password-uji-panjang',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    protected function staffUser(): User
    {
        return User::create([
            'name' => 'Staff Uji',
            'email' => 'staff-uji@example.test',
            'password' => 'password-uji-panjang',
            'role' => User::ROLE_STAFF,
            'is_active' => true,
        ]);
    }

    /** Payload form checkout yang valid. */
    protected function checkoutPayload(array $overrides = []): array
    {
        return array_merge([
            'buyer_name' => 'Pembeli Uji',
            'buyer_wa' => '081234567890',
            'payment_method' => 'qris_manual',
            'website' => '',
        ], $overrides);
    }
}
