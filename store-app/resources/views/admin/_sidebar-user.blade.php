<div class="mt-4 border-t border-slate-200 pt-4">
    <div class="flex items-center gap-2.5 px-1">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-50 text-xs font-black uppercase text-brand-600 ring-1 ring-brand-100">
            {{ mb_substr($user?->name ?? 'A', 0, 1) }}
        </span>
        <span class="min-w-0">
            <span class="block truncate text-sm font-bold text-slate-800">{{ $user?->name }}</span>
            <span class="block truncate text-[11px] font-semibold uppercase text-slate-400">{{ $user?->role }}</span>
        </span>
    </div>
    <div class="mt-2 flex gap-2 px-1">
        <a href="{{ route('home') }}" target="_blank" class="btn-secondary btn-sm flex-1">Lihat toko</a>
        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50">Keluar</button>
        </form>
    </div>
</div>
