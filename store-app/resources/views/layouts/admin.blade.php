<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — {{ \App\Models\Setting::storeName() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-100 font-sans text-slate-800 antialiased">

<div id="toast-host" class="pointer-events-none fixed left-1/2 top-4 z-[60] flex w-full max-w-sm -translate-x-1/2 flex-col gap-2 px-4"></div>

@php($user = auth()->user())
@php($pendingReviews = \App\Models\Review::query()->pending()->count())
@php($pendingOrders = \App\Models\Order::query()->unpaid()->count())
@php($awaitingOrders = \App\Models\Order::query()->awaitingDelivery()->count())

<div class="flex min-h-screen">
    {{-- Sidebar desktop --}}
    <aside class="hidden w-64 shrink-0 flex-col border-r border-slate-200 bg-slate-50/80 p-4 lg:flex">
        @include('admin._sidebar-head')
        @include('admin._nav-links')
        @include('admin._sidebar-user')
    </aside>

    {{-- Drawer mobile: slide dari kiri; dibuka oleh initAdminNav() --}}
    <div data-admin-drawer-backdrop
         class="fixed inset-0 z-40 hidden bg-slate-900/50 backdrop-blur-sm lg:hidden"
         aria-hidden="true"></div>
    <aside data-admin-drawer
           id="admin-drawer"
           class="fixed left-0 top-0 z-50 flex h-full w-72 max-w-[86vw] -translate-x-full flex-col overflow-y-auto border-r border-slate-200 bg-white p-4 shadow-2xl transition-transform duration-200 ease-out lg:hidden"
           aria-label="Menu admin">
        @include('admin._sidebar-head')
        @include('admin._nav-links')
        @include('admin._sidebar-user')
    </aside>

    {{-- Konten --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur lg:px-6">
            <div class="flex items-center justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2">
                    {{-- Tombol menu drawer (mobile) --}}
                    <button type="button" data-admin-nav-toggle
                            class="grid h-10 w-10 shrink-0 cursor-pointer place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 transition active:scale-95 hover:border-brand-200 hover:bg-brand-50 hover:text-brand-600 lg:hidden"
                            aria-label="Buka menu admin" aria-expanded="false">
                        <i data-lucide="menu" class="h-4.5 w-4.5"></i>
                    </button>

                    <div class="min-w-0">
                        <h1 class="truncate text-base font-black tracking-tight text-slate-900 sm:text-lg">@yield('heading', 'Dashboard')</h1>
                        @hasSection('subheading')
                            <p class="truncate text-[11px] text-slate-500 sm:text-xs">@yield('subheading')</p>
                        @endif
                    </div>
                </div>

                {{-- Bell pesanan masuk (realtime, polling) --}}
                <div class="relative flex shrink-0 items-center gap-2" data-admin-live data-live-url="{{ route('admin.orders.live') }}">
                    @php($liveTotal = $pendingOrders + $awaitingOrders)
                    <button type="button" data-live-toggle
                            class="relative grid h-10 w-10 cursor-pointer place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-600"
                            aria-label="Pesanan masuk">
                        <i data-lucide="bell" class="h-4.5 w-4.5"></i>
                        <span data-live-badge
                              class="{{ $liveTotal === 0 ? 'hidden' : '' }} absolute -right-1 -top-1 grid h-4 min-w-4 place-items-center rounded-full border-2 border-white bg-brand-500 px-1 text-[9px] font-black text-white">
                            {{ $liveTotal }}
                        </span>
                    </button>

                    <div data-live-panel class="hidden absolute right-0 top-12 z-40 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">
                        <p class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 text-xs font-black uppercase tracking-wide text-slate-600">
                            <i data-lucide="bell-ring" class="h-3.5 w-3.5 text-brand-500"></i> Pesanan Masuk
                            <span class="ml-auto flex items-center gap-1 text-[10px] font-medium normal-case text-emerald-600">
                                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500"></span> live
                            </span>
                        </p>
                        <div data-live-list class="max-h-96 space-y-0.5 overflow-y-auto p-2">
                            <p class="px-3 py-6 text-center text-xs text-slate-400">Memantau pesanan masuk…</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Pill tindak lanjut (semua ukuran; angka ikut live polling) --}}
            <div class="mt-3 flex items-center gap-2 overflow-x-auto no-scrollbar">
                <a href="{{ route('admin.orders.index', ['bayar' => 'pending']) }}"
                   class="flex shrink-0 items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] font-bold lg:py-1.5 text-amber-800 transition hover:bg-amber-100 active:scale-95">
                    <i data-lucide="clock" class="h-3.5 w-3.5"></i> Belum bayar
                    <span data-live-unpaid class="rounded-full bg-white px-1.5 font-black text-amber-700 ring-1 ring-amber-200">{{ $pendingOrders }}</span>
                </a>
                <a href="{{ route('admin.orders.index', ['status' => 'processing']) }}"
                   class="flex shrink-0 items-center gap-1.5 rounded-full border border-rose-200 bg-rose-50 px-3 py-2 text-[11px] font-bold lg:py-1.5 text-rose-800 transition hover:bg-rose-100 active:scale-95">
                    <i data-lucide="send" class="h-3.5 w-3.5"></i> Perlu kirim
                    <span data-live-awaiting class="rounded-full bg-white px-1.5 font-black text-rose-700 ring-1 ring-rose-200">{{ $awaitingOrders }}</span>
                </a>

                <a href="{{ route('home') }}" target="_blank"
                   class="ml-auto flex shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-2 text-[11px] font-bold lg:py-1.5 text-slate-600 transition hover:bg-slate-50 active:scale-95 lg:hidden">
                    <i data-lucide="store" class="h-3.5 w-3.5"></i> Lihat toko
                </a>
            </div>
        </header>

        @if(session('success') || session('error'))
            <div class="px-4 pt-4 lg:px-6">
                <div class="rounded-xl px-4 py-3 text-sm font-medium ring-1 {{ session('error') ? 'bg-rose-50 text-rose-800 ring-rose-200' : 'bg-emerald-50 text-emerald-800 ring-emerald-200' }}">
                    {{ session('error') ?: session('success') }}
                </div>
            </div>
        @endif

        <main class="flex-1 px-4 pb-24 pt-5 lg:px-6 lg:pb-5">
            @yield('content')
        </main>
    </div>
</div>

{{-- Bottom nav admin (mobile) --}}
<nav data-admin-bottomnav
     class="fixed inset-x-0 bottom-0 z-40 flex items-stretch gap-0.5 border-t border-slate-200 bg-white/95 px-1.5 pb-[env(safe-area-inset-bottom,0px)] pt-1.5 backdrop-blur lg:hidden"
     aria-label="Navigasi admin">
    @php($bawah = [
        [route('admin.dashboard'), 'layout-dashboard', 'Dashboard', 'admin.dashboard'],
        [route('admin.products.index'), 'package', 'Produk', 'admin.products.*'],
        [route('admin.orders.index'), 'receipt', 'Pesanan', 'admin.orders.*'],
        [route('admin.reviews.index'), 'star', 'Ulasan', 'admin.reviews.*'],
    ])
    @foreach($bawah as [$url, $ikon, $label, $aktifKalau])
        @php($aktif = request()->routeIs($aktifKalau))
        <a href="{{ $url }}" class="flex flex-1 flex-col items-center gap-0.5 rounded-xl px-1 py-1 text-[10px] font-bold transition">
            <span class="grid h-8 w-14 place-items-center rounded-xl {{ $aktif ? 'bg-brand-50 text-brand-600' : 'text-slate-500' }}">
                <i data-lucide="{{ $ikon }}" class="h-[18px] w-[18px]"></i>
            </span>
            <span class="{{ $aktif ? 'text-brand-600' : 'text-slate-500' }}">{{ $label }}</span>
        </a>
    @endforeach
    <button type="button" data-admin-nav-toggle
            class="flex flex-1 cursor-pointer flex-col items-center gap-0.5 rounded-xl px-1 py-1 text-[10px] font-bold transition active:scale-95"
            aria-label="Menu lainnya">
        <span class="grid h-8 w-14 place-items-center rounded-xl text-slate-500">
            <i data-lucide="menu" class="h-[18px] w-[18px]"></i>
        </span>
        <span class="text-slate-500">Lainnya</span>
    </button>
</nav>

</body>
</html>
