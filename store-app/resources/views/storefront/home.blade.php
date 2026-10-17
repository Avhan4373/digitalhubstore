@extends('layouts.storefront')

@section('title', $storeName.' — '.$tagline)

@section('content')
    <x-trust-bar />

    {{-- Banner promo: hanya tampil kalau admin mengunggah gambar banner.
         (Situs asal juga tidak menampilkan banner apa pun saat belum diisi.) --}}
    @if($bannerUrl)
        <div class="w-full overflow-hidden rounded-2xl border border-slate-200/80 shadow-sm sm:rounded-3xl">
            <img src="{{ $bannerUrl }}" alt="Banner promo {{ $storeName }}" class="h-auto max-h-56 w-full object-cover sm:max-h-72">
        </div>
    @endif

    <x-category-menu :categories="$categories" :active="$activeCategory?->slug" base="home" />

    @if($popular->isNotEmpty())
        <div class="space-y-3">
            <div class="flex items-center justify-between px-1">
                <h2 class="flex items-center gap-1.5 text-xs font-black uppercase tracking-wide text-slate-800 sm:text-sm">
                    <i data-lucide="flame" class="h-4 w-4 text-[#f5125e]"></i> Produk Terlaris &amp; Paling Diminati
                </h2>
            </div>
            <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-4 sm:gap-4">
                @foreach($popular as $product)
                    <x-product-card :product="$product" variant="popular" />
                @endforeach
            </div>
        </div>
    @endif

    {{-- ===================== Katalog produk ===================== --}}
    <div class="space-y-3">
        <div class="flex flex-col justify-between gap-2 px-1 sm:flex-row sm:items-center">
            <div class="flex items-center gap-2">
                <h2 class="flex items-center gap-2 text-sm font-extrabold text-slate-800 sm:text-base">
                    <i data-lucide="layout-grid" class="h-4 w-4 text-[#f5125e]"></i> Daftar Produk &amp; Layanan
                </h2>
                <span class="rounded-md border border-rose-100 bg-rose-50 px-2 py-0.5 text-[10px] font-bold uppercase text-[#f5125e]">
                    {{ $activeCategory?->name ?? 'Semua' }}
                </span>
                <span class="text-xs font-semibold text-slate-500">({{ $products->count() }} Produk)</span>
            </div>

            <div class="flex items-center gap-2 self-end sm:self-auto">
                <span class="hidden text-xs font-medium text-slate-500 sm:inline">Urutkan:</span>
                <form method="GET" action="{{ route('home') }}">
                    @if($activeCategory)
                        <input type="hidden" name="kategori" value="{{ $activeCategory->slug }}">
                    @endif
                    <select name="urut" data-auto-submit
                            class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-[#f5125e]">
                        @foreach(['sesuai' => 'Paling Sesuai', 'terlaris' => '🔥 Paling Terlaris', 'termurah' => '💰 Harga Terendah', 'termahal' => '💎 Harga Tertinggi', 'rating' => '⭐ Rating Tertinggi'] as $value => $label)
                            <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </form>
            </div>
        </div>

        @if($products->isEmpty())
            <div class="rounded-2xl border border-slate-200 bg-white px-4 py-16 text-center shadow-2xs">
                <i data-lucide="package" class="mx-auto mb-2 h-8 w-8 text-slate-300"></i>
                <p class="text-sm font-semibold text-slate-700">Belum ada produk pada kategori ini.</p>
                <a href="{{ route('home') }}" class="mt-4 inline-flex rounded-xl bg-[#f5125e] px-4 py-2.5 text-xs font-bold text-white">Lihat semua produk</a>
            </div>
        @else
            <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                @foreach($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        @endif
    </div>

    <x-faq />

    {{-- Notifikasi pembelian terbaru (data nyata, nama disamarkan) --}}
    @if($socialProof !== [])
        <div id="social-proof-toast" data-items="{{ json_encode($socialProof) }}"
             class="pointer-events-none fixed bottom-20 left-3 z-40 flex max-w-xs translate-y-6 items-center gap-3 rounded-2xl border border-rose-200/90 bg-white/95 p-2.5 opacity-0 shadow-xl backdrop-blur-md transition-all duration-500 sm:p-3 md:bottom-6 md:left-6">
            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-gradient-to-tr from-[#f5125e] to-[#ff477e] text-white shadow-xs">
                <i data-lucide="shopping-bag" class="h-5 w-5"></i>
            </div>
            <div class="text-[11px] leading-tight">
                <p class="flex items-center gap-1 font-bold text-slate-800">
                    <span data-proof-user></span>
                    <span class="rounded bg-emerald-50 px-1 text-[9px] font-semibold text-emerald-600">Lunas</span>
                </p>
                <p class="max-w-[180px] truncate font-medium text-slate-500" data-proof-product></p>
                <span class="text-[9.5px] text-slate-400" data-proof-time></span>
            </div>
        </div>
    @endif
@endsection
