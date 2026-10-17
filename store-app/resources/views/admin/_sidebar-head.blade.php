@php($logoKustom = \App\Models\Setting::hasCustomLogo())

<a href="{{ route('admin.dashboard') }}" class="mb-6 flex items-center gap-2.5 px-1">
    @if($logoKustom)
        <img src="{{ \App\Models\Setting::logoUrl() }}" alt="Logo {{ \App\Models\Setting::storeName() }}"
             class="h-10 w-10 shrink-0 rounded-2xl border border-slate-100 bg-white object-contain shadow-xs">
    @else
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-brand-500 to-rose-600 text-xs font-black tracking-tight text-white shadow-md"
              aria-label="Logo {{ \App\Models\Setting::storeName() }}">{{ \App\Models\Setting::logoMonogram() }}</span>
    @endif
    <span class="min-w-0">
        <span class="block text-sm font-black tracking-tight text-slate-900">Panel Admin</span>
        <span class="block truncate text-[10px] font-medium text-slate-400">{{ \App\Models\Setting::storeName() }}</span>
    </span>
</a>
