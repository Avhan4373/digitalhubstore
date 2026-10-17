{{--
    Kartu pesanan gaya Shopee di popup "Cek Transaksi" (salinan markup situs asal).

    Klik kartu / "Lihat Invoice" membuka lembar E-Invoice pada modal lapis kedua
    (`$sheetId`, satu lembar per kartu — lihat cek-transaksi.blade.php), persis
    seperti `viewInvoiceFromData()` di situs asal. Tanpa JS, tautannya tetap
    bekerja ke halaman pesanan/invoice milik session ini.

    Isi pesanan (akun/kredensial) tidak pernah ditampilkan kartu ini.
--}}
@props(['order', 'sheetId' => 'invoice-preview-modal'])

@php
    $milik = order_access()->has($order->invoice_no);
    // Pesanan batal tidak masuk tab "Menunggu / Proses" (sudah ditutup) maupun
    // "Selesai" — hanya tampil di tab "Semua" dengan label Dibatalkan.
    $statusTab = match (true) {
        $order->isCancelled() => 'batal',
        $order->isPaid() => 'done',
        default => 'pending',
    };
    $tanggal = $order->created_at->translatedFormat('d M Y').', '.$order->created_at->format('H.i');
    $produkPertama = $order->items->first()?->product;
    $totalQty = (int) $order->items->sum('qty');
@endphp

<div data-cek-card data-cek-status="{{ $statusTab }}"
     class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
    {{-- Toko & status --}}
    <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-3.5 py-2.5 text-xs">
        <div class="flex items-center gap-1.5 font-black text-slate-800">
            <i data-lucide="store" class="h-3.5 w-3.5 text-[#f5125e]"></i>
            <span>{{ \App\Models\Setting::storeName() }}</span>
            <span class="ml-1 hidden font-mono text-[9.5px] font-medium text-slate-400 sm:inline">#{{ $order->invoice_no }}</span>
        </div>
        <span class="text-[10px] font-black uppercase {{ $order->isPaid() ? 'text-emerald-600' : ($order->isCancelled() ? 'text-slate-500' : 'text-amber-600') }}">
            {{ $order->isPaid() ? 'Selesai' : $order->paymentLabel() }}
        </span>
    </div>

    {{-- Isi produk --}}
    <a href="{{ route('orders.show', $order->invoice_no) }}" data-modal-open="{{ $sheetId }}"
       class="flex gap-3 p-3.5">
        @include('components.partials.order-history-items', ['order' => $order])
    </a>

    {{-- Banner garansi --}}
    <div class="flex items-center justify-between border-t border-slate-100 bg-amber-50/40 px-3.5 py-2 text-[11px] text-amber-900">
        <div class="flex items-center gap-1.5 font-bold">
            <i data-lucide="shield-check" class="h-3.5 w-3.5"></i>
            <span>Garansi Resmi {{ \App\Models\Setting::storeName() }} Aktif</span>
        </div>
        <span class="text-[10px] font-medium text-slate-400">{{ $tanggal }}</span>
    </div>

    {{-- Total & tombol aksi --}}
    <div class="space-y-3 border-t border-slate-100 bg-white p-3.5">
        <div class="flex items-baseline justify-end gap-2 text-xs">
            <span class="text-[11px] text-slate-500">Total {{ $totalQty }} produk:</span>
            <span class="text-sm font-black text-[#f5125e]">{{ rupiah($order->total) }}</span>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 pt-1">
            {{-- Lihat invoice: buka lembar E-Invoice di modal lapis kedua;
                 tanpa JS tetap jalan ke halaman invoice milik session ini. --}}
            <a href="{{ route('orders.invoice', $order->invoice_no) }}" data-modal-open="{{ $sheetId }}"
               class="rounded-xl border border-slate-200 px-3 py-1.5 text-[11px] font-bold text-slate-700 transition hover:bg-slate-50 active:scale-95">
                Lihat Invoice
            </a>

            {{-- Beli lagi --}}
            @if($produkPertama)
                <a href="{{ route('products.show', $produkPertama->slug) }}"
                   class="rounded-xl border border-slate-200 px-3 py-1.5 text-[11px] font-bold text-slate-700 transition hover:bg-slate-50 active:scale-95">
                    Beli Lagi
                </a>
            @endif

            {{-- Nilai: hanya untuk pesanan yang tersimpan di perangkat ini,
                 karena ulasan wajib mencantumkan nomor invoice milik sendiri. --}}
            @if($milik && $produkPertama)
                <a href="{{ route('products.show', $produkPertama->slug) }}#ulasan"
                   class="rounded-xl bg-[#f5125e] px-4 py-1.5 text-[11px] font-bold text-white shadow-2xs transition hover:bg-[#d40c4e] active:scale-95">
                    Nilai
                </a>
            @endif
        </div>
    </div>
</div>
