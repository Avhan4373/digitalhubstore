@extends('layouts.storefront')

@section('title', ($activeCategory?->name ?? 'Semua Produk').' — Katalog')

@section('content')
    <x-category-menu :categories="$categories" :active="$activeCategory?->slug" base="catalog" />

    <div class="space-y-3">
        {{-- Kepala katalog: jumlah produk + urutan --}}
        <div class="flex flex-col justify-between gap-2 px-1 sm:flex-row sm:items-center">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="flex items-center gap-2 text-sm font-extrabold text-slate-800 sm:text-base">
                    <i data-lucide="layout-grid" class="h-4 w-4 text-[#f5125e]"></i> Daftar Produk &amp; Layanan
                </h2>
                <span class="rounded-md border border-rose-100 bg-rose-50 px-2 py-0.5 text-[10px] font-bold uppercase text-[#f5125e]">
                    {{ $activeCategory?->name ?? 'Semua' }}
                </span>
                <span class="text-xs font-semibold text-slate-500">({{ $products->total() }} Produk)</span>
                @if($query)
                    <span class="text-xs text-slate-500">untuk <b class="text-slate-700">"{{ $query }}"</b></span>
                @endif
            </div>

            <div class="flex items-center gap-2 self-end sm:self-auto">
                <span class="hidden text-xs font-medium text-slate-500 sm:inline">Urutkan:</span>
                <form method="GET" action="{{ route('catalog') }}">
                    @foreach(request()->except('urut', 'page') as $key => $value)
                        @if(is_scalar($value) && $value !== '')
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <select name="urut" data-auto-submit
                            class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-[#f5125e]">
                        @foreach(['sesuai' => 'Paling Sesuai', 'terlaris' => '🔥 Paling Terlaris', 'termurah' => '💰 Harga Terendah', 'termahal' => '💎 Harga Tertinggi', 'rating' => '⭐ Rating Tertinggi'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['urut'] ?? 'sesuai') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </form>
            </div>
        </div>

        {{-- Filter tambahan (kata kunci & rentang harga) --}}
        <details class="rounded-2xl border border-slate-200 bg-white p-3 shadow-2xs sm:p-4">
            <summary class="flex cursor-pointer list-none items-center justify-between text-xs font-bold text-slate-700">
                <span class="flex items-center gap-2">
                    <i data-lucide="filter" class="h-4 w-4 text-[#f5125e]"></i> Filter Pencarian
                </span>
                <i data-lucide="chevron-down" class="h-4 w-4 text-slate-400"></i>
            </summary>

            <form method="GET" action="{{ route('catalog') }}" class="mt-3 grid gap-3 sm:grid-cols-4">
                @if($activeCategory)
                    <input type="hidden" name="kategori" value="{{ $activeCategory->slug }}">
                @endif
                <input type="hidden" name="urut" value="{{ $filters['urut'] ?? 'sesuai' }}">

                <div class="sm:col-span-2">
                    <label class="label" for="q">Kata kunci</label>
                    <input id="q" name="q" value="{{ $query }}" class="field" placeholder="mis. canva, netflix, template">
                </div>
                <div>
                    <label class="label" for="min">Harga minimum</label>
                    <input id="min" name="min" type="number" min="0" step="1000" value="{{ $filters['min'] ?? '' }}" class="field" placeholder="0">
                </div>
                <div>
                    <label class="label" for="max">Harga maksimum</label>
                    <input id="max" name="max" type="number" min="0" step="1000" value="{{ $filters['max'] ?? '' }}" class="field" placeholder="Tanpa batas">
                </div>

                <div class="flex gap-2 sm:col-span-4">
                    <button class="btn-primary btn-sm">Terapkan filter</button>
                    <a href="{{ route('catalog') }}" class="btn-secondary btn-sm">Reset</a>
                </div>
            </form>
        </details>

        @if($products->isEmpty())
            <div class="rounded-2xl border border-slate-200 bg-white px-4 py-16 text-center shadow-2xs">
                <i data-lucide="package" class="mx-auto mb-2 h-8 w-8 text-slate-300"></i>
                <p class="text-sm font-semibold text-slate-700">Tidak ada produk yang cocok.</p>
                <p class="mt-1 text-sm text-slate-500">Coba ubah kata kunci atau filter harga.</p>
                <a href="{{ route('catalog') }}" class="mt-4 inline-flex rounded-xl bg-[#f5125e] px-4 py-2.5 text-xs font-bold text-white">Lihat semua produk</a>
            </div>
        @else
            <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                @foreach($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>

            <div class="pt-2">
                {{ $products->links() }}
            </div>
        @endif
    </div>
@endsection
