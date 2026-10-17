<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $title ?? 'Katalog Produk Digital') — {{ \App\Models\Setting::storeName() }}</title>
    <meta name="description" content="@yield('meta_description', \App\Models\Setting::get('store_tagline', 'Akun premium murah, resmi, dan bergaransi.'))">
    <meta name="theme-color" content="#f5125e">
    <link rel="icon" type="image/png" href="{{ \App\Models\Setting::logoUrl() }}">
    <link rel="apple-touch-icon" href="{{ \App\Models\Setting::logoUrl() }}">

    {{-- Font Outfit — sama dengan situs asal --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    @stack('meta')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 pb-20 text-slate-800 antialiased selection:bg-[#f5125e] selection:text-white md:pb-10">

{{-- Toast container --}}
<div id="toast-host" class="pointer-events-none fixed left-1/2 top-5 z-[100] flex w-full max-w-sm -translate-x-1/2 flex-col gap-2 px-4">
    @if(session('success'))
        <div class="pointer-events-auto rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 shadow-lg ring-1 ring-emerald-200">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="pointer-events-auto rounded-xl bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800 shadow-lg ring-1 ring-rose-200">
            {{ session('error') }}
        </div>
    @endif
</div>

@php($cartCount = app(\App\Services\Cart::class)->totalQty())
@php($waLink = \App\Models\Setting::whatsappLink())
@php($waOrder = \App\Models\Setting::whatsappLink('Halo admin, saya mau order via WhatsApp.'))
@php($logoUrl = \App\Models\Setting::logoUrl())
@php($monogram = \App\Models\Setting::logoMonogram())
@php([$wordAwal, $wordAkhir] = \App\Models\Setting::logoWordmark())
@php($logoKustom = \App\Models\Setting::hasCustomLogo())

{{-- ============================ HEADER ============================ --}}
<header class="sticky top-0 z-40 border-b border-rose-100/80 bg-white/95 px-3 py-3 shadow-xs backdrop-blur-md sm:px-8">
    <div class="flex w-full items-center justify-between gap-2.5 sm:gap-6">
        {{-- Logo --}}
        <a href="{{ route('home') }}" class="flex flex-shrink-0 cursor-pointer items-center gap-2.5">
            @if($logoKustom)
                <img src="{{ $logoUrl }}" alt="Logo {{ \App\Models\Setting::storeName() }}"
                     class="h-9 w-9 rounded-2xl border border-slate-100 bg-white object-contain shadow-xs sm:h-10 sm:w-10">
            @else
                <span class="grid h-9 w-9 place-items-center rounded-2xl bg-gradient-to-br from-[#f5125e] to-rose-600 text-xs font-black tracking-tight text-white shadow-xs sm:h-10 sm:w-10 sm:text-sm"
                      aria-label="Logo {{ \App\Models\Setting::storeName() }}">{{ $monogram }}</span>
            @endif
            <div class="hidden sm:block">
                <h1 class="text-base font-black leading-tight tracking-tight text-slate-900 sm:text-lg">
                    @if($wordAwal !== ''){{ $wordAwal }}@endif<span class="text-[#f5125e]">{{ $wordAwal !== '' ? ' '.$wordAkhir : $wordAkhir }}</span>
                </h1>
                <p class="text-[10px] font-medium text-slate-400">{{ \App\Models\Setting::get('store_tagline', 'Aplikasi Premium, Template & Jasa Web') }}</p>
            </div>
        </a>

        {{-- Pencarian --}}
        <div class="relative flex max-w-2xl flex-1 items-center gap-2">
            <form action="{{ route('catalog') }}" method="GET"
                  class="flex flex-1 items-center rounded-full border-2 border-[#f5125e] bg-white p-0.5 shadow-xs transition-all focus-within:ring-2 focus-within:ring-[#f5125e]/30 sm:p-1">
                <i data-lucide="search" class="ml-3 mr-1 h-4 w-4 flex-shrink-0 text-slate-400"></i>
                <input type="search" name="q" value="{{ request('q') }}" aria-label="Cari produk"
                       placeholder="Cari Template, Jasa Web, AI..."
                       class="w-full bg-transparent px-1 text-xs font-medium text-slate-800 placeholder:text-slate-400 focus:outline-none sm:text-sm">
                {{-- Cari dengan gambar: situs asal membuka pemilih foto. Di sini
                     diarahkan ke chat CS supaya tetap berguna (belum ada
                     pencarian visual di sisi server). --}}
                <button type="button" title="Cari dengan Gambar"
                        data-toast="Pencarian lewat gambar belum tersedia di versi ini. Kirim foto produknya ke Chat CS, admin akan bantu carikan."
                        class="flex flex-shrink-0 cursor-pointer items-center justify-center rounded-full p-1.5 text-slate-400 transition-colors hover:text-[#f5125e]">
                    <i data-lucide="camera" class="h-4 w-4"></i>
                </button>
                <button type="submit"
                        class="flex-shrink-0 rounded-full bg-gradient-to-r from-[#f5125e] to-[#ff2b56] px-3.5 py-1.5 text-xs font-bold text-white transition-all hover:opacity-95 sm:px-5 sm:text-sm">
                    Search
                </button>
            </form>
        </div>

        {{-- Aksi kanan --}}
        <div class="flex flex-shrink-0 items-center gap-1.5 sm:gap-3">
            {{-- Live order WA (desktop) --}}
            @if($waOrder !== '#')
                <a href="{{ $waOrder }}" target="_blank" rel="noopener"
                   class="hidden items-center gap-1.5 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-xs font-bold text-emerald-700 shadow-2xs transition-all hover:bg-emerald-100 lg:flex">
                    <span class="h-2 w-2 animate-pulse rounded-full bg-emerald-500"></span>
                    <i data-lucide="message-circle" class="h-4 w-4 text-emerald-600"></i>
                    <span>Live Order WA</span>
                </a>
            @endif

            {{-- Riwayat pesanan (membuka popup "Cek Transaksi"; tanpa JS tetap
                 membuka halaman lacak lewat href-nya) --}}
            <a href="{{ route('orders.track.form') }}" data-modal-open="cek-modal"
               class="hidden cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-100 px-3.5 py-2.5 text-xs font-semibold text-slate-700 transition-all hover:border-rose-200 hover:bg-rose-50 hover:text-[#f5125e] md:flex">
                <i data-lucide="receipt" class="h-4 w-4 text-[#f5125e]"></i>
                <span>Riwayat Pesanan</span>
            </a>

            {{-- Keranjang --}}
            <a href="{{ route('cart.index') }}" title="Keranjang Belanja"
               class="relative flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-100 text-slate-700 transition-all hover:border-rose-200 hover:bg-rose-50 hover:text-[#f5125e] sm:h-10 sm:w-10">
                <i data-lucide="shopping-cart" class="h-4 w-4 sm:h-5 sm:w-5"></i>
                <span id="cart-badge"
                      class="{{ $cartCount > 0 ? '' : 'hidden' }} absolute -right-1 -top-1 flex h-4 w-4 items-center justify-center rounded-full border-2 border-white bg-[#f5125e] text-[9px] font-black text-white">
                    {{ $cartCount > 9 ? '9+' : $cartCount }}
                </span>
            </a>

            {{-- Chat CS --}}
            @if($waLink !== '#')
                <a href="{{ $waLink }}" target="_blank" rel="noopener" title="Chat Bantuan CS"
                   class="relative flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-100 text-slate-700 transition-all hover:border-rose-200 hover:bg-rose-50 hover:text-[#f5125e] sm:h-10 sm:w-10">
                    <i data-lucide="message-square-more" class="h-4 w-4 sm:h-5 sm:w-5"></i>
                    <span class="absolute -right-1 -top-1 rounded-full border-2 border-white bg-emerald-500 px-1 text-[8px] font-bold text-white">CS</span>
                </a>
            @endif
        </div>
    </div>
</header>

{{-- ============================ KONTEN ============================ --}}
<main class="w-full space-y-5 px-3 py-4 sm:space-y-6 sm:px-6 sm:py-6 lg:px-8">
    @yield('content')
</main>

{{-- ============================ FOOTER ============================ --}}
<footer class="mt-8 border-t border-slate-100 py-6 text-center text-xs text-slate-400">
    © {{ date('Y') }} <b>{{ \App\Models\Setting::storeName() }}</b>. All Rights Reserved. {{ \App\Models\Setting::get('store_tagline', 'Layanan Digital Terpercaya.') }}
</footer>

{{-- ======================= NAV BAWAH (MOBILE) ======================= --}}
<nav class="fixed inset-x-0 bottom-0 z-40 flex items-center justify-between border-t border-slate-200/80 bg-white/95 px-3 py-2 text-[10px] font-medium text-slate-500 shadow-lg backdrop-blur-md md:hidden">
    <a href="{{ route('home') }}" class="flex flex-col items-center gap-0.5 {{ request()->routeIs('home') ? 'text-[#f5125e]' : 'hover:text-[#f5125e]' }}">
        <i data-lucide="home" class="h-4 w-4"></i><span>Beranda</span>
    </a>
    <a href="{{ route('catalog') }}" class="flex flex-col items-center gap-0.5 {{ request()->routeIs('catalog', 'products.show') ? 'text-[#f5125e]' : 'hover:text-[#f5125e]' }}">
        <i data-lucide="layout-grid" class="h-4 w-4"></i><span>Katalog</span>
    </a>
    <a href="{{ route('orders.track.form') }}" data-modal-open="cek-modal" class="cursor-pointer flex flex-col items-center gap-0.5 {{ request()->routeIs('orders.*') ? 'text-[#f5125e]' : 'hover:text-[#f5125e]' }}">
        <i data-lucide="receipt" class="h-4 w-4"></i><span>Pesanan</span>
    </a>
    @if($waOrder !== '#')
        <a href="{{ $waOrder }}" target="_blank" rel="noopener" class="relative flex flex-col items-center gap-0.5 font-bold text-emerald-600">
            <span class="absolute -top-1 right-2 h-2 w-2 animate-ping rounded-full bg-emerald-500"></span>
            <i data-lucide="message-circle" class="h-4 w-4"></i><span>Live Order WA</span>
        </a>
    @endif
    <a href="{{ route('cart.index') }}" class="relative flex flex-col items-center gap-0.5 {{ request()->routeIs('cart.*') ? 'text-[#f5125e]' : 'hover:text-[#f5125e]' }}">
        <i data-lucide="shopping-bag" class="h-4 w-4"></i><span>Keranjang</span>
        @if($cartCount > 0)
            <span class="absolute -top-1 right-1 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-[#f5125e] text-[8px] font-black text-white">{{ $cartCount > 9 ? '9+' : $cartCount }}</span>
        @endif
    </a>
    @if($waLink !== '#')
        <a href="{{ $waLink }}" target="_blank" rel="noopener" class="flex flex-col items-center gap-0.5 hover:text-[#f5125e]">
            <i data-lucide="message-square-more" class="h-4 w-4"></i><span>Chat CS</span>
        </a>
    @endif
</nav>

{{-- ==================== TOMBOL BANTUAN MELAYANG ==================== --}}
{{-- Disalin dari situs asal: bubble muncul sesekali, tombol chat di kanan bawah --}}
@if($waLink !== '#')
    <div class="fixed bottom-20 right-4 z-40 flex flex-col items-end gap-2.5 md:bottom-8">
        <div id="quick-help-bubble"
             class="hidden animate-bounce-soft rounded-2xl border border-rose-100 bg-white/95 px-3 py-1.5 text-[11px] font-bold text-slate-800 shadow-xl backdrop-blur-md">
            Butuh bantuan? Chat kami 👋
        </div>
        <a href="{{ $waLink }}" target="_blank" rel="noopener" title="Chat Bantuan CS"
           class="group relative flex h-12 w-12 items-center justify-center rounded-full border-2 border-white bg-gradient-to-tr from-[#f5125e] to-[#ff477e] text-white shadow-2xl transition-all hover:scale-110 active:scale-95 sm:h-14 sm:w-14">
            <i data-lucide="message-square-more" class="h-6 w-6"></i>
            <span class="absolute right-0 top-0 h-3.5 w-3.5 rounded-full border-2 border-white bg-emerald-400"></span>
        </a>
    </div>
@endif

{{-- ================= CEK TRANSAKSI (POPUP GAYA SITUS ASAL) ================= --}}
{{-- Popup ini sengaja diletakkan langsung di dalam <body> supaya bisa dicetak
     sendiri ("Unduh PDF") tanpa ikut mencetak seluruh halaman (lihat app.css). --}}
<x-cek-transaksi />

</body>
</html>
