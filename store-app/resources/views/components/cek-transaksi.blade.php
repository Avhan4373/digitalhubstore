{{--
    ==========================================================================
    Popup "Cek Transaksi" / "Pesanan Saya" — disalin dari situs asal
    (`history-modal` + lembar E-Invoice per kartu, dua lapis).

    Bedanya dengan situs asal (lihat ../ANALYSIS.md):
      - pencarian hanya lewat NOMOR INVOICE dan diproses di server
        (`POST /cek-transaksi`, dibatasi 8 percobaan / 5 menit per IP).
        Situs asal mencari lewat nomor WhatsApp (`GET /orders?wa=`) sehingga
        siapa pun yang tahu nomor WA orang lain bisa membaca riwayatnya;
      - data pesanan tidak dititipkan sebagai base64 di dalam HTML, dan
        email/WhatsApp pembeli disamarkan (`mask_email()`, `mask_wa()`);
      - isi pesanan (akun/kredensial) TIDAK pernah dirender di sini, hanya
        lembar pembelian. Membuka akun tetap butuh invoice + nomor WhatsApp.
    ==========================================================================
--}}
@php
    $cekInvoice = session('cek_hasil');
    $cekOrder = $cekInvoice
        ? \App\Models\Order::query()->with('items.product')->where('invoice_no', $cekInvoice)->first()
        : null;
    $cekRiwayat = ($cekOrder ? collect([$cekOrder]) : order_access()->orders(5))
        ->each(fn (\App\Models\Order $order) => $order->loadMissing('items.product'));
    $cekTerbuka = $cekOrder !== null || session('cek_error') !== null;
@endphp

{{-- ============================ popup riwayat ============================ --}}
<div id="cek-modal" class="fixed inset-0 z-[60] hidden items-center justify-center overflow-hidden p-2 sm:p-4"
     data-auto-open="{{ $cekTerbuka ? '1' : '0' }}">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-2xs" data-modal-close="cek-modal"></div>

    <div class="relative z-10 flex h-[94vh] w-full max-w-xl flex-col overflow-hidden rounded-3xl border border-slate-200/80 bg-slate-100 shadow-2xl sm:h-auto sm:max-h-[92vh]">
        {{-- Kepala --}}
        <div class="flex flex-shrink-0 items-center justify-between border-b border-slate-200 bg-white px-4 py-3.5">
            <div class="flex items-center gap-2">
                <button type="button" data-modal-close="cek-modal" aria-label="Tutup"
                        class="-ml-1 cursor-pointer rounded-full p-1 text-slate-600 transition-colors hover:text-slate-900">
                    <i data-lucide="arrow-left" class="h-4 w-4"></i>
                </button>
                <h3 class="text-sm font-extrabold tracking-tight text-slate-800 sm:text-base">Pesanan Saya</h3>
            </div>
            <button type="button" data-modal-close="cek-modal" aria-label="Tutup"
                    class="cursor-pointer p-1 text-slate-400 transition-colors hover:text-slate-600">
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>
        </div>

        {{-- Pencarian nomor invoice --}}
        <div class="flex-shrink-0 border-b border-slate-200/70 bg-white px-4 py-3">
            <form action="{{ route('orders.check') }}" method="POST" class="flex gap-2">
                @csrf

                <div class="relative flex-1">
                    <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
                    <input type="text" name="invoice" value="{{ old('invoice', $cekOrder?->invoice_no) }}"
                           required maxlength="32" autocomplete="off" aria-label="Nomor invoice"
                           placeholder="Masukkan nomor invoice..."
                           class="w-full rounded-xl border border-slate-200 bg-slate-100 py-2 pl-9 pr-3 text-xs font-semibold text-slate-800 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#f5125e]">
                </div>

                <button type="submit"
                        class="flex flex-shrink-0 cursor-pointer items-center gap-1 rounded-xl bg-gradient-to-r from-[#f5125e] to-[#ff2b56] px-4 py-2 text-xs font-bold text-white shadow-xs transition-all hover:opacity-95">
                    <span>Cari</span>
                </button>
            </form>

            @if(session('cek_error'))
                <p class="mt-2 flex items-start gap-1.5 rounded-lg bg-rose-50 px-2.5 py-2 text-[11px] font-semibold text-rose-700">
                    <i data-lucide="triangle-alert" class="mt-px h-3.5 w-3.5 flex-shrink-0"></i>
                    <span>{{ session('cek_error') }}</span>
                </p>
            @endif
        </div>

        {{-- Tab filter status (gaya Shopee) --}}
        <div class="grid flex-shrink-0 grid-cols-4 border-b border-slate-200 bg-white text-center text-xs font-bold text-slate-500">
            <button type="button" data-cek-tab="all"
                    class="cursor-pointer border-b-2 border-[#f5125e] py-3 text-[#f5125e] transition-colors">Semua</button>
            <button type="button" data-cek-tab="pending"
                    class="cursor-pointer border-b-2 border-transparent py-3 transition-colors hover:text-slate-800">Proses</button>
            <button type="button" data-cek-tab="done"
                    class="cursor-pointer border-b-2 border-transparent py-3 transition-colors hover:text-slate-800">Selesai</button>
            <button type="button" data-cek-tab="batal"
                    class="cursor-pointer border-b-2 border-transparent py-3 transition-colors hover:text-slate-800">Batal</button>
        </div>

        {{-- Daftar --}}
        <div class="no-scrollbar flex-1 space-y-3.5 overflow-y-auto p-3 sm:p-4">
            @forelse($cekRiwayat as $riwayat)
                {{-- Setiap kartu punya lembar E-Invoice sendiri (klik kartu /
                     "Lihat Invoice" membuka lembar, tanpa pindah halaman). --}}
                <x-order-history-card :order="$riwayat" :sheet-id="'invoice-preview-modal-'.$loop->index" />
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white/70 p-6 text-center">
                    <i data-lucide="search-x" class="mx-auto h-6 w-6 text-slate-300"></i>
                    <p class="mt-2 text-xs font-semibold text-slate-500">
                        Masukkan nomor invoice untuk menampilkan lembar pembelian Anda.
                    </p>
                </div>
            @endforelse

            <p class="pt-1 text-center text-[10.5px] leading-relaxed text-slate-400">
                Isi akun tidak ditampilkan di sini.
                <a href="{{ route('orders.track.form') }}" class="font-bold text-[#f5125e] hover:underline">Lacak lengkap</a>
                dengan nomor invoice + nomor WhatsApp pesanan.
            </p>
        </div>
    </div>
