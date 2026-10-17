@props(['categories', 'active' => null, 'base' => null])

@php
    /**
     * Ikon, warna kotak, dan LABEL TAMPILAN tiap kategori — disalin apa adanya
     * dari menu situs asal (`#category-buttons-grid`). Label menu di situs asal
     * memang berbeda dari nama kategori di data produk (mis. data "Editor"
     * ditampilkan sebagai "App Pro"), jadi alias di bawah ini bukan bug.
     *
     * Kunci = slug kategori. `order` di bawah juga menyalin urutan menu asal;
     * kategori baru yang tidak terdaftar ditaruh di belakang.
     */
    $menu = [
        'ai' => ['label' => 'Akun AI', 'icon' => 'bot', 'box' => 'bg-rose-50 text-[#f5125e] border-rose-100', 'hover' => 'group-hover:bg-[#f5125e]'],
        'editor' => ['label' => 'App Pro', 'icon' => 'video', 'box' => 'bg-pink-50 text-pink-600 border-pink-100', 'hover' => 'group-hover:bg-pink-600'],
        'template-web' => ['label' => 'Template Web', 'icon' => 'layout', 'box' => 'bg-amber-50 text-amber-600 border-amber-100', 'hover' => 'group-hover:bg-amber-600'],
        'landing' => ['label' => 'Landing Page', 'icon' => 'globe', 'box' => 'bg-sky-50 text-sky-600 border-sky-100', 'hover' => 'group-hover:bg-sky-600'],
        // Data kategori aslinya bernama "meeting"; menunya berlabel "Jasa Web".
        'meeting' => ['label' => 'Jasa Web', 'icon' => 'monitor', 'box' => 'bg-purple-50 text-purple-600 border-purple-100', 'hover' => 'group-hover:bg-purple-600'],
        'jasa-web' => ['label' => 'Jasa Web', 'icon' => 'monitor', 'box' => 'bg-purple-50 text-purple-600 border-purple-100', 'hover' => 'group-hover:bg-purple-600'],
        'stream' => ['label' => 'Streaming', 'icon' => 'play-square', 'box' => 'bg-violet-50 text-violet-600 border-violet-100', 'hover' => 'group-hover:bg-violet-600'],
    ];

    $order = ['ai', 'editor', 'template-web', 'landing', 'meeting', 'jasa-web', 'stream'];

    $fallback = ['label' => null, 'icon' => 'grid', 'box' => 'bg-slate-50 text-slate-600 border-slate-100', 'hover' => 'group-hover:bg-slate-600'];

    $items = $categories
        ->sortBy(fn ($c) => array_search($c->slug, $order, true) === false ? 999 : array_search($c->slug, $order, true))
        ->values();

    // Kotak aktif: pink penuh. Kotak non-aktif: warna kategorinya.
    $activeBox = 'h-12 w-12 rounded-2xl flex items-center justify-center border border-[#f5125e] bg-[#f5125e] text-white shadow-xs';
    $linkTo = fn (?string $slug) => $base
        ? route($base, $slug ? ['kategori' => $slug] : [])
        : route('catalog', $slug ? ['kategori' => $slug] : []);
@endphp

{{-- Menu kategori gaya situs asal: kotak ikon + label --}}
<div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-3.5 shadow-2xs sm:p-5">
    <div class="grid grid-cols-4 gap-3 text-center text-[11px] font-semibold text-slate-700 sm:gap-4 md:grid-cols-7">

        {{-- Semua --}}
        <a href="{{ $linkTo(null) }}" class="group flex flex-col items-center gap-1.5">
            <span class="{{ $active === null
                ? $activeBox
                : 'h-12 w-12 rounded-2xl flex items-center justify-center border border-slate-200 bg-slate-100 text-slate-700 shadow-2xs transition-all group-hover:scale-105' }}">
                <i data-lucide="grid" class="h-5 w-5"></i>
            </span>
            <span class="w-full truncate {{ $active === null ? 'font-bold text-[#f5125e]' : '' }}">Semua</span>
        </a>

        @foreach($items as $category)
            @php($style = $menu[$category->slug] ?? $fallback)
            @php($isActive = $active === $category->slug)
            <a href="{{ $linkTo($category->slug) }}" class="group flex flex-col items-center gap-1.5">
                <span class="{{ $isActive
                    ? $activeBox
                    : 'h-12 w-12 rounded-2xl flex items-center justify-center border shadow-2xs transition-all group-hover:scale-105 group-hover:text-white '.$style['box'].' '.$style['hover'] }}">
                    <i data-lucide="{{ $style['icon'] }}" class="h-5 w-5"></i>
                </span>
                <span class="w-full truncate {{ $isActive ? 'font-bold text-[#f5125e]' : '' }}">
                    {{ $style['label'] ?? $category->name }}
                </span>
            </a>
        @endforeach
    </div>
</div>
