{{-- Daftar tautan nav panel admin — dipakai oleh sidebar desktop dan drawer
    mobile biar tidak berbeda isi satu sama lain. --}}
<nav class="flex flex-1 flex-col gap-1">
    <a href="{{ route('admin.dashboard') }}"
       class="nav-link {{ request()->routeIs('admin.dashboard') ? 'nav-link-active' : '' }}">
        <i data-lucide="layout-dashboard" class="h-4 w-4"></i> Dashboard
    </a>

    <p class="mt-4 px-3 text-[11px] font-bold uppercase tracking-wider text-slate-400">Katalog</p>
    <a href="{{ route('admin.products.index') }}"
       class="nav-link {{ request()->routeIs('admin.products.*') || request()->routeIs('admin.variants.*') || request()->routeIs('admin.stock.*') ? 'nav-link-active' : '' }}">
        <i data-lucide="package" class="h-4 w-4"></i> Produk &amp; Varian
    </a>
    <a href="{{ route('admin.coupons.index') }}"
       class="nav-link {{ request()->routeIs('admin.coupons.*') ? 'nav-link-active' : '' }}">
        <i data-lucide="gift" class="h-4 w-4"></i> Voucher
    </a>

    <p class="mt-4 px-3 text-[11px] font-bold uppercase tracking-wider text-slate-400">Penjualan</p>
    <a href="{{ route('admin.orders.index') }}"
       class="nav-link {{ request()->routeIs('admin.orders.*') ? 'nav-link-active' : '' }}">
        <i data-lucide="receipt" class="h-4 w-4"></i> Pesanan
        @if($awaitingOrders + $pendingOrders > 0)
            <span class="ml-auto rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-700">{{ $pendingOrders + $awaitingOrders }}</span>
        @endif
    </a>
    <a href="{{ route('admin.reviews.index') }}"
       class="nav-link {{ request()->routeIs('admin.reviews.*') ? 'nav-link-active' : '' }}">
        <i data-lucide="star" class="h-4 w-4"></i> Ulasan
        @if($pendingReviews > 0)
            <span class="ml-auto rounded-full bg-brand-100 px-2 py-0.5 text-[10px] font-bold text-brand-700">{{ $pendingReviews }}</span>
        @endif
    </a>

    <p class="mt-4 px-3 text-[11px] font-bold uppercase tracking-wider text-slate-400">Sistem</p>
    @can('manage-settings')
        <a href="{{ route('admin.settings.edit') }}"
           class="nav-link {{ request()->routeIs('admin.settings.*') ? 'nav-link-active' : '' }}">
            <i data-lucide="settings" class="h-4 w-4"></i> Pengaturan
        </a>
    @endcan
    <a href="{{ route('admin.logs.index') }}"
       class="nav-link {{ request()->routeIs('admin.logs.*') ? 'nav-link-active' : '' }}">
        <i data-lucide="scroll-text" class="h-4 w-4"></i> Log Aktivitas
    </a>
    @can('manage-users')
        <a href="{{ route('admin.users.index') }}"
           class="nav-link {{ request()->routeIs('admin.users.*') ? 'nav-link-active' : '' }}">
            <i data-lucide="users" class="h-4 w-4"></i> Pengguna
        </a>
    @endcan
</nav>
