@extends('layouts.storefront')

@section('title', 'Pesanan '.$order->invoice_no)

@section('content')
    @php($paid = $order->isPaid())
    @php($batal = $order->isCancelled())
    @php($kedaluwarsa = ! $paid && ! $batal && $order->isExpired())
    @php($gagal = ! $paid && ! $batal && ! $kedaluwarsa && $order->payment_status === \App\Models\Order::PAYMENT_FAILED)
    @php($bisaDibayar = $order->bisaDibayar())
    @php($itemCount = $order->items->sum('qty'))
    @php($hasDelivered = $order->items->where('is_fulfilled', true)->isNotEmpty())
    @php($judul = match (true) {
        $paid => 'Pesanan Dibayar',
        $batal => 'Pesanan Dibatalkan',
        $kedaluwarsa => 'Pesanan Kedaluwarsa',
        $gagal => 'Pembayaran Gagal',
        default => 'Menunggu Pembayaran',
    })
    @php($ikon = match (true) {
        $paid => 'check-circle-2',
        $batal => 'x',
        $kedaluwarsa => 'clock',
        $gagal => 'alert-circle',
        default => 'clock',
    })
    @php($warnaKepala = match (true) {
        $paid => 'bg-emerald-500 text-white',
        $batal => 'bg-slate-500 text-white',
        $kedaluwarsa, $gagal => 'bg-rose-500 text-white',
        default => 'bg-amber-500 text-white',
    })

    <div class="mx-auto w-full max-w-2xl"
         data-order-live="{{ route('orders.status', $order->invoice_no) }}"
         data-live-key="{{ $order->payment_status }}|{{ $order->order_status }}|{{ $hasDelivered ? 1 : 0 }}|{{ $unlocked ? 1 : 0 }}">
        {{-- Remah roti --}}
        <nav class="mb-3 flex flex-wrap items-center gap-1.5 px-1 text-[11px] text-slate-500">
            <a href="{{ route('home') }}" class="hover:text-[#f5125e]">Beranda</a>
            <span>/</span>
            <a href="{{ route('orders.track.form') }}" class="hover:text-[#f5125e]">Pesanan</a>
            <span>/</span>
            <span class="font-semibold text-slate-700">{{ $order->invoice_no }}</span>
        </nav>

        <div class="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-2xl">
            {{-- Kepala: status pesanan --}}
            <div class="flex flex-col items-center border-b border-slate-100 bg-white p-5 text-center">
                <div class="mb-2 flex h-12 w-12 items-center justify-center rounded-full shadow-xs {{ $warnaKepala }}">
                    <i data-lucide="{{ $ikon }}" class="h-6 w-6"></i>
                </div>
                <p class="text-sm font-black text-slate-800">{{ $judul }}</p>
                <p class="mt-0.5 text-[11px] text-slate-500">
                    {{ $order->created_at->translatedFormat('d M Y, H:i') }} WIB · {{ $itemCount }} unit
                </p>

                <div class="mt-3 flex flex-wrap justify-center gap-2">
                    {{-- Saat batal/kedaluwarsa, label pembayaran sudah mewakili
                         status akhir — jangan tampilkan badge kembar. --}}
                    @unless($order->isCancelled())
                        <span class="badge {{ $order->paymentBadge() }}">{{ $order->paymentLabel() }}</span>
                    @endunless
                    <span class="badge {{ status_badge($order->order_status) }}">{{ $order->statusLabel() }}</span>
                </div>

                @if($bisaDibayar)
                    <a href="{{ route('orders.pay', $order->invoice_no) }}"
                       class="mt-3 flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 px-4 py-2.5 text-xs font-black text-white shadow-md transition-transform active:scale-95">
                        <i data-lucide="wallet" class="h-3.5 w-3.5"></i> Selesaikan Pembayaran
                    </a>
                @endif
            </div>

            <div class="space-y-3 p-5">
                {{-- Pesanan batal/kedaluwarsa: jelaskan kenapa tidak bisa dibayar
                     lagi dan arahkan ke admin, bukan menyisakan tombol bayar mati. --}}
                @if($batal || $kedaluwarsa || $gagal)
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3.5">
                        <p class="flex items-center gap-1.5 text-[11px] font-black uppercase tracking-wide text-slate-700">
                            <i data-lucide="{{ $batal ? 'x' : 'clock' }}" class="h-3.5 w-3.5"></i>
                            {{ $batal ? 'Pesanan Dibatalkan' : ($kedaluwarsa ? 'Batas Bayar Terlewat' : 'Pembayaran Gagal') }}
                        </p>
                        <p class="mt-1 text-[10.5px] leading-relaxed text-slate-600">
                            @if($batal)
                                Pesanan ini dibatalkan admin sehingga tidak bisa dibayar lagi. Stok yang
                                sempat direservasi sudah dikembalikan. Silakan buat pesanan baru atau
                                hubungi admin kalau ini keliru.
                            @elseif($kedaluwarsa)
                                Batas waktu pembayaran sudah lewat, jadi pesanan ditutup otomatis. Silakan
                                buat pesanan baru — stok akan dicek ulang saat checkout.
                            @else
                                Pembayaran sebelumnya gagal diproses. Silakan buat pesanan baru atau
                                hubungi admin untuk bantuan.
                            @endif
                        </p>
                        <div class="mt-2.5 flex flex-wrap gap-2">
                            <a href="{{ route('catalog') }}" class="btn-primary btn-sm">Belanja lagi</a>
                            @if($whatsapp !== '#')
                                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn-secondary btn-sm">
                                    <i data-lucide="message-circle" class="h-3.5 w-3.5"></i> Tanya admin
                                </a>
                            @endif
                        </div>
                    </div>
                @endif
                {{-- ============ Kunci isi pesanan (akun/kredensial) ============ --}}
                {{-- Isi pesanan TIDAK ikut dikirim ke browser sebelum pembeli
                     memverifikasi nomor WhatsApp pesanannya, supaya browser
                     yang dipakai bergantian tidak membocorkan akun orang lain. --}}
                @if($hasDelivered && ! $unlocked)
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-3.5">
                        <p class="flex items-center gap-1.5 text-[11px] font-black uppercase tracking-wide text-amber-800">
                            <i data-lucide="lock" class="h-3.5 w-3.5"></i> Isi Pesanan Terkunci
                        </p>
                        <p class="mt-1 text-[10.5px] leading-relaxed text-amber-900">
                            Demi keamanan, akun/isi pesanan tidak ditampilkan sebelum Anda memverifikasi
                            nomor WhatsApp yang dipakai saat memesan. Setelah terbuka, isi ini terkunci
                            ulang dalam {{ intdiv(App\Services\OrderAccess::UNLOCK_TTL, 60) }} menit.
                        </p>
                        <form method="POST" action="{{ route('orders.unlock', $order->invoice_no) }}"
                              class="mt-2.5 flex flex-col gap-2 sm:flex-row">
                            @csrf
                            <input type="tel" name="whatsapp" required maxlength="24" inputmode="tel" autocomplete="tel"
                                   placeholder="08xxxxxxxxxx" aria-label="Nomor WhatsApp saat memesan"
                                   class="field flex-1">
                            <button type="submit"
                                    class="flex items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 px-4 py-2.5 text-xs font-black text-white shadow-md transition-transform active:scale-95">
                                <i data-lucide="lock-open" class="h-3.5 w-3.5"></i> Buka Isi Pesanan
                            </button>
                        </form>
                    </div>
                @endif

                {{-- ==================== Item pesanan ==================== --}}
                @foreach($order->items as $item)
                    @php($contents = $unlocked ? $item->deliveredContents() : [])
                    @php($myInfo = $unlocked ? $item->requiredInfoPairs() : [])

                    <div class="space-y-2.5 rounded-2xl border border-slate-100 bg-slate-50 p-3.5">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-slate-800">{{ $item->product_name }}</p>
                                <p class="text-[10.5px] text-slate-500">{{ $item->variant_name }} × {{ $item->qty }}</p>
                            </div>
                            @if($order->isCancelled())
                                <span class="flex-shrink-0 rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">Dibatalkan</span>
                            @else
                                <span class="flex-shrink-0 rounded-md px-2 py-0.5 text-[10px] font-bold
                                             {{ $item->is_fulfilled ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                                    {{ $item->is_fulfilled ? 'Terkirim' : 'Belum terkirim' }}
                                </span>
                            @endif
                        </div>

                        @if($myInfo !== [])
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-[10.5px] text-slate-500">
                                @foreach($myInfo as $label => $value)
                                    <span>{{ $label }}: <span class="font-mono font-semibold text-slate-700">{{ $value }}</span></span>
                                @endforeach
                            </div>
                        @endif

                        @if($contents !== [])
                            {{-- Kotak akses produk, gaya kotak akun situs asal --}}
                            <div class="rounded-xl bg-slate-900 p-3">
                                <div class="mb-1.5 flex items-center justify-between">
                                    <p class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wide text-slate-400">
                                        <i data-lucide="package" class="h-3.5 w-3.5 text-emerald-400"></i> Isi Pesanan Anda
                                    </p>
                                    @if(count($contents) > 1)
                                        <button type="button" data-copy="{{ implode("\n", $contents) }}"
                                                class="flex items-center gap-1 rounded-lg bg-white/10 px-2 py-1 text-[9.5px] font-bold text-white transition-colors hover:bg-white/20">
                                            <i data-lucide="copy" class="h-3 w-3"></i> Salin Semua
                                        </button>
                                    @endif
                                </div>

                                @foreach($contents as $content)
                                    <div class="mt-1.5 flex items-start gap-2">
                                        <pre id="account-data" class="min-w-0 flex-1 whitespace-pre-wrap break-all font-mono text-[11px] leading-relaxed text-emerald-300">{{ $content }}</pre>
                                        <button type="button" data-copy="{{ $content }}"
                                                class="flex flex-shrink-0 items-center gap-1 rounded-lg bg-white/10 px-2 py-1 text-[9.5px] font-bold text-white transition-colors hover:bg-white/20">
                                            <i data-lucide="copy" class="h-3 w-3"></i> Salin
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @elseif($item->is_fulfilled && $unlocked)
                            <p class="rounded-xl border border-emerald-100 bg-emerald-50 p-3 text-[11px] text-emerald-800">
                                Item sudah ditandai terkirim oleh admin. Kalau isinya belum Anda terima, hubungi admin dengan nomor invoice ini.
                            </p>
                        @elseif($item->is_fulfilled)
                            <p class="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white p-3 text-[11px] font-semibold text-slate-500">
                                <i data-lucide="lock" class="h-3.5 w-3.5 flex-shrink-0 text-slate-400"></i>
                                Isi pesanan terkunci — buka dengan nomor WhatsApp Anda di atas.
                            </p>
                        @else
                            @php($instruction = $item->deliveryInstructionPlain())
                            <div class="rounded-xl border border-amber-100 bg-amber-50 p-3 text-[11px] text-amber-900">
                                @if($instruction)
                                    <div class="whitespace-pre-line">{!! safe_text($instruction) !!}</div>
                                @else
                                    <p>Menunggu pengiriman oleh admin.</p>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach

                {{-- ==================== Rincian biaya ==================== --}}
                <div class="space-y-1.5 rounded-2xl border border-slate-100 bg-slate-50 p-3.5 text-[11px]">
                    <p class="flex items-center gap-1.5 text-[11px] font-black uppercase tracking-wide text-slate-500">
                        <i data-lucide="receipt" class="h-3.5 w-3.5 text-[#f5125e]"></i> Rincian Pembayaran
                    </p>
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
                        <span class="text-[11px] font-black uppercase tracking-wide text-slate-500">Total</span>
                        <span class="font-black text-[#f5125e]">{{ rupiah($order->total) }}</span>
                    </div>
                </div>

                {{-- ==================== Data pembeli ==================== --}}
                <dl class="grid gap-3 rounded-2xl border border-slate-100 bg-white p-3.5 text-[11px] sm:grid-cols-2">
                    <div>
                        <dt class="text-[9.5px] font-bold uppercase tracking-wide text-slate-400">Pembeli</dt>
                        <dd class="font-semibold text-slate-800">{{ $order->buyer_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-[9.5px] font-bold uppercase tracking-wide text-slate-400">WhatsApp</dt>
                        <dd class="font-semibold text-slate-800">{{ mask_wa($order->buyer_wa) }}</dd>
                    </div>
                    <div>
                        <dt class="text-[9.5px] font-bold uppercase tracking-wide text-slate-400">Metode bayar</dt>
                        <dd class="font-semibold text-slate-800">
                            {{ $order->payment_method === \App\Models\Order::METHOD_TRIPAY ? 'Gateway otomatis' : 'QRIS manual' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[9.5px] font-bold uppercase tracking-wide text-slate-400">Invoice</dt>
                        <dd class="font-mono font-semibold text-slate-800">{{ $order->invoice_no }}</dd>
                    </div>
                </dl>

                {{-- ==================== Aksi ==================== --}}
                <div class="grid grid-cols-2 gap-2">
                    <a href="{{ route('orders.invoice', $order->invoice_no) }}"
                       class="flex items-center justify-center gap-1.5 rounded-2xl bg-gradient-to-r from-[#f5125e] to-[#ff2b56] py-3 text-xs font-bold text-white shadow-md transition-transform active:scale-95">
                        <i data-lucide="download" class="h-3.5 w-3.5"></i> Invoice
                    </a>
                    @if($whatsapp !== '#')
                        <a href="{{ $whatsapp }}" target="_blank" rel="noopener"
                           class="flex items-center justify-center gap-1.5 rounded-2xl bg-gradient-to-r from-emerald-500 to-emerald-600 py-3 text-xs font-bold text-white shadow-md transition-transform active:scale-95">
                            <i data-lucide="message-circle" class="h-3.5 w-3.5"></i> Chat Admin
                        </a>
                    @endif
                </div>

                <a href="{{ route('catalog') }}"
                   class="flex w-full items-center justify-center gap-1.5 rounded-2xl border border-rose-200 bg-white py-3 text-xs font-bold text-[#f5125e] transition-colors hover:bg-rose-50">
                    <i data-lucide="shopping-bag" class="h-3.5 w-3.5"></i> Belanja Lagi
                </a>

                {{-- ==================== Ulasan ==================== --}}
                @php($reviewable = $order->items->where('is_fulfilled', true)->pluck('product')->filter()->unique('id'))
                @if($reviewable->isNotEmpty())
                    <div class="rounded-2xl border border-rose-100 bg-rose-50/60 p-3.5">
                        <p class="flex items-center gap-1.5 text-[11px] font-black uppercase tracking-wide text-slate-600">
                            <i data-lucide="star" class="h-3.5 w-3.5 fill-amber-500 text-amber-500"></i> Beri Ulasan
                        </p>
                        <p class="mt-1 text-[10px] text-slate-500">Ulasan Anda membantu pembeli lain dan membuat layanan kami lebih baik.</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach($reviewable as $product)
                                <a href="{{ route('products.show', $product->slug) }}#ulasan"
                                   class="rounded-xl border border-rose-200 bg-white px-3 py-1.5 text-[10.5px] font-bold text-[#f5125e] transition-colors hover:bg-rose-50">
                                    Ulas {{ $product->name }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                <p class="flex items-center justify-center gap-1.5 text-[10px] text-slate-400">
                    <i data-lucide="clock" class="h-3 w-3"></i> Layanan admin aktif 08.00 – 22.00 WIB
                </p>
            </div>
        </div>
    </div>
@endsection
