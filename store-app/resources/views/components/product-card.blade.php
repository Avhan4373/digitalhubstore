@props(['product', 'variant' => 'grid'])

@php
    $variants = $product->relationLoaded('activeVariants') ? $product->activeVariants : $product->activeVariants()->get();
    $prices = $variants->pluck('price')->filter()->values();
    $min = (int) ($prices->min() ?? 0);
    $rating = trim((string) ($product->rating_source ?? '')) ?: '5';
    $showSold = \App\Models\Setting::get('show_sold_count', true) && $product->sold_count > 0;
@endphp

@if($variant === 'popular')
    {{-- Kartu kecil untuk blok "Produk Terlaris & Paling Diminati" --}}
    <a href="{{ route('products.show', $product->slug) }}"
       class="group relative flex cursor-pointer flex-col justify-between rounded-2xl border border-rose-200/90 bg-white p-2.5 shadow-2xs transition-all hover:border-[#f5125e]">
        <div>
            @if($product->is_best_seller)
                <span class="absolute left-2 top-2 z-10 rounded bg-[#f5125e] px-1.5 py-0.5 text-[8px] font-black text-white shadow-xs">🔥 BEST SELLER</span>
            @endif
            @if($product->is_hot)
                <span class="absolute right-2 top-2 z-10 rounded bg-rose-600 px-1.5 py-0.5 text-[8px] font-black text-white shadow-xs">HOT</span>
            @endif

            <div class="mb-1.5 flex aspect-square w-full items-center justify-center rounded-xl border border-slate-100 bg-slate-50 p-2">
                @if($product->cover_image)
                    <img src="{{ $product->coverUrl() }}" alt="{{ $product->name }}" loading="lazy" class="h-full w-full object-contain">
                @else
                    <span class="text-3xl font-black text-slate-300">{{ mb_substr($product->name, 0, 1) }}</span>
                @endif
            </div>

            <h3 class="line-clamp-1 text-xs font-bold text-slate-800">{{ $product->name }}</h3>
        </div>

        <div class="mt-2 flex items-center justify-between border-t border-slate-100 pt-1">
            <span class="text-xs font-black text-[#f5125e]">{{ $min > 0 ? rupiah($min) : 'Hubungi admin' }}</span>
            @if($showSold)
                <span class="text-[9px] text-slate-400">{{ sold_label($product->sold_count) }} Terjual</span>
            @endif
        </div>
    </a>
@else
    {{-- Kartu katalog (grid utama) --}}
    <a href="{{ route('products.show', $product->slug) }}"
       class="group relative flex cursor-pointer flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-2.5 shadow-2xs transition-all hover:border-[#f5125e] hover:shadow-md sm:p-3">
        <div>
            <div class="mb-2 flex aspect-square w-full items-center justify-center overflow-hidden rounded-xl border border-slate-100 bg-slate-50 p-2 transition-transform group-hover:scale-[1.02]">
                @if($product->cover_image)
                    <img src="{{ $product->coverUrl() }}" alt="{{ $product->name }}" loading="lazy" class="h-full w-full object-contain">
                @else
                    <span class="text-4xl font-black text-slate-300">{{ mb_substr($product->name, 0, 1) }}</span>
                @endif
            </div>

            @if($product->category)
                <span class="rounded bg-rose-50 px-1.5 py-0.5 text-[9px] font-bold uppercase text-[#f5125e]">{{ $product->category->name }}</span>
            @endif

            <h3 class="mt-1 line-clamp-2 min-h-[2rem] text-xs font-semibold leading-tight text-slate-800 sm:text-sm">
                {{ $product->name }}
            </h3>
        </div>

        <div class="mt-2.5 border-t border-slate-100 pt-2">
            <p class="text-xs font-extrabold text-[#f5125e] sm:text-sm">{{ $min > 0 ? rupiah($min) : 'Hubungi admin' }}</p>
            <div class="mt-1 flex items-center justify-between text-[10px] text-slate-400">
                <span class="font-bold text-amber-500">★★★★★ <span class="font-medium text-slate-400">{{ $rating }}</span></span>
                @if($showSold)
                    <span>{{ sold_label($product->sold_count) }} Terjual</span>
                @endif
            </div>
        </div>
    </a>
@endif
