@extends('layouts.storefront')

@section('title', 'Keranjang Belanja')

@section('content')
    <div class="mx-auto w-full max-w-lg">
        <div class="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-2xl">
            {{-- Kepala keranjang (gaya drawer situs asal) --}}
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-4">
                <h3 class="flex items-center gap-2 text-sm font-bold text-slate-800">
                    <i data-lucide="shopping-cart" class="h-4 w-4 text-[#f5125e]"></i>
                    Keranjang Belanja (<span>{{ $items->count() }}</span>)
                </h3>
                <a href="{{ route('catalog') }}" title="Lanjut belanja" class="text-slate-400 transition-colors hover:text-slate-600">
                    <i data-lucide="x" class="h-5 w-5"></i>
                </a>
            </div>

            @if($items->isEmpty())
                <div class="px-4 py-14 text-center">
                    <i data-lucide="shopping-bag" class="mx-auto mb-2 h-9 w-9 text-slate-300"></i>
                    <p class="text-sm font-semibold text-slate-700">Keranjang Anda masih kosong.</p>
                    <p class="mt-1 text-xs text-slate-500">Pilih layanan digital favorit Anda, proses cepat dan otomatis.</p>
                    <a href="{{ route('catalog') }}"
                       class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-[#f5125e] to-[#ff2b56] px-4 py-2.5 text-xs font-bold text-white shadow-xs transition-transform active:scale-95">
                        <i data-lucide="layout-grid" class="h-3.5 w-3.5"></i> Mulai Belanja
                    </a>
                </div>
            @else
                <form action="{{ route('cart.update') }}" method="POST" data-cart-form>
                    @csrf

                    <div class="space-y-2.5 p-4">
                        @foreach($items as $row)
                            @php($variant = $row['variant'])
                            @php($product = $row['product'])

                            <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-100 bg-slate-50 p-2.5 shadow-sm"
                                 data-cart-row data-step-row data-price="{{ $variant->price }}">
                                <a href="{{ route('products.show', $product->slug) }}" class="shrink-0">
                                    @if($product->cover_image)
                                        <img src="{{ $product->coverUrl() }}" alt="{{ $product->name }}"
                                             class="h-12 w-12 rounded-lg border bg-white object-contain p-1">
                                    @else
                                        <span class="flex h-12 w-12 items-center justify-center rounded-lg border bg-white text-base font-black text-slate-300">
                                            {{ mb_substr($product->name, 0, 1) }}
                                        </span>
                                    @endif
                                </a>

                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('products.show', $product->slug) }}"
                                       class="line-clamp-1 text-xs font-bold text-slate-800 hover:text-[#f5125e]">{{ $product->name }}</a>
                                    <p class="truncate text-[10px] text-slate-500">
                                        {{ $variant->name }} · {{ rupiah($variant->price) }}
                                        @if($variant->requiresStock())
                                            · stok {{ $variant->availableStock() }}
                                        @endif
                                    </p>
                                    <p class="text-xs font-black text-[#f5125e]" data-line-total>{{ rupiah($row['subtotal']) }}</p>
                                </div>

                                <div class="flex flex-shrink-0 items-center gap-1.5">
                                    <div class="flex items-center overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                        <button type="button" data-step="-1"
                                                class="flex h-7 w-7 items-center justify-center text-slate-500 transition hover:bg-slate-100 active:bg-slate-200">
                                            <i data-lucide="minus" class="h-3.5 w-3.5"></i>
                                        </button>
                                        <label class="sr-only" for="qty-{{ $variant->id }}">Jumlah {{ $product->name }}</label>
                                        <input id="qty-{{ $variant->id }}" type="number" min="0"
                                               max="{{ \App\Services\Cart::MAX_QTY_PER_ITEM }}"
                                               name="quantities[{{ $variant->id }}]" value="{{ $row['qty'] }}"
                                               data-qty-input
                                               class="w-8 border-x border-slate-200 bg-transparent py-1 text-center text-xs font-bold text-slate-800 [appearance:textfield] focus:outline-none">
                                        <button type="button" data-step="1"
                                                class="flex h-7 w-7 items-center justify-center text-[#f5125e] transition hover:bg-rose-50 active:bg-rose-100">
                                            <i data-lucide="plus" class="h-3.5 w-3.5"></i>
                                        </button>
                                    </div>

                                    <button type="submit" form="remove-{{ $variant->id }}" title="Hapus item"
                                            class="flex h-7 w-7 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-rose-50 hover:text-rose-600">
                                        <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap items-center gap-2 px-4 pb-3">
                        <button class="btn-secondary btn-sm">
                            <i data-lucide="refresh-cw" class="h-3.5 w-3.5"></i> Perbarui jumlah
                        </button>
                        <button type="submit" form="clear-cart" class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50">
                            Kosongkan
                        </button>
                    </div>

                    {{-- Ringkasan & lanjut checkout (gaya footer drawer) --}}
                    <div class="flex items-center justify-between gap-3 border-t border-slate-100 bg-slate-50 p-4">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Tagihan</p>
                            <p class="text-lg font-black text-slate-900" data-cart-total>{{ rupiah($subtotal) }}</p>
                            <p class="text-[10px] text-slate-400">Voucher dihitung di halaman checkout.</p>
                        </div>
                        <a href="{{ route('checkout.index') }}"
                           class="flex flex-shrink-0 items-center gap-1.5 rounded-xl bg-gradient-to-r from-[#f5125e] to-[#ff2b56] px-4 py-3 text-xs font-black text-white shadow-md transition-transform active:scale-95">
                            Checkout Sekarang <i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>
                        </a>
                    </div>
                </form>

                {{-- Form terpisah untuk hapus per baris (HTML tidak mengizinkan form bersarang) --}}
                @foreach($items as $row)
                    <form id="remove-{{ $row['variant']->id }}" action="{{ route('cart.remove') }}" method="POST" class="hidden">
                        @csrf
                        <input type="hidden" name="variant_id" value="{{ $row['variant']->id }}">
                    </form>
                @endforeach

                <form id="clear-cart" action="{{ route('cart.clear') }}" method="POST" class="hidden">@csrf</form>
            @endif
        </div>

        @if(order_access()->invoices() !== [])
            <div class="mt-6">
                <h2 class="mb-3 flex items-center gap-2 px-1 text-xs font-bold uppercase tracking-wide text-slate-500">
                    <i data-lucide="receipt" class="h-3.5 w-3.5 text-[#f5125e]"></i> Pesanan saya di perangkat ini
                </h2>
                <div class="flex flex-wrap gap-2">
                    @foreach(array_slice(order_access()->invoices(), 0, 6) as $invoice)
                        <a href="{{ route('orders.show', $invoice) }}"
                           class="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 font-mono text-[11px] font-semibold text-slate-600 shadow-2xs transition-colors hover:border-rose-200 hover:text-[#f5125e]">
                            {{ $invoice }}
                            @if(! order_access()->isUnlocked($invoice))
                                <i data-lucide="lock" class="h-3 w-3 text-amber-500"></i>
                            @endif
                        </a>
                    @endforeach
                </div>
                <p class="mt-2 px-1 text-[10px] text-slate-400">
                    Isi pesanan (akun) hanya tampil setelah nomor WhatsApp pesanan diverifikasi.
                </p>
            </div>
        @endif
    </div>
@endsection
