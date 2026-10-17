<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Cart;
use App\Services\CouponService;
use App\Services\OrderAccess;
use App\Services\OrderService;
use App\Services\TripayService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly Cart $cart,
        private readonly CouponService $coupons,
        private readonly OrderService $orders,
        private readonly OrderAccess $access,
        private readonly TripayService $tripay,
    ) {}

    public function index(Request $request)
    {
        $items = $this->cart->items();

        if ($items->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Keranjang Anda masih kosong.');
        }

        $subtotal = $this->cart->subtotal();
        $coupon = null;
        $discount = 0;
        $message = null;

        $code = $request->query('voucher', $this->cart->couponCode());

        if ($code) {
            $result = $this->coupons->validate((string) $code, $subtotal);
            if ($result['ok']) {
                $coupon = $result['coupon'];
                $discount = $result['discount'];
            } else {
                $this->cart->setCoupon(null);
                $message = $result['message'];
            }
        }

        return view('storefront.checkout', [
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => max(0, $subtotal - $discount),
            'coupon' => $coupon,
            'couponCode' => $coupon?->code,
            'couponMessage' => $message,
            'methods' => $this->availableMethods(),
            'buyer' => session('checkout.buyer', []),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'buyer_name' => ['required', 'string', 'min:2', 'max:60'],
            'buyer_wa' => ['required', 'string', 'min:8', 'max:24', 'regex:/^[0-9+\-\s()]+$/'],
            'buyer_email' => ['nullable', 'email', 'max:160'],
            'buyer_note' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['required', 'in:qris_manual,tripay'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            // data wajib per varian (mis. Player ID / email), divalidasi ulang
            // terhadap label yang diminta varian di dalam OrderService
            'required_info' => ['nullable', 'array', 'max:20'],
            'required_info.*' => ['nullable', 'array', 'max:5'],
            'required_info.*.*' => ['nullable', 'string', 'max:160'],
            // honeypot: diisi hanya oleh bot
            'website' => ['nullable', 'size:0'],
        ], [
            'website.size' => 'Permintaan ditolak.',
        ]);

        $whatsapp = wa_normalize($data['buyer_wa']);

        if (strlen($whatsapp) < 10 || ! str_starts_with($whatsapp, '62')) {
            throw ValidationException::withMessages([
                'buyer_wa' => 'Nomor WhatsApp tidak valid. Contoh: 0822 4389 0517.',
            ]);
        }

        if (! in_array($data['payment_method'], array_keys($this->availableMethods()), true)) {
            throw ValidationException::withMessages([
                'payment_method' => 'Metode pembayaran tidak tersedia.',
            ]);
        }

        if ($this->cart->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Keranjang Anda masih kosong.');
        }

        $order = $this->orders->createFromCart(
            [
                'name' => $data['buyer_name'],
                'whatsapp' => $whatsapp,
                'email' => $data['buyer_email'] ?? null,
                'note' => $data['buyer_note'] ?? null,
            ],
            $data['payment_method'],
            $data['coupon_code'] ?? null,
            $data['required_info'] ?? [],
        );

        session()->put('checkout.buyer', [
            'buyer_name' => $data['buyer_name'],
            'buyer_wa' => $whatsapp,
            'buyer_email' => $data['buyer_email'] ?? null,
        ]);

        $this->cart->clear();
        // Pesanan baru dibuat di browser ini, jadi halaman STATUS-nya boleh
        // dibuka — tetapi isinya (akun) tetap butuh verifikasi nomor WhatsApp
        // di halaman pesanan. Kalau checkout otomatis membuka isi, browser
        // bergantian (warnet) akan menampilkan akun pembeli sebelumnya.
        $this->access->remember($order->invoice_no);

        // Pembayaran gateway: buat transaksi sekarang supaya pembeli langsung
        // dapat QR/VA tanpa polling dari browser.
        if ($order->payment_method === Order::METHOD_TRIPAY && $this->tripay->isConfigured()) {
            $result = $this->tripay->createTransaction($order);

            if ($result['ok']) {
                $order->forceFill([
                    'gateway_reference' => $result['data']['reference'] ?? null,
                    'gateway_payload' => $result['data'],
                ])->save();
            } else {
                // gateway gagal -> turunkan ke QRIS manual supaya pembeli
                // tidak terjebak di halaman mati
                $order->forceFill([
                    'payment_method' => Order::METHOD_QRIS,
                    'admin_note' => 'Gateway gagal: '.$result['message'],
                ])->save();
            }
        }

        return redirect()->route('orders.pay', $order->invoice_no);
    }

    /** @return array<string,string> */
    private function availableMethods(): array
    {
        $methods = [];

        if (Setting::manualEnabled()) {
            $methods[Order::METHOD_QRIS] = 'QRIS manual (verifikasi admin)';
        }

        if ($this->tripay->isConfigured()) {
            $methods[Order::METHOD_TRIPAY] = 'Otomatis via gateway (Tripay)';
        }

        return $methods;
    }
}
