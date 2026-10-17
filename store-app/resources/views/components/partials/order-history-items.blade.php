{{--
    Baris produk di kartu pesanan (gaya Shopee) — dipakai
    `components/order-history-card`. Sengaja dipisah supaya kartu satu-item
    (paling umum) tetap punya markup identik dengan situs asal.
--}}
<div class="flex min-w-0 flex-1 flex-col gap-3">
    @foreach($order->items as $item)
        @php($gambar = $item->product?->coverUrl())

        <div class="flex min-w-0 gap-3">
            @if($gambar)
                <img src="{{ $gambar }}" alt="{{ $item->product_name }}"
                     class="h-16 w-16 flex-shrink-0 rounded-xl border border-slate-100 bg-slate-50 object-contain p-1">
            @else
                <div class="flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-xl border border-slate-100 bg-slate-50">
                    <i data-lucide="package" class="h-5 w-5 text-slate-300"></i>
                </div>
            @endif

            <div class="flex min-w-0 flex-1 flex-col justify-between">
                <div>
                    <h4 class="line-clamp-1 text-xs font-bold leading-tight text-slate-900 sm:text-sm">{{ $item->product_name }}</h4>
                    <p class="mt-0.5 text-[10.5px] text-slate-500">Variasi: {{ $item->variant_name }}</p>
                </div>
                <div class="mt-1 flex items-center justify-between text-xs">
                    <span class="text-[10px] text-slate-400">x{{ $item->qty }}</span>
                    <span class="font-extrabold text-slate-800">{{ rupiah($item->subtotal) }}</span>
                </div>
            </div>
        </div>
    @endforeach
</div>
