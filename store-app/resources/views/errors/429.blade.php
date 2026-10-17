@extends('layouts.storefront')

{{-- Halaman 429 bergaya storefront supaya pembeli paham apa yang terjadi:
    limit percobaan (throttle) berlaku per IP untuk aksi berulang seperti
    checkout/buka isi pesanan/lacak. Retry-After dikirim middleware. --}}

@php
    $retryAfter = (int) request()->headers->get('Retry-After', 600);
    $menit = max(1, (int) ceil($retryAfter / 60));
    $deadline = now()->addSeconds(min($retryAfter, 600));
@endphp

@section('title', 'Terlalu Sering — Tunggu Sebentar')

@section('content')
    <div class="mx-auto flex w-full max-w-md flex-col items-center px-4 py-10 text-center">
        <div class="grid h-16 w-16 place-items-center rounded-full bg-amber-100 text-amber-600">
            <i data-lucide="clock" class="h-8 w-8"></i>
        </div>

        <h1 class="mt-4 text-lg font-black text-slate-900">Terlalu sering mencoba</h1>
        <p class="mt-1.5 text-sm leading-relaxed text-slate-600">
            Permintaan Anda melebihi batas percobaan untuk beberapa menit terakhir.
            Ulangi lagi setelah {{ $menit }} menit supaya tidak dianggap aktivitas
            otomatis. Pesanan yang sudah selesai tidak terpengaruh.
        </p>

        <div class="mt-5 w-full rounded-2xl border border-amber-200 bg-amber-50 p-5">
            <p class="text-[11px] font-black uppercase tracking-wider text-amber-700">Coba lagi dalam</p>
            <p class="mt-1 font-mono text-3xl font-black tabular-nums text-amber-800" data-countdown="{{ $deadline->toISOString() }}"
               aria-label="hitung mundur">
                <span data-countdown-text>--:--</span>
            </p>
            <p class="mt-2 text-[11px] text-amber-700">
                Setelah hitungan habis, muat ulang halaman ini untuk melanjutkan.
            </p>
        </div>

        <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
            <a href="{{ route('home') }}" class="btn-primary">Kembali ke beranda</a>
            <a href="{{ route('orders.track.form') }}" class="btn-secondary">Lacak pesanan</a>
        </div>
        <p class="mt-4 text-[11px] text-slate-400">Kode: 429 · Batas percobaan dilindungi sistem keamanan toko.</p>
    </div>
@endsection
