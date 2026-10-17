{{-- FAQ accordion, sama seperti situs asal --}}
<div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs sm:p-6">
    <div class="flex items-center gap-2 border-b border-slate-100 pb-3">
        <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-50 text-[#f5125e]">
            <i data-lucide="help-circle" class="h-4 w-4"></i>
        </div>
        <h3 class="text-sm font-extrabold text-slate-800 sm:text-base">Pertanyaan Sering Diajukan (FAQ)</h3>
    </div>

    <div class="space-y-2 text-xs">
        @foreach([
            ['⚡ Berapa lama waktu pengiriman layanan?', 'Sistem kami memproses transaksi seketika setelah pembayaran terkonfirmasi. Akses atau lisensi langsung siap digunakan.'],
            ['🛡️ Bagaimana ketentuan garansi jika akun berkendala?', 'Semua produk bergaransi penuh. Simpan PDF Invoice untuk klaim penggantian jika terjadi kendala pada layanan.'],
            ['💬 Bagaimana cara memesan dan bayar?', 'Pilih layanan, tentukan varian, lalu isi data yang dibutuhkan saat checkout. Pembayaran bisa lewat QRIS manual (verifikasi admin) atau QRIS otomatis lewat gateway.'],
            ['📄 Di mana saya melihat status pesanan?', 'Buka menu Riwayat Pesanan lalu masukkan nomor invoice dan nomor WhatsApp yang Anda pakai saat memesan.'],
        ] as [$question, $answer])
            <details class="group cursor-pointer rounded-xl border border-slate-100 bg-slate-50 p-3">
                <summary class="flex list-none items-center justify-between font-bold text-slate-800">
                    <span>{{ $question }}</span>
                    <i data-lucide="chevron-down" class="h-4 w-4 text-slate-400 transition-transform group-open:rotate-180"></i>
                </summary>
                <p class="mt-2 text-[11.5px] leading-relaxed text-slate-600">{{ $answer }}</p>
            </details>
        @endforeach
    </div>
</div>
