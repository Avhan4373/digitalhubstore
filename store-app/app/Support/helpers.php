<?php

use App\Services\OrderAccess;
use Illuminate\Support\HtmlString;

if (! function_exists('rupiah')) {
    /** Format Rupiah: 25000 -> "Rp 25.000". */
    function rupiah(int|float|string|null $amount, bool $withPrefix = true): string
    {
        $value = (int) round((float) $amount);
        $formatted = number_format($value, 0, ',', '.');

        return $withPrefix ? 'Rp '.$formatted : $formatted;
    }
}

if (! function_exists('rupiah_short')) {
    /** Format ringkas untuk kartu statistik: 1250000 -> "1,3 jt". */
    function rupiah_short(int|float|null $amount): string
    {
        $value = (float) $amount;

        return match (true) {
            $value >= 1_000_000_000 => number_format($value / 1_000_000_000, 1, ',', '.').' M',
            $value >= 1_000_000 => number_format($value / 1_000_000, 1, ',', '.').' jt',
            $value >= 1_000 => number_format($value / 1_000, 0, ',', '.').' rb',
            default => (string) (int) $value,
        };
    }
}

if (! function_exists('sold_label')) {
    /**
     * Angka terjual gaya situs asal: 850 -> "850", 30.000 -> "30RB+".
     * Angka ini berasal dari situs lama dan belum diverifikasi (lihat sold_is_estimate).
     */
    function sold_label(int|float|string|null $count): string
    {
        $value = (int) $count;

        return $value >= 1_000 ? floor($value / 1_000).'RB+' : (string) $value;
    }
}

if (! function_exists('mask_name')) {
    /** Nama ulasan disamarkan seperti situs asal: "Antonius" -> "a***s". */
    function mask_name(?string $name): string
    {
        $name = trim((string) $name);
        $length = mb_strlen($name);

        return match (true) {
            $length === 0 => 'Pembeli',
            $length <= 2 => mb_strtolower(mb_substr($name, 0, 1)).'***',
            default => mb_strtolower(mb_substr($name, 0, 1)).'***'.mb_strtolower(mb_substr($name, -1)),
        };
    }
}

if (! function_exists('avatar_classes')) {    /** Warna bulat avatar ulasan, dipilih stabil dari nama. */
    function avatar_classes(?string $name): string
    {
        $palette = [
            'bg-blue-100 text-blue-600',
            'bg-rose-100 text-rose-600',
            'bg-emerald-100 text-emerald-600',
            'bg-amber-100 text-amber-600',
            'bg-violet-100 text-violet-600',
        ];

        return $palette[abs(crc32(mb_strtolower(trim((string) $name)))) % count($palette)];
    }
}

if (! function_exists('mask_buyer_name')) {
    /** Nama pembeli untuk notifikasi publik: "Budi Santoso" -> "Budi S.". */
    function mask_buyer_name(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));

        if ($parts === []) {
            return 'Pembeli';
        }

        $first = mb_convert_case($parts[0], MB_CASE_TITLE);

        return count($parts) === 1
            ? $first
            : $first.' '.mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)).'.';
    }
}

if (! function_exists('storage_url')) {
    /**
     * URL berkas di disk `public`.
     *
     * Sengaja memakai `asset('storage/...')` (relatif ke request/`ASSET_URL`)
     * alih-alih `Storage::url()`, karena `Storage::url()` selalu memakai
     * `config('app.url')` — gambar jadi rusak kalau aplikasi diakses dari
     * domain/port yang berbeda dari `APP_URL` (mis. dev di port lain).
     */
    function storage_url(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset('storage/'.ltrim($path, '/'));
    }
}

if (! function_exists('wa_normalize')) {
    /**
     * Normalisasi nomor WhatsApp Indonesia ke format 62xxxx.
     * Menerima "0822-4389-0517", "+62 822 4389 0517", "6282243890517".
     */
    function wa_normalize(?string $number): string
    {
        $digits = preg_replace('/\D/', '', (string) $number);

        if ($digits === '') {
            return '';
        }

        return match (true) {
            str_starts_with($digits, '62') => $digits,
            str_starts_with($digits, '0') => '62'.substr($digits, 1),
            str_starts_with($digits, '8') => '62'.$digits,
            default => $digits,
        };
    }
}

if (! function_exists('order_access')) {
    /**
     * Daftar invoice milik pembeli di session (lihat App\Services\OrderAccess).
     *
     * Dipakai view supaya tidak membaca `session('my_orders')` langsung —
     * daftar itu punya aturan sendiri (lihat OrderAccess) dan terpisah dari
     * daftar invoice yang ISI-nya boleh dibuka.
     */
    function order_access(): OrderAccess
    {
        return app(OrderAccess::class);
    }
}

if (! function_exists('mask_email')) {
    /**
     * Email untuk ditampilkan umum: "arlan.siman1996@gmail.com" -> "arl***@gmail.com".
     *
     * Dipakai lembar E-Invoice yang bisa dibuka hanya dengan nomor invoice,
     * jadi identitas pembeli tidak perlu tampil utuh di layar siapa pun.
     */
    function mask_email(?string $email): string
    {
        $email = trim((string) $email);

        if ($email === '') {
            return '—';
        }

        if (! str_contains($email, '@')) {
            return '••••••';
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, 3);

        return $visible.str_repeat('*', max(3, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }
}

if (! function_exists('mask_wa')) {
    /**
     * Nomor WhatsApp untuk ditampilkan: awalan + 3 digit terakhir saja.
     *
     * Nomor WhatsApp adalah "kunci" untuk membuka isi pesanan, jadi nomor
     * pembeli tidak boleh tampil utuh di halaman yang bisa dibuka dengan
     * session browser (mis. komputer warnet yang dipakai bergantian).
     */
    function mask_wa(?string $number): string
    {
        $digits = wa_normalize($number);

        if ($digits === '' || strlen($digits) < 8) {
            return '••••••';
        }

        return '+'.substr($digits, 0, 5).'••••'.substr($digits, -3);
    }
}

if (! function_exists('wa_display')) {
    /** 6282243890517 -> "+62 822-4389-0517" (untuk tampilan). */
    function wa_display(?string $number): string
    {
        $digits = wa_normalize($number);

        if ($digits === '' || ! str_starts_with($digits, '62')) {
            return (string) $number;
        }

        $local = substr($digits, 2);
        $parts = str_split($local, 4);

        return '+62 '.implode('-', $parts);
    }
}

if (! function_exists('safe_text')) {
    /**
     * Tampilkan teks apa adanya TANPA pernah mengeksekusi HTML.
     * Dipakai untuk semua input dari pembeli (nama, ulasan) supaya stored XSS
     * seperti yang terjadi di situs asal tidak mungkin terulang.
     */
    function safe_text(?string $value): HtmlString
    {
        return new HtmlString(nl2br(e((string) $value)));
    }
}

if (! function_exists('status_badge')) {
    /** Kelas warna Tailwind untuk sebuah status order/pembayaran. */
    function status_badge(string $status): string
    {
        return match ($status) {
            'paid', 'delivered', 'completed', 'approved' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'pending', 'new', 'processing', 'reserved' => 'bg-amber-50 text-amber-700 ring-amber-200',
            'failed', 'expired', 'rejected', 'cancelled', 'void' => 'bg-rose-50 text-rose-700 ring-rose-200',
            default => 'bg-slate-50 text-slate-700 ring-slate-200',
        };
    }
}
