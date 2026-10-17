{{-- Panel 3 keunggulan toko, gaya situs asal --}}
<div class="grid grid-cols-3 gap-2 rounded-2xl border border-rose-100/60 bg-white p-3 text-center text-xs shadow-2xs sm:p-4">
    <div class="flex cursor-pointer items-center justify-center gap-2.5 border-r border-slate-100"
         role="button" tabindex="0" data-toast="Sistem Pembayaran QRIS Aktif 24 Jam">
        <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl bg-rose-50 text-[#f5125e]">
            <i data-lucide="qr-code" class="h-4 w-4"></i>
        </div>
        <div class="hidden text-left sm:block">
            <p class="font-bold leading-tight text-slate-800">GoPay QRIS</p>
            <p class="text-[10px] text-slate-400">Mudah &amp; Cepat</p>
        </div>
    </div>

    <a href="{{ route('orders.track.form') }}" data-modal-open="cek-modal"
       class="flex cursor-pointer items-center justify-center gap-2.5 border-r border-slate-100">
        <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
            <i data-lucide="receipt" class="h-4 w-4"></i>
        </div>
        <div class="hidden text-left sm:block">
            <p class="font-bold leading-tight text-slate-800">Cek Transaksi</p>
            <p class="text-[10px] text-slate-400">Lihat riwayat pesanan</p>
        </div>
    </a>

    <div class="flex cursor-pointer items-center justify-center gap-2.5"
         role="button" tabindex="0" data-toast="Semua layanan bergaransi penuh">
        <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
            <i data-lucide="shield-check" class="h-4 w-4"></i>
        </div>
        <div class="hidden text-left sm:block">
            <p class="font-bold leading-tight text-slate-800">Garansi 100%</p>
            <p class="text-[10px] text-slate-400">Layanan resmi &amp; terpercaya</p>
        </div>
    </div>
</div>
