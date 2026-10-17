@extends('layouts.storefront')

@section('title', 'Invoice '.$order->invoice_no)

@section('content')
    <div class="mx-auto max-w-2xl">
        <div class="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-2xl">
            <div class="h-1.5 w-full bg-gradient-to-r from-[#f5125e] to-[#ff477e]"></div>

            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-6 py-5">
                <div class="flex items-start gap-3">
                    <img src="{{ \App\Models\Setting::logoUrl() }}" alt="Logo {{ \App\Models\Setting::storeName() }}"
                         class="h-11 w-11 rounded-2xl border border-slate-100 bg-white object-contain shadow-xs">
                    <div>
                        <p class="text-lg font-black text-slate-900">{{ \App\Models\Setting::storeName() }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ \App\Models\Setting::get('store_tagline', '') }}</p>
                        @php($wa = \App\Models\Setting::whatsappNumber())
                        @if($wa)
                            <p class="mt-0.5 text-xs text-slate-500">{{ wa_display($wa) }}</p>
                        @endif
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-xs uppercase tracking-wide text-slate-400">Invoice</p>
                    <p class="font-mono text-sm font-bold text-slate-900">{{ $order->invoice_no }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $order->created_at->translatedFormat('d M Y, H:i') }} WIB</p>
                    <span class="badge mt-2 inline-flex {{ $order->paymentBadge() }}">{{ $order->paymentLabel() }}</span>
                </div>
            </div>

            <div class="grid gap-4 px-6 py-5 sm:grid-cols-2">
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-400">Ditagihkan kepada</p>
                    <p class="mt-1 text-sm font-semibold text-slate-800">{{ $order->buyer_name }}</p>
                    <p class="text-sm text-slate-500">{{ mask_wa($order->buyer_wa) }}</p>
                    @if($order->buyer_email)
                        <p class="text-sm text-slate-500">{{ $order->buyer_email }}</p>
                    @endif
                </div>
                <div class="sm:text-right">
                    <p class="text-xs uppercase tracking-wide text-slate-400">Metode pembayaran</p>
                    <p class="mt-1 text-sm font-semibold text-slate-800">
                        {{ $order->payment_method === \App\Models\Order::METHOD_TRIPAY ? 'Gateway otomatis' : 'QRIS manual' }}
                    </p>
                    @if($order->paid_at)
                        <p class="text-sm text-slate-500">Dibayar {{ $order->paid_at->translatedFormat('d M Y, H:i') }} WIB</p>
                    @endif
                </div>
            </div>

            <div class="px-6">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Produk</th>
                                <th class="text-right">Harga</th>
                                <th class="text-center">Qty</th>
                                <th class="text-right">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($order->items as $item)
                                <tr>
                                    <td>
                                        <p class="font-medium text-slate-800">{{ $item->product_name }}</p>
                                        <p class="text-xs text-slate-500">{{ $item->variant_name }}</p>
                                    </td>
                                    <td class="text-right">{{ rupiah($item->unit_price) }}</td>
                                    <td class="text-center">{{ $item->qty }}</td>
                                    <td class="text-right font-medium">{{ rupiah($item->subtotal) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-5 ml-auto max-w-xs space-y-1.5 px-6 text-sm">
                <div class="flex justify-between text-slate-500">
                    <span>Subtotal</span><span>{{ rupiah($order->subtotal) }}</span>
                </div>
                @if($order->discount > 0)
                    <div class="flex justify-between text-emerald-700">
                        <span>Voucher {{ $order->coupon_code }}</span><span>-{{ rupiah($order->discount) }}</span>
                    </div>
                @endif
                <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-bold">
                    <span>Total</span><span class="text-brand-600">{{ rupiah($order->total) }}</span>
                </div>
            </div>

            <p class="mt-6 border-t border-slate-100 px-6 pb-6 pt-4 text-center text-[11px] text-slate-400">
                Invoice ini dibuat otomatis oleh sistem. Simpan sebagai bukti pembelian dan klaim garansi.
            </p>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" data-print
                    class="flex items-center gap-1.5 rounded-2xl bg-gradient-to-r from-[#f5125e] to-[#ff2b56] px-4 py-2.5 text-xs font-bold text-white shadow-md transition-transform active:scale-95">
                <i data-lucide="printer" class="h-3.5 w-3.5"></i> Cetak / Simpan PDF
            </button>
            <a href="{{ route('orders.show', $order->invoice_no) }}" class="btn-secondary">Kembali ke pesanan</a>
        </div>
    </div>
@endsection
