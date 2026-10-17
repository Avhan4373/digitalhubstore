<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'label', 'is_secret'];

    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }

    public const CACHE_KEY = 'settings.all';

    /**
     * Daftar kunci pengaturan bawaan (group, key, value, type, label, secret).
     * Dipakai `SettingSeeder` dan `SettingController::edit()` supaya kunci baru
     * ikut muncul di instalasi lama tanpa perlu `migrate:fresh --seed`.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    public static function kunciBawaan(): array
    {
        return [
            ['general', 'store_name', 'Creative Solutions', 'string', 'Nama toko', false],
            ['general', 'store_tagline', 'Akun premium resmi, bergaransi penuh', 'string', 'Tagline', false],
            ['general', 'store_whatsapp', '6282243890517', 'string', 'Nomor WhatsApp admin', false],
            ['general', 'store_email', '', 'string', 'Email toko', false],
            ['general', 'store_logo', '', 'file', 'Logo toko (kosong = monogram nama toko)', false],
            ['general', 'store_banner', '', 'file', 'Banner promo halaman depan', false],

            ['payment', 'payment_mode', 'both', 'string', 'Mode pembayaran (manual/gateway/both)', false],
            ['payment', 'qris_image', '', 'file', 'Gambar QRIS statis', false],
            ['payment', 'qris_holder', '', 'string', 'Nama pemilik QRIS', false],
            ['payment', 'order_ttl_minutes', '60', 'int', 'Batas waktu bayar (menit)', false],

            ['gateway', 'tripay_mode', 'sandbox', 'string', 'Mode Tripay (sandbox/production)', false],
            ['gateway', 'tripay_merchant_code', '', 'string', 'Tripay merchant code', false],
            ['gateway', 'tripay_api_key', '', 'string', 'Tripay API key', true],
            ['gateway', 'tripay_private_key', '', 'string', 'Tripay private key', true],
            ['gateway', 'tripay_channel', 'QRIS', 'string', 'Kanal default (QRIS/BRIVA/...)', false],

            ['storefront', 'auto_approve_reviews', '0', 'bool', 'Setujui ulasan otomatis', false],
            ['storefront', 'show_sold_count', '1', 'bool', 'Tampilkan angka terjual', false],
            ['storefront', 'show_social_proof', '1', 'bool', 'Tampilkan notifikasi pembelian terbaru', false],
        ];
    }

    public static function all_cached(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return self::query()->pluck('value', 'key')->all();
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $raw = self::all_cached()[$key] ?? null;

        if ($raw === null) {
            return $default;
        }

        $row = self::query()->where('key', $key)->first();

        if ($row?->is_secret) {
            try {
                return Crypt::decryptString($raw);
            } catch (\Throwable) {
                return $default;
            }
        }

        return match ($row?->type) {
            'bool' => (bool) $raw,
            'int' => (int) $raw,
            'json' => json_decode($raw, true) ?? $default,
            default => $raw,
        };
    }

    public static function put(string $key, mixed $value, string $type = 'string', string $group = 'general', ?string $label = null, bool $secret = false): void
    {
        $encoded = match (true) {
            $type === 'json' && ! is_string($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };

        if ($secret && $encoded !== '') {
            $encoded = Crypt::encryptString($encoded);
        }

        self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $encoded, 'type' => $type, 'group' => $group, 'label' => $label, 'is_secret' => $secret]
        );

        Cache::forget(self::CACHE_KEY);
    }

    public static function forget(string $key): void
    {
        self::query()->where('key', $key)->delete();
        Cache::forget(self::CACHE_KEY);
    }

    // ------------------------------------------------------ helper terketik
    public static function storeName(): string
    {
        return (string) self::get('store_name', config('app.name'));
    }

    /**
     * Monogram logo (maks 2 huruf) dari nama toko, dipakai saat admin belum
     * mengunggah `store_logo`. "Creative Solutions" -> "CS", "Toko" -> "TO".
     */
    public static function logoMonogram(): string
    {
        $kata = preg_split('/\s+/u', trim(self::storeName()), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $huruf = match (true) {
            count($kata) >= 2 => mb_substr($kata[0], 0, 1).mb_substr($kata[1], 0, 1),
            count($kata) === 1 => mb_substr($kata[0], 0, 2),
            default => 'ST',
        };

        return mb_strtoupper($huruf);
    }

    /**
     * Nama toko dipecah untuk wordmark header: kata terakhir diberi warna brand.
     * "Creative Solutions" -> ['Creative', 'Solutions'].
     *
     * @return array{0: string, 1: string} [awalan, kata terakhir]
     */
    public static function logoWordmark(): array
    {
        $kata = preg_split('/\s+/u', trim(self::storeName()), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($kata) < 2) {
            return ['', $kata[0] ?? 'Toko'];
        }

        $terakhir = array_pop($kata);

        return [implode(' ', $kata), $terakhir];
    }

    public static function whatsappNumber(): string
    {
        return (string) self::get('store_whatsapp', '');
    }

    /**
     * Logo toko: unggahan admin kalau ada, kalau kosong memakai gambar bawaan
     * `public/images/logo.png`. Untuk wordmark/monogram di UI pakai
     * `logoMonogram()` supaya ikut nama toko.
     */
    public static function logoUrl(): string
    {
        $path = (string) self::get('store_logo', '');

        return match (true) {
            $path === '' => asset('images/logo.png'),
            str_starts_with($path, 'http://'), str_starts_with($path, 'https://') => $path,
            default => storage_url($path),
        };
    }

    /** Apakah admin sudah mengunggah logo sendiri. */
    public static function hasCustomLogo(): bool
    {
        return (string) self::get('store_logo', '') !== '';
    }

    public static function whatsappLink(?string $message = null): string
    {
        $number = preg_replace('/\D/', '', self::whatsappNumber());

        return $number === ''
            ? '#'
            : 'https://wa.me/'.$number.($message ? '?text='.rawurlencode($message) : '');
    }

    /** Banner promo halaman depan (opsional). */
    public static function bannerUrl(): ?string
    {
        $path = (string) self::get('store_banner', '');

        if ($path === '') {
            return null;
        }

        return storage_url($path);
    }

    public static function qrisImageUrl(): ?string
    {
        $path = (string) self::get('qris_image', '');

        return storage_url($path);
    }

    public static function paymentMode(): string
    {
        return (string) self::get('payment_mode', 'both'); // manual | gateway | both
    }

    public static function gatewayEnabled(): bool
    {
        return in_array(self::paymentMode(), ['gateway', 'both'], true)
            && (string) self::get('tripay_api_key', '') !== ''
            && (string) self::get('tripay_private_key', '') !== '';
    }

    public static function manualEnabled(): bool
    {
        return in_array(self::paymentMode(), ['manual', 'both'], true);
    }

    public static function orderTtlMinutes(): int
    {
        return max(5, (int) self::get('order_ttl_minutes', 60));
    }
}
