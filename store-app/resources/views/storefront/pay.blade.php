@extends('layouts.storefront')

@section('title', 'Pembayaran '.$order->invoice_no)

@section('content')
    @php($paid = $order->isPaid())
    @php($gateway = $order->payment_method === \App\Models\Order::METHOD_TRIPAY)

    <div class="mx-auto w-full max-w-lg">
        <div class="overflow-hidden rounded-3xl border border-slate-200/80 bg-white text-center shadow-2xl">
            {{-- Kepala --}}
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h3 class="flex items-center gap-1.5 text-sm font-black text-slate-800">
                    <i data-lucide="wallet" class="h-4 w-4 text-[#f5125e]"></i> Kasir {{ \App\Models\Setting::storeName() }}
                </h3>
                <span class="rounded-full px-2.5 py-1 text-[9px] font-black uppercase tracking-widest
                             {{ $paid ? 'bg-emerald-50 text-emerald-700' : ($order->isCancelled() ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-700') }}">
                    {{ $paid ? 'Lunas' : ($order->isCancelled() ? 'Dibatalkan' : ($order->isExpired() ? 'Kedaluwarsa' : 'Menunggu Bayar')) }}
                </span>
            </div>

            <div class="space-y-3 p-5 text-left">
                {{-- Kotak invoice --}}
                <div class="relative overflow-hidden rounded-2xl border-2 border-slate-100 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50 p-3.5">
                        <div>
                            <span class="rounded-full bg-[#f5125e] px-2 py-0.5 text-[9px] font-black uppercase tracking-widest text-white shadow-xs">E-Invoice</span>
                            <p id="pay-invoice-no" class="mt-1 font-mono text-xs font-black tracking-tight text-slate-800 sm:text-sm">{{ $order->invoice_no }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Tanggal</p>
                            <p class="text-xs font-bold text-slate-700">{{ $order->created_at->translatedFormat('d M Y, H:i') }}</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between p-3.5">
                        <div class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                            {{ $order->items->sum('qty') }} unit · {{ $order->paymentLabel() }}
                        </div>
                        <div class="text-right">
                            <p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Total bayar</p>
                            <p class="text-base font-black text-[#f5125e] sm:text-lg">{{ rupiah($order->total) }}</p>
                        </div>
                    </div>
                </div>

                @if($paid)
                    {{-- ========================= Lunas ========================= --}}
                    <div class="flex flex-col items-center rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-center">
                        <div class="mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-500 text-white shadow-xs">
                            <i data-lucide="check-circle-2" class="h-6 w-6"></i>
                        </div>
                        <p class="text-sm font-black text-emerald-800">Pembayaran terverifikasi</p>
                        <p class="mt-1 text-[11px] text-emerald-700">
                            Produk Anda sudah dikirim ke halaman pesanan. Simpan invoice sebagai bukti &amp; syarat garansi.
                        </p>
                        <a href="{{ route('orders.show', $order->invoice_no) }}"
                           class="mt-3 flex items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-[#f5125e] to-[#ff2b56] px-4 py-2.5 text-xs font-black text-white shadow-md transition-transform active:scale-95">
                            <i data-lucide="package" class="h-3.5 w-3.5"></i> Lihat Produk &amp; Status
                        </a>
                    </div>
                @elseif(! $order->bisaDibayar())
                    {{-- ============ Pesanan batal / kedaluwarsa / gagal ============ --}}
                    {{-- Tanpa cabang ini, halaman kasir tetap menampilkan QRIS dan
                         hitung mundur untuk pesanan yang sudah dibatalkan admin. --}}
                    @php($batal = $order->isCancelled())
                    @php($lewat = ! $batal && $order->isExpired())
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-center">
                        <span class="mx-auto grid h-11 w-11 place-items-center rounded-full {{ $batal ? 'bg-slate-200 text-slate-600' : 'bg-rose-100 text-rose-600' }}">
                            <i data-lucide="{{ $batal ? 'x' : 'clock' }}" class="h-5 w-5"></i>
                        </span>
                        <p class="mt-2.5 text-sm font-black text-slate-800">
                            {{ $batal ? 'Pesanan Dibatalkan' : ($lewat ? 'Pesanan Kedaluwarsa' : 'Pembayaran Tidak Berhasil') }}
                        </p>
                        <p class="mx-auto mt-1 max-w-sm text-[11px] leading-relaxed text-slate-600">
                            {{ $batal
                                ? 'Pesanan ini dibatalkan admin, jadi pembayaran tidak bisa dilanjutkan. Stok yang sempat direservasi sudah dikembalikan ke toko.'
                                : ($lewat
                                    ? 'Batas waktu pembayaran sudah lewat sehingga pesanan ditutup otomatis dan stok dikembalikan.'
                                    : 'Pembayaran sebelumnya tidak berhasil diproses. Silakan buat pesanan baru.') }}
                        </p>
                        <div class="mt-3 flex flex-col justify-center gap-2 sm:flex-row">
                            <a href="{{ route('catalog') }}" class="btn-primary btn-sm">Belanja lagi</a>
                            @if($whatsapp !== '#')
                                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn-secondary btn-sm">
                                    <i data-lucide="message-circle" class="h-3.5 w-3.5"></i> Tanya admin
                                </a>
                            @endif
                        </div>
                    </div>
                @elseif($gateway)
                    {{-- ==================== QRIS otomatis (gateway) ==================== --}}
                    @php($data = $order->gateway_payload ?? [])

                    <div class="rounded-2xl border border-rose-100 bg-rose-50/60 p-3.5">
                        <p class="flex items-center gap-1.5 text-[11px] font-black uppercase tracking-wide text-rose-600">
                            <i data-lucide="shield-alert" class="h-3.5 w-3.5"></i> Selesaikan Pembayaran
                        </p>
                        <p class="mt-1 text-[11px] text-rose-700">
                            Selesaikan pembayaran sebelum batas waktu. Halaman ini menyegarkan sendiri saat pembayaran terdeteksi.
                        </p>
                        @if($order->expires_at)
                            <p class="mt-1.5 flex items-center gap-1 text-[11px] font-bold text-slate-600">
                                <i data-lucide="clock" class="h-3.5 w-3.5 text-rose-500"></i> Berakhir dalam:
                                <span data-countdown="{{ $order->expires_at->toIso8601String() }}">
                                    <span data-countdown-text class="font-black text-rose-600">--:--</span>
                                </span>
                            </p>
                        @endif
                    </div>

                    @if(! empty($data['pay_code']))
                        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-3.5">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Kode bayar / nomor VA</p>
                            <div class="mt-1 flex items-center justify-between gap-2">
                                <code class="text-base font-black text-slate-900">{{ $data['pay_code'] }}</code>
                                <button type="button" data-copy="{{ $data['pay_code'] }}"
                                        class="flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1.5 text-[10px] font-bold text-[#f5125e] transition-colors hover:bg-rose-50">
                                    <i data-lucide="copy" class="h-3 w-3"></i> Salin
                                </button>
                            </div>
                        </div>
                    @endif

                    <div class="rounded-2xl border border-slate-100 bg-white p-3.5 text-center">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Scan QRIS</p>
                        <div class="mt-2 flex justify-center">
                            @if(! empty($data['qr_url']))
                                <img src="{{ $data['qr_url'] }}" alt="QRIS pembayaran" class="h-56 w-56 rounded-xl bg-white object-contain ring-1 ring-slate-200">
                            @elseif(! empty($data['qr_string']))
                                <code class="block break-all rounded-xl bg-slate-50 p-3 text-[10px] ring-1 ring-slate-100">{{ $data['qr_string'] }}</code>
                            @else
                                <p class="rounded-xl bg-amber-50 p-3 text-[11px] text-amber-800">QR belum tersedia dari gateway.</p>
                            @endif
                        </div>
                        <p class="mt-2 text-[11px] text-slate-600">
                            Nominal: <span class="font-black text-slate-900">{{ rupiah($order->total) }}</span>
                        </p>
                    </div>

                    @if(! empty($data['instructions']))
                        <div class="rounded-2xl border border-slate-100 bg-white p-3.5">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Cara pembayaran</p>
                            <ol class="mt-2 space-y-1.5 text-[11px] text-slate-600">
                                @foreach(collect($data['instructions'])->flatten(1) as $step)
                                    <li class="flex gap-2">
                                        <span class="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-[#f5125e]"></span>
                                        <span>{{ is_array($step) ? implode(' ', $step) : $step }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endif

                    <div class="flex items-center gap-2 rounded-2xl border border-rose-100 bg-rose-50/60 p-3.5 text-[11px] text-slate-600"
                         data-poll-status="{{ route('orders.status', $order->invoice_no) }}" data-poll-interval="15000">
                        <i data-lucide="refresh-cw" class="h-4 w-4 flex-shrink-0 animate-spin text-[#f5125e]"></i>
                        <span>Menunggu pembayaran otomatis… Status saat ini: <b class="text-slate-800">{{ $order->paymentLabel() }}</b>.</span>
                    </div>
                @else
                    {{-- ====================== QRIS manual ====================== --}}
                    <div class="rounded-2xl border border-amber-200 bg-amber-50/70 p-3.5">
                        <p class="flex items-center gap-1.5 text-[11px] font-black uppercase tracking-wide text-amber-700">
                            <i data-lucide="shield-alert" class="h-3.5 w-3.5"></i> Wajib Sertakan Bukti Transfer
                        </p>
                        <p class="mt-1 text-[11px] text-amber-700">
                            Tanpa bukti transfer, pesanan tidak bisa diverifikasi admin. Mohon transfer <b>tepat</b> sejumlah nominal di bawah tanpa pembulatan.
                        </p>
                        @if($order->expires_at)
                            <p class="mt-1.5 flex items-center gap-1 text-[11px] font-bold text-slate-600">
                                <i data-lucide="clock" class="h-3.5 w-3.5 text-rose-500"></i> Bayar sebelum:
                                <span data-countdown="{{ $order->expires_at->toIso8601String() }}">
                                    <span data-countdown-text class="font-black text-rose-600">--:--</span>
                                </span>
                            </p>
                        @endif
                    </div>

                    <div class="rounded-2xl border border-slate-100 bg-white p-3.5 text-center">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Scan QRIS</p>

                        @if($qrisUrl)
                            <div class="mt-2 flex flex-col items-center">
                                <img src="{{ $qrisUrl }}" alt="QRIS pembayaran" class="h-64 w-64 rounded-xl bg-white object-contain ring-1 ring-slate-200">
                                @if($qrisHolder)
                                    <p class="mt-2 text-[11px] text-slate-500">a.n. {{ $qrisHolder }}</p>
                                @endif
                            </div>
                        @else
                            <p class="mt-2 rounded-xl bg-amber-50 p-3 text-[11px] text-amber-800">
                                Gambar QRIS belum diatur admin. Silakan hubungi admin untuk instruksi pembayaran.
                            </p>
                        @endif

                        <p class="mt-3 text-[11px] text-slate-600">
                            Nominal: <span class="font-black text-slate-900">{{ rupiah($order->total) }}</span>
                        </p>
                    </div>

                    @if($whatsapp !== '#')
                        <a href="{{ $whatsapp }}" target="_blank" rel="noopener"
                           class="flex w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-amber-500 to-orange-500 py-3.5 text-xs font-black uppercase tracking-wider text-white shadow-md transition-transform active:scale-95">
                            <i data-lucide="message-circle" class="h-4 w-4"></i> Saya Sudah Transfer (Manual)
                        </a>
                        <p class="text-center text-[10px] text-slate-400">
                            Sertakan nomor invoice <b class="text-slate-600">{{ $order->invoice_no }}</b> dan foto bukti transfer pada pesan Anda.
                        </p>
                    @endif

                    <div class="flex items-center gap-2 rounded-2xl border border-slate-100 bg-slate-50 p-3.5 text-[11px] text-slate-500"
                         data-poll-status="{{ route('orders.status', $order->invoice_no) }}" data-poll-interval="20000">
                        <i data-lucide="loader-2" class="h-4 w-4 flex-shrink-0 animate-spin text-[#f5125e]"></i>
                        <span>Pembayaran manual diverifikasi admin. Halaman ini menyegarkan sendiri setelah dikonfirmasi.</span>
                    </div>
                @endif

                {{-- ===================== Rincian pesanan ===================== --}}
                <div class="space-y-2 rounded-2xl border border-slate-100 bg-slate-50 p-3.5">
                    <p class="flex items-center gap-1.5 text-[11px] font-black uppercase tracking-wide text-slate-500">
                        <i data-lucide="receipt" class="h-3.5 w-3.5 text-[#f5125e]"></i> Rincian Pesanan
                    </p>

                    @foreach($order->items as $item)
                        <div class="flex justify-between gap-3 text-[11px]">
                            <span class="min-w-0 flex-1">
                                <span class="font-semibold text-slate-700">{{ $item->product_name }}</span>
                                <span class="text-slate-400">× {{ $item->qty }}</span>
                                <span class="block text-[10px] text-slate-400">{{ $item->variant_name }}</span>
                            </span>
                            <span class="flex-shrink-0 font-bold text-slate-700">{{ rupiah($item->subtotal) }}</span>
                        </div>
                    @endforeach

                    <div class="space-y-1 border-t border-slate-200 pt-2 text-[11px]">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Subtotal</span>
                            <span class="font-semibold text-slate-700">{{ rupiah($order->subtotal) }}</span>
                        </div>
                        @if($order->discount > 0)
                            <div class="flex justify-between text-emerald-700">
                                <span>Voucher {{ $order->coupon_code }}</span>
                                <span class="font-semibold">-{{ rupiah($order->discount) }}</span>
                            </div>
                        @endif
                        <div class="flex items-center justify-between border-t border-slate-200 pt-2">
                            <span class="text-[11px] font-black uppercase tracking-wide text-slate-500">Total Tagihan</span>
                            <span class="font-black text-[#f5125e]">{{ rupiah($order->total) }}</span>
                        </div>
                    </div>
                </div>

                {{-- Tombol invoice --}}
                <a href="{{ route('orders.invoice', $order->invoice_no) }}"
                   class="flex w-full items-center justify-center gap-2 rounded-2xl border border-rose-200 bg-white py-3 text-xs font-bold text-[#f5125e] transition-colors hover:bg-rose-50">
                    <i data-lucide="file-text" class="h-4 w-4"></i> Lihat / Unduh Invoice
                </a>

                <p class="flex items-center justify-center gap-1.5 text-[10px] text-slate-400">
                    <i data-lucide="clock" class="h-3 w-3"></i> Layanan admin aktif 08.00 – 22.00 WIB
                </p>
            </div>
        </div>
    </div>
@endsection
