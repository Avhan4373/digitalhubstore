<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Cart;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly Cart $cart) {}

    public function index()
    {
        return view('storefront.cart', [
            'items' => $this->cart->items(),
            'subtotal' => $this->cart->subtotal(),
        ]);
    }

    public function add(Request $request)
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer', 'exists:variants,id'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:'.Cart::MAX_QTY_PER_ITEM],
        ]);

        $result = $this->cart->add((int) $data['variant_id'], (int) ($data['qty'] ?? 1));

        if ($request->boolean('beli_sekarang') && $result['ok']) {
            return redirect()->route('checkout.index');
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:0', 'max:'.Cart::MAX_QTY_PER_ITEM],
        ]);

        foreach ($data['quantities'] as $variantId => $qty) {
            $this->cart->setQty((int) $variantId, (int) ($qty ?? 0));
        }

        return back()->with('success', 'Keranjang diperbarui.');
    }

    public function remove(Request $request)
    {
        $data = $request->validate(['variant_id' => ['required', 'integer']]);
        $this->cart->remove((int) $data['variant_id']);

        return back()->with('success', 'Item dihapus dari keranjang.');
    }

    public function clear()
    {
        $this->cart->clear();

        return back()->with('success', 'Keranjang dikosongkan.');
    }
}