</div>

@foreach($cekRiwayat as $riwayat)
    {{-- ====================== lembar E-invoice per kartu (lapis 2) ======================
         Dirender dari server untuk pesanan milik session ini / hasil pencarian.
         Isi akun tidak pernah ada di lembar ini — pencarian hanya nomor invoice.
         Saat ada hasil pencarian, lembar pesanan yang ditemukan terbuka sendiri. --}}
    <div id="invoice-preview-modal-{{ $loop->index }}"
         class="cek-sheet-modal fixed inset-0 z-[85] hidden items-center justify-center overflow-hidden p-3 sm:p-4"
         data-auto-open="{{ $cekOrder !== null && $loop->first ? '1' : '0' }}">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-2xs"
             data-modal-close="invoice-preview-modal-{{ $loop->index }}"></div>

        <div class="cek-sheet-card relative z-10 flex max-h-[92vh] w-full max-w-lg flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50 p-3.5 sm:p-4" data-sheet-chrome>
                <h3 class="flex items-center gap-1.5 text-xs font-bold text-slate-800 sm:text-sm">
                    <i data-lucide="file-text" class="h-4 w-4 text-[#f5125e]"></i> Lembar Invoice Pembelian
                </h3>
                <button type="button" data-modal-close="invoice-preview-modal-{{ $loop->index }}" aria-label="Tutup"
                        class="cursor-pointer p-1 text-slate-400 transition-colors hover:text-slate-600">
                    <i data-lucide="x" class="h-4 w-4"></i>
                </button>
            </div>

            <div class="cek-sheet-content overflow-y-auto p-4 text-xs sm:p-6 sm:text-sm">
                <x-cek-einvoice :order="$riwayat" />
            </div>

            <div class="flex gap-2 border-t border-slate-100 bg-slate-50 p-3" data-sheet-chrome>
                <button type="button" data-modal-close="invoice-preview-modal-{{ $loop->index }}"
                        class="flex-1 cursor-pointer rounded-xl bg-slate-200 py-2.5 text-xs font-bold text-slate-700 transition hover:bg-slate-300">
                    Tutup
                </button>
                <button type="button" data-print-sheet
                        class="flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-[#f5125e] to-[#ff2b56] py-2.5 text-xs font-bold text-white shadow-md transition active:scale-95">
                    <i data-lucide="download" class="h-3.5 w-3.5"></i> Unduh PDF
                </button>
            </div>
        </div>
    </div>
@endforeach
