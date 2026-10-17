<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Setting;
use App\Services\OrderAccess;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderAccess $access,
    ) {}

    /** Halaman pembayaran sebuah invoice. */
    public function pay(string $invoice)
    {
        $order = $this->findAccessible($invoice);

        return view('storefront.pay', [
            'order' => $order->load('items'),
            'qrisUrl' => Setting::qrisImageUrl(),
            'qrisHolder' => (string) Setting::get('qris_holder', ''),
            'whatsapp' => Setting::whatsappLink($this->whatsappMessage($order)),
        ]);
    }

    public function show(string $invoice)
    {
        $order = $this->findAccessible($invoice);

        return view('storefront.order', [
            'order' => $order->load(['items.deliveries', 'coupon']),
            // Isi pesanan (akun/kredensial) baru dikirim ke browser setelah
            // pembeli memverifikasi nomor WhatsApp-nya di halaman ini.
            'unlocked' => $this->access->isUnlocked($order->invoice_no),
            'whatsapp' => Setting::whatsappLink('Halo admin, saya mau tanya pesanan '.$order->invoice_no),
        ]);
    }

    public function invoice(string $invoice)
    {
        $order = $this->findAccessible($invoice);

        return view('storefront.invoice', ['order' => $order->load('items')]);
    }

    /**
     * Status pesanan untuk polling ringan (dipakai halaman pembayaran dan
     * halaman pesanan). Hanya mengembalikan status & boolean yang memengaruhi
     * susunan halaman — tanpa data pribadi dan tanpa isi akun.
     */
    public function status(string $invoice)
    {
        $order = $this->findAccessible($invoice);

        return response()->json([
            'invoice' => $order->invoice_no,
            'payment_status' => $order->payment_status,
            'order_status' => $order->order_status,
            'is_paid' => $order->isPaid(),
            // Kunci yang dipantau halaman pesanan (polling): kalau isi akun
            // muncul di pesanan ini ATAU statusUnlock berubah, halaman
            // dimuat ulang otomatis supaya pembeli tidak men-refresh manual.
            'punya_isi' => $order->items()->where('is_fulfilled', true)->exists(),
            'terbuka' => $this->access->isUnlocked($order->invoice_no),
        ]);
    }

    /**
     * Buka isi pesanan setelah pembeli memasukkan nomor WhatsApp pesanannya.
     *
     * Halaman status sengaja hanya dijaga session (`my_orders`) supaya pembeli
     * tidak diminta login. Karena session browser bisa dipakai bergantian,
     * kredensial akun TIDAK ikut terkirim di halaman itu — pembeli harus
     * membuktikan tahu nomor WhatsApp-nya dulu di sini, dengan pembatasan
     * percobaan supaya tidak bisa ditebak-tebak.
     */
    public function unlock(Request $request, string $invoice)
    {
        $order = $this->findAccessible($invoice);

        $data = $request->validate([
            'whatsapp' => ['required', 'string', 'max:24'],
        ]);

        $key = 'unlock:'.$request->ip().':'.$order->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('error', 'Terlalu banyak percobaan. Coba lagi dalam '
                .RateLimiter::availableIn($key).' detik.');
        }

        RateLimiter::hit($key, 600);

        if (wa_normalize($data['whatsapp']) !== $order->buyer_wa) {
            ActivityLog::record(
                'order.unlock.rejected',
                'Gagal membuka isi pesanan '.$order->invoice_no.' (nomor WhatsApp tidak cocok)',
                $order,
            );

            return back()->with('error', 'Nomor WhatsApp tidak cocok dengan pesanan ini.');
        }

        $this->access->unlock($order->invoice_no);

        ActivityLog::record(
            'order.unlock',
            'Isi pesanan '.$order->invoice_no.' dibuka (nomor WhatsApp cocok)',
            $order,
        );

        return back()->with('success', 'Isi pesanan dibuka selama '
            .intdiv(OrderAccess::UNLOCK_TTL, 60).' menit — setelah itu verifikasi ulang. '
            .'Jangan bagikan akun ini ke siapa pun.');
    }

    /**
     * Cek transaksi cepat dari popup "Cek Transaksi" (gaya situs asal):
     * cukup nomor invoice, hasilnya lembar E-Invoice pembelian.
     *
     * Sengaja HANYA menerima nomor invoice — situs asal mencari berdasarkan
     * nomor WhatsApp (`GET /orders?wa=`) sehingga siapa pun yang tahu nomor
     * WA orang lain bisa membaca riwayatnya. Di sini identitas pembeli juga
     * disamarkan (`mask_email()`/`mask_wa()`, nomor WA adalah kunci membuka
     * isi pesanan) dan isi pesanan (akun/kredensial) tidak pernah ikut.
     * Percobaan dibatasi supaya nomor invoice tidak bisa ditebak-tebak.
     */
    public function check(Request $request)
    {
        $data = $request->validate([
            'invoice' => ['required', 'string', 'max:32'],
        ]);

        $key = 'cek:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 8)) {
            return back()->withInput()->with(
                'cek_error',
                'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.',
            );
        }

        RateLimiter::hit($key, 300);

        $order = Order::query()
            ->where('invoice_no', strtoupper(trim($data['invoice'])))
            ->first();

        if (! $order) {
            return back()
                ->withInput()
                ->with('cek_error', 'Invoice tidak ditemukan. Periksa kembali nomor invoice Anda.');
        }

        return back()->with('cek_hasil', $order->invoice_no);
    }

    // ------------------------------------------------------------------ lacak
    public function trackForm()
    {
        return view('storefront.track', [
            'invoice' => old('invoice', request('invoice')),
            'orders' => $this->access->orders(),
        ]);
    }

    public function track(Request $request)
    {
        $data = $request->validate([
            'invoice' => ['required', 'string', 'max:32'],
            'whatsapp' => ['required', 'string', 'max:24'],
        ]);

        // Batasi percobaan supaya invoice orang lain tidak bisa ditebak-tebak.
        $key = 'track:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 8)) {
            return back()->with('error', 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.');
        }

        RateLimiter::hit($key, 300);

        $order = Order::query()
            ->where('invoice_no', strtoupper(trim($data['invoice'])))
            ->where('buyer_wa', wa_normalize($data['whatsapp']))
            ->first();

        if (! $order) {
            return back()
                ->withInput()
                ->with('error', 'Pesanan tidak ditemukan. Pastikan nomor invoice dan WhatsApp sama dengan saat memesan.');
        }

        // Nomor WhatsApp sudah dicocokkan di sini, jadi isi pesanannya sekalian
        // dibuka untuk beberapa menit (OrderAccess::UNLOCK_TTL) — pembeli tidak
        // perlu memverifikasi dua kali di halaman pesanan.
        $this->access->unlock($order->invoice_no);

        return redirect()->route('orders.show', $order->invoice_no);
    }

    // ------------------------------------------------------------------ auth
    /**
     * Pesanan hanya bisa dibuka bila invoice-nya tersimpan di session pembeli.
     * Ini menutup kebocoran seperti `GET /orders?wa=` di situs asal, di mana
     * siapa pun yang tahu nomor WA bisa membaca riwayat orang lain.
     */
    private function findAccessible(string $invoice): Order
    {
        $order = Order::query()
            ->where('invoice_no', strtoupper(trim($invoice)))
            ->firstOrFail();

        if (! $this->access->has($order->invoice_no)) {
            abort(404);
        }

        return $order;
    }

    private function whatsappMessage(Order $order): string
    {
        return "Halo admin, saya sudah transfer untuk pesanan {$order->invoice_no} "
            .'sebesar '.rupiah($order->total).'. Mohon dicek ya.';
    }
}
