@extends('layouts.storefront')

@section('title', 'Lacak Pesanan')

@section('content')
    <div class="mx-auto w-full max-w-lg">
        <div class="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h3 class="flex items-center gap-1.5 text-sm font-black text-slate-800">
                    <i data-lucide="receipt" class="h-4 w-4 text-[#f5125e]"></i> Riwayat Pesanan
                </h3>
                <a href="{{ route('home') }}" class="text-slate-400 transition-colors hover:text-slate-600" title="Kembali ke beranda">
                    <i data-lucide="x" class="h-5 w-5"></i>
                </a>
            </div>

            <div class="p-5">
                <p class="text-[11px] text-slate-500">
                    Masukkan nomor invoice dan nomor WhatsApp yang Anda pakai saat memesan untuk melihat pesanan
                    dan mengambil produk Anda.
                </p>

                @if(session('error'))
                    <div class="mt-3 flex items-start gap-2 rounded-2xl border border-rose-200 bg-rose-50 p-3.5 text-[11px] text-rose-800">
                        <i data-lucide="alert-circle" class="mt-0.5 h-4 w-4 flex-shrink-0"></i>
                        <span>{{ session('error') }}</span>
                    </div>
                @endif

                <form action="{{ route('orders.track') }}" method="POST" class="mt-3 space-y-3">
                    @csrf

                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-slate-500" for="invoice">Nomor Invoice</label>
                        <input id="invoice" name="invoice" value="{{ $invoice }}" required maxlength="32"
                               placeholder="INV261017XXXXX"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 font-mono text-xs font-bold uppercase tracking-tight text-slate-800 focus:border-[#f5125e] focus:outline-none">
                        @error('invoice')<p class="mt-1 text-[10px] font-medium text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-slate-500" for="whatsapp">Nomor WhatsApp</label>
                        <input id="whatsapp" name="whatsapp" value="{{ old('whatsapp') }}" required maxlength="24" inputmode="tel"
                               placeholder="0822 4389 0517"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-800 focus:border-[#f5125e] focus:outline-none">
                        @error('whatsapp')<p class="mt-1 text-[10px] font-medium text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit"
                            class="flex w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-[#f5125e] to-[#ff2b56] py-3 text-xs font-black uppercase tracking-wider text-white shadow-md transition-transform active:scale-95">
                        <i data-lucide="search" class="h-4 w-4"></i> Cari Pesanan
                    </button>

                    <p class="flex items-start gap-1.5 text-[9.5px] leading-relaxed text-slate-400">
                        <i data-lucide="shield-check" class="mt-0.5 h-3 w-3 flex-shrink-0"></i>
                        Demi keamanan, pencarian dibatasi 8 percobaan per 5 menit. Data pesanan hanya terbuka bila
                        nomor invoice dan WhatsApp cocok.
                    </p>
                </form>
            </div>

            @if($orders->isNotEmpty())
                <div class="border-t border-slate-100 bg-slate-50 p-4">
                    <p class="mb-2.5 flex items-center gap-1.5 text-[10px] font-black uppercase tracking-wide text-slate-500">
                        <i data-lucide="clock" class="h-3.5 w-3.5 text-[#f5125e]"></i> Pesanan tersimpan di perangkat ini
                    </p>
                    <div class="space-y-2">
                        @foreach($orders as $saved)
                            <a href="{{ route('orders.show', $saved->invoice_no) }}"
                               class="flex items-center justify-between gap-3 rounded-xl border border-slate-100 bg-white p-2.5 shadow-sm transition-all hover:border-rose-200">
                                <div class="min-w-0">
                                    <p class="font-mono text-[11px] font-bold text-slate-800">{{ $saved->invoice_no }}</p>
                                    <p class="truncate text-[10px] text-slate-400">
                                        {{ $saved->created_at->translatedFormat('d M Y') }} ·
                                        {{ $saved->items()->count() }} item · {{ rupiah($saved->total) }}
                                    </p>
                                </div>
                                <span class="flex-shrink-0 rounded-md px-2 py-0.5 text-[9.5px] font-bold {{ $saved->paymentBadge() }}">
                                    {{ $saved->paymentLabel() }}
                                </span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
