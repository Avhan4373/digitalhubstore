<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

/**
 * Pengelola daftar invoice milik pembeli (pengganti `localStorage` situs asal),
 * dipisah menjadi DUA tingkat supaya status pesanan dan ISI pesanan tidak
 * punya syarat akses yang sama:
 *
 * - `my_orders`     : invoice yang boleh dibuka halaman statusnya. Isinya
 *                     invoice yang dibuat di browser ini, atau yang lolos
 *                     verifikasi invoice + nomor WhatsApp di halaman lacak.
 * - `order_unlocked`: invoice yang ISI-nya (kredensial akun, data wajib
 *                     pembeli) boleh ditampilkan.
 *
 * Kenapa dipisah: satu session browser bisa dipakai bergantian (komputer
 * warnet, HP keluarga). Kalau keduanya disatukan, siapa pun yang memakai
 * browser itu cukup membuka "Riwayat Pesanan" untuk melihat akun milik
 * pembeli sebelumnya — tanpa tahu apa pun. Dengan pemisahan ini, halaman
 * status tetap boleh dilihat (nama, status, total) tetapi isi akun hanya
 * terkirim ke browser setelah nomor WhatsApp pesanan diverifikasi ulang.
 *
 * Hasil verifikasi juga BERWAKTU (`UNLOCK_TTL`): setelah tenggat lewat,
 * `order_unlocked` otomatis terkunci lagi. Jadi sesi browser yang ditinggal
 * (checkout/verifikasi lama dilupakan) tidak membuka isi akun pembeli sesudahnya.
 */
class OrderAccess
{
    private const MINE = 'my_orders';

    private const UNLOCKED = 'order_unlocked';

    /** Jumlah invoice yang disimpan per daftar (yang terbaru dulu). */
    public const MAX_KEPT = 25;

    /** Isi akun boleh terbuka selama 10 menit setelah verifikasi WhatsApp. */
    public const UNLOCK_TTL = 600;

    /** Catat invoice sebagai milik browser ini (belum membuka isinya). */
    public function remember(string $invoice): void
    {
        Session::put(self::MINE, $this->push((array) Session::get(self::MINE, []), $invoice));
    }

    /** Catat invoice sebagai milik browser ini SEKALIGUS buka isinya (berwaktu). */
    public function unlock(string $invoice): void
    {
        $this->remember($invoice);

        $map = $this->unlockedMap();
        $map[$this->normalize($invoice)] = now()->timestamp;

        Session::put(self::UNLOCKED, $map);
    }

    /** Boleh dibuka halaman statusnya? */
    public function has(string $invoice): bool
    {
        return in_array($this->normalize($invoice), $this->invoices(), true);
    }

    /** Boleh ditampilkan isi pesanannya (akun/kredensial)? */
    public function isUnlocked(string $invoice): bool
    {
        $masa = $this->unlockedMap()[$this->normalize($invoice)] ?? null;

        if ($masa === null || ! is_numeric($masa)) {
            return false;
        }

        return (now()->timestamp - (int) $masa) <= self::UNLOCK_TTL;
    }

    /** @return array<int,string> */
    public function invoices(): array
    {
        return $this->list(self::MINE);
    }

    /** @return array<int,string> invoice yang masih dalam masa buka. */
    public function unlockedInvoices(): array
    {
        return array_keys(array_filter($this->unlockedMap(), fn ($masa) => is_numeric($masa)
            && (now()->timestamp - (int) $masa) <= self::UNLOCK_TTL));
    }

    /** @return Collection<int,Order> */
    public function orders(int $limit = 10): Collection
    {
        $invoices = $this->invoices();

        if ($invoices === []) {
            return collect();
        }

        return Order::query()
            ->whereIn('invoice_no', $invoices)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    private function normalize(string $invoice): string
    {
        return strtoupper(trim($invoice));
    }

    /** @return array<string,mixed> invoice => timestamp verifikasi. */
    private function unlockedMap(): array
    {
        $items = (array) Session::get(self::UNLOCKED, []);
        $map = [];

        foreach ($items as $invoice => $masa) {
            // Cache versi lama array string: anggap terkunci (wajib verifikasi ulang).
            if (! is_int($invoice) && ! is_string($invoice)) {
                continue;
            }

            $kunci = $this->normalize((string) $invoice);

            if ($kunci !== '' && is_numeric($masa)) {
                $map[$kunci] = (int) $masa;
            }
        }

        return $map;
    }

    /** @return array<int,string> */
    private function list(string $key): array
    {
        $items = (array) Session::get($key, []);

        return array_values(array_filter(array_map(
            fn ($item) => is_string($item) ? $this->normalize($item) : '',
            $items
        )));
    }

    /**
     * @param  array<int,mixed>  $list
     * @return array<int,string>
     */
    private function push(array $list, string $invoice): array
    {
        array_unshift($list, $this->normalize($invoice));

        $unique = array_values(array_unique(array_filter(
            array_map(fn ($item) => is_string($item) ? $this->normalize($item) : '', $list)
        )));

        return array_slice($unique, 0, self::MAX_KEPT);
    }
}
