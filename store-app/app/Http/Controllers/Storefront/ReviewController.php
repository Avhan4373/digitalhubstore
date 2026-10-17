<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $key = 'review:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'comment' => 'Terlalu banyak ulasan dikirim. Coba lagi nanti.',
            ]);
        }

        $data = $request->validate([
            'user_name' => ['required', 'string', 'min:2', 'max:40'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['required', 'string', 'min:5', 'max:1000'],
            'invoice' => ['required', 'string', 'max:32'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        // Ulasan hanya dari pembeli yang benar-benar memesan produk ini.
        $order = Order::query()
            ->where('invoice_no', strtoupper(trim($data['invoice'])))
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->first();

        if (! $order) {
            throw ValidationException::withMessages([
                'invoice' => 'Nomor invoice tidak cocok dengan produk ini.',
            ]);
        }

        if (! order_access()->has($order->invoice_no)) {
            throw ValidationException::withMessages([
                'invoice' => 'Invoice ini bukan milik sesi Anda. Buka pesanan Anda dulu dari halaman Lacak.',
            ]);
        }

        if ($product->reviews()->where('order_id', $order->id)->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'Anda sudah mengulas produk ini untuk invoice tersebut.',
            ]);
        }

        $imagePath = null;

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('reviews', 'public');
        }

        RateLimiter::hit($key, 600);

        $autoApprove = (bool) Setting::get('auto_approve_reviews', false);

        Review::create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'user_name' => $data['user_name'],
            // komentar disimpan mentah, TAPI selalu di-escape saat dirender
            'comment' => $data['comment'],
            'rating' => $data['rating'],
            'variant_name' => $order->items()->where('product_id', $product->id)->value('variant_name'),
            'image_path' => $imagePath,
            'status' => $autoApprove ? Review::STATUS_APPROVED : Review::STATUS_PENDING,
            'ip' => $request->ip(),
        ]);

        return back()->with(
            'success',
            $autoApprove
                ? 'Terima kasih, ulasan Anda sudah tayang.'
                : 'Terima kasih! Ulasan Anda masuk moderasi dan akan tayang setelah disetujui admin.'
        );
    }
}
