@extends('layouts.storefront')

@section('title', 'Kasir — Checkout')

@section('content')
    @php
        // Judul & keterangan tiap metode pembayaran (label asli dari controller
        // tetap jadi fallback kalau ada metode baru).
        $methodInfo = [
            'qris_manual' => ['QRIS Manual', 'Scan QRIS, lalu konfirmasi bukti transfer ke admin.', 'qr-code'],
            'tripay' => ['QRIS Otomatis (Tripay)', 'QRIS dibuat otomatis oleh gateway, pesanan langsung diproses.', 'zap'],
        ];
        $needInfo = $items->filter(fn ($row) => $row['variant']->requiredFieldLabels() !== []);
    @endphp

    <div class="mx-auto w-full max-w-lg">
        {{-- Form voucher berdiri sendiri: input-nya dipakai lewat atribut `form=`
             supaya tidak ada <form> bersarang (HTML tidak mengizinkan). --}}
        <form id="voucher-form" method="GET" action="{{ route('checkout.index') }}"></form>

        <div class="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-2xl">
            {{-- Kepala kasir --}}
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h3 class="flex items-center gap-1.5 text-sm font-black text-slate-800">
                    <i data-lucide="wallet" class="h-4 w-4 text-[#f5125e]"></i> Kasir {{ \App\Models\Setting::storeName() }}
                </h3>
                <a href="{{ route('cart.index') }}" class="text-slate-400 transition-colors hover:text-slate-600" title="Kembali ke keranjang">
                    <i data-lucide="x" class="h-5 w-5"></i>
                </a>
            </div>

            <form action="{{ route('checkout.store') }}" method="POST" class="flex flex-col p-5 text-left">
                @csrf

                @if($errors->any())
                    <div class="mb-3 rounded-2xl border border-rose-200 bg-rose-50 p-3.5 text-xs text-rose-800">
                        <p class="flex items-center gap-1.5 font-bold">
                            <i data-lucide="alert-circle" class="h-4 w-4"></i> Periksa kembali data Anda
                        </p>
                        <ul class="mt-1.5 list-inside list-disc space-y-0.5">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- ===================== Kartu invoice ===================== --}}
                <div class="relative mb-3 overflow-hidden rounded-2xl border-2 border-slate-100 bg-white text-left shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50 p-3.5">
                        <div>
                            <span class="rounded-full bg-[#f5125e] px-2 py-0.5 text-[9px] font-black uppercase tracking-widest text-white shadow-xs">E-Invoice</span>
                            <p class="mt-1 font-mono text-xs font-black tracking-tight text-slate-800 sm:text-sm">
                                Dibuat setelah pesanan disimpan
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Tanggal</p>
                            <p class="text-xs font-bold text-slate-700">{{ now()->translatedFormat('d M Y') }}</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between p-3.5">
                        <div class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                            {{ $items->count() }} item · {{ $items->sum('qty') }} unit
                        </div>
                        <div class="text-right">
                            <p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Total bayar</p>
                            <p class="text-base font-black text-[#f5125e] sm:text-lg">{{ rupiah($total) }}</p>
                        </div>
                    </div>
                </div>

                {{-- ===================== Data pembeli ===================== --}}
                <div class="space-y-3 rounded-2xl border border-slate-100 bg-white p-3.5 shadow-2xs">
                    <p class="flex items-center gap-1.5 text-[11px] font-black uppercase tracking-wide text-slate-500">
                        <i data-lucide="user" class="h-3.5 w-3.5 text-[#f5125e]"></i> Data Pembeli
                    </p>

                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-slate-500" for="buyer_name">Nama Lengkap</label>
                        <input id="buyer_name" name="buyer_name" value="{{ old('buyer_name', $buyer['buyer_name'] ?? '') }}"
                               required maxlength="60" autocomplete="name" placeholder="Contoh: Budi Santoso"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-800 focus:border-[#f5125e] focus:outline-none">
                        @error('buyer_name')<p class="mt-1 text-[10px] font-medium text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-slate-500" for="buyer_wa">Nomor WhatsApp (Aktif)</label>
                        <input id="buyer_wa" name="buyer_wa" value="{{ old('buyer_wa', $buyer['buyer_wa'] ?? '') }}"
                               required inputmode="tel" placeholder="0822 4389 0517"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-800 focus:border-[#f5125e] focus:outline-none">
                        <p class="mt-1 text-[9.5px] text-slate-400">Dipakai admin untuk mengirim produk &amp; mengecek riwayat pesanan.</p>
                        @error('buyer_wa')<p class="mt-1 text-[10px] font-medium text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-slate-500" for="buyer_email">Email (Opsional)</label>
                        <input id="buyer_email" name="buyer_email" type="email" autocomplete="email"
                               value="{{ old('buyer_email', $buyer['buyer_email'] ?? '') }}" placeholder="nama@email.com"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-800 focus:border-[#f5125e] focus:outline-none">
                        @error('buyer_email')<p class="mt-1 text-[10px] font-medium text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-slate-500" for="buyer_note">Catatan (Opsional)</label>
                        <textarea id="buyer_note" name="buyer_note" rows="2" maxlength="500" placeholder="Permintaan khusus untuk admin..."
                                  class="w-full resize-none rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-800 focus:border-[#f5125e] focus:outline-none">{{ old('buyer_note') }}</textarea>
                    </div>
                </div>

                {{-- ============ Data wajib per varian (dari server) ============ --}}
                @if($needInfo->isNotEmpty())
                    <div class="mt-3 space-y-3 rounded-2xl border border-amber-200 bg-amber-50/60 p-3.5">
                        <p class="flex items-center gap-1.5 text-[11px] font-black uppercase tracking-wide text-amber-700">
                            <i data-lucide="info" class="h-3.5 w-3.5"></i> Data yang dibutuhkan
                        </p>
                        <p class="text-[10px] text-amber-700">
                            Dipakai admin untuk memproses pesanan. Pastikan benar — salah isi bisa membuat pesanan gagal atau tertunda.
                        </p>

                        @foreach($needInfo as $row)
                            @php($variant = $row['variant'])
                            <div class="rounded-xl border border-amber-100 bg-white p-3">
                                <p class="text-[11px] font-bold text-slate-800">
                                    {{ $row['product']->name }}
                                    <span class="font-normal text-slate-500">— {{ $variant->name }}</span>
                                </p>

                                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                    @foreach($variant->requiredFieldLabels() as $i => $label)
                                        <div>
                                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-slate-500" for="ri-{{ $variant->id }}-{{ $i }}">{{ $label }}</label>
                                            <input id="ri-{{ $variant->id }}-{{ $i }}"
                                                   name="required_info[{{ $variant->id }}][{{ $i }}]"
                                                   value="{{ old('required_info.'.$variant->id.'.'.$i) }}"
                                                   required maxlength="160"
                                                   class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-800 focus:border-[#f5125e] focus:outline-none">
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- ===================== Rincian pesanan ===================== --}}
                <div class="mt-3 space-y-2 rounded-2xl border border-slate-100 bg-slate-50 p-3.5">
                    <p class="flex items-center gap-1.5 text-[11px] font-black uppercase tracking-wide text-slate-500">
                        <i data-lucide="receipt" class="h-3.5 w-3.5 text-[#f5125e]"></i> Rincian Pesanan
                    </p>

                    @foreach($items as $row)
                        <div class="flex justify-between gap-3 text-[11px]">
                            <span class="min-w-0 flex-1">
                                <span class="font-semibold text-slate-700">{{ $row['product']->name }}</span>
                                <span class="text-slate-400">× {{ $row['qty'] }}</span>
                                <span class="block text-[10px] text-slate-400">{{ $row['variant']->name }}</span>
                            </span>
                            <span class="flex-shrink-0 font-bold text-slate-700">{{ rupiah($row['subtotal']) }}</span>
                        </div>
                    @endforeach

                    <div class="space-y-1 border-t border-slate-200 pt-2 text-[11px]">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Subtotal</span>
                            <span class="font-semibold text-slate-700">{{ rupiah($subtotal) }}</span>
                        </div>
                        @if($discount > 0)
                            <div class="flex justify-between text-emerald-700">
                                <span>Voucher {{ $couponCode }}</span>
                                <span class="font-semibold">-{{ rupiah($discount) }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center justify-between border-t border-slate-200 pt-2">
                        <span class="text-[11px] font-black uppercase tracking-wide text-slate-500">Total Tagihan</span>
                        <span class="text-base font-black text-[#f5125e]">{{ rupiah($total) }}</span>
                    </div>
                </div>

                {{-- ===================== Voucher ===================== --}}
                <div class="mt-3 rounded-2xl border border-dashed border-rose-200 bg-rose-50/50 p-3.5">
                    <label class="mb-1 block text-[10px] font-bold uppercase tracking-wide text-slate-500" for="voucher">Kode Voucher</label>
                    <div class="flex gap-2">
                        <input id="voucher" form="voucher-form" name="voucher" value="{{ $couponCode }}" maxlength="40"
                               placeholder="HEMAT10"
                               class="w-full rounded-xl border border-rose-200 bg-white px-3 py-2 text-xs font-bold uppercase text-slate-800 focus:border-[#f5125e] focus:outline-none">
                        <button form="voucher-form" type="submit"
                                class="flex-shrink-0 rounded-xl border border-rose-200 bg-white px-3.5 py-2 text-xs font-bold text-[#f5125e] transition-colors hover:bg-rose-50">
                            Pakai
                        </button>
                    </div>
                    @if($couponMessage)
                        <p class="mt-1 text-[10px] font-medium text-rose-600">{{ $couponMessage }}</p>
                    @endif
                    @if($discount > 0)
                        <p class="mt-1 text-[10px] font-medium text-emerald-600">Voucher {{ $couponCode }} aktif, potongan {{ rupiah($discount) }}.</p>
                    @endif
                </div>

                {{-- ===================== Metode pembayaran ===================== --}}
                <div class="mt-3">
                    <p class="mb-2 text-[11px] font-black uppercase tracking-wide text-slate-500">Metode Pembayaran</p>

                    <div class="space-y-2">
                        @foreach($methods as $value => $label)
                            @php($info = $methodInfo[$value] ?? [$label, '', 'qr-code'])
                            <label class="flex cursor-pointer items-start gap-3 rounded-2xl border-2 border-slate-100 bg-white p-3.5 transition-all hover:border-rose-200 has-[:checked]:border-[#f5125e] has-[:checked]:bg-rose-50">
                                <input type="radio" name="payment_method" value="{{ $value }}" required
                                       class="sr-only" @checked(old('payment_method', array_key_first($methods)) === $value)>
                                <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-rose-50 text-[#f5125e]">
                                    <i data-lucide="{{ $info[2] }}" class="h-4 w-4"></i>
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-xs font-bold text-slate-800">{{ $info[0] }}</span>
                                    <span class="block text-[10px] text-slate-500">{{ $info[1] ?: $label }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('payment_method')<p class="mt-1 text-[10px] font-medium text-rose-600">{{ $message }}</p>@enderror
                </div>

                {{-- honeypot anti-bot: tidak terlihat manusia, wajib kosong --}}
                <div class="hidden" aria-hidden="true">
                    <label for="website">Website</label>
                    <input id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <button type="submit"
                        class="mt-4 flex w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-[#f5125e] to-[#ff2b56] py-3.5 text-xs font-black uppercase tracking-wider text-white shadow-md transition-transform active:scale-95">
                    <i data-lucide="shield-check" class="h-4 w-4"></i> Buat Pesanan &amp; Tampilkan QRIS
                </button>

                <p class="mt-3 flex items-start gap-1.5 text-[9.5px] leading-relaxed text-slate-400">
                    <i data-lucide="info" class="mt-0.5 h-3 w-3 flex-shrink-0"></i>
                    Total akhir dihitung ulang di server saat pesanan dibuat, jadi angka di halaman ini tidak bisa dimanipulasi dari browser.
                </p>
            </form>
        </div>
    </div>
@endsection
