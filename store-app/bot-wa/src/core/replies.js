/**
 * Penyusun teks balasan bot.
 *
 * Semua fungsi di sini murni (input data -> string), jadi bisa diuji tanpa
 * Chrome maupun jaringan. Tidak ada angka yang dihitung ulang di sini: harga
 * dan stok datang dari API Laravel.
 */
import { angka, escapeWa, hurufMiring, potong, rentangHarga, rupiah } from './format.js';

const GARIS = '━━━━━━━━━━━━━━━';

/**
 * Nama hari/bulan Indonesia untuk header katalog.
 * `Intl` dengan locale `id-ID` tersedia di Node 18+ tanpa data tambahan.
 */
const HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const BULAN = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

/** "Selamat Siang 🌝" — pagi 🌅 / siang 🌝 / sore 🌆 / malam 🌙 (WIB). */
export function sapaanWaktu(date = new Date()) {
    const jamWIB = Number(
        new Intl.DateTimeFormat('id-ID', {
            hour: 'numeric',
            hour12: false,
            timeZone: 'Asia/Makassar',
        }).format(date),
    );

    if (jamWIB >= 4 && jamWIB < 11) return 'Selamat Pagi 🌅';
    if (jamWIB >= 10 && jamWIB < 15) return 'Selamat Siang 🌝';
    if (jamWIB >= 15 && jamWIB < 19) return 'Selamat Sore 🌆';

    return 'Selamat Malam 🌙';
}

/** Tanggal & jam WIB untuk header katalog, mis. "18 Oktober 2026" / "12:29:43 WIB". */
export function tanggalWaktuWIB(date = new Date()) {
    const pemformat = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Makassar',
        weekday: 'short',
        day: 'numeric',
        month: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: 'numeric',
        second: 'numeric',
        hour12: false,
    });

    const bagian = Object.fromEntries(
        pemformat.formatToParts(date).map((p) => [p.type, p.value]),
    );

    const tanggal = `${bagian.day} ${BULAN[Number(bagian.month) - 1]} ${bagian.year}`;
    const jam = `${bagian.hour}:${bagian.minute}:${bagian.second} WIB`;
    const hari = HARI.find(
        (h) => h.toLowerCase().startsWith(String(bagian.weekday || '').toLowerCase()),
    ) || '';

    return { tanggal, jam, hari };
}

/** Alias supaya pemanggil bisa memakai R.rupiahRange tanpa impor terpisah. */
export const rupiahRange = rentangHarga;

export function sambutan(store) {
    return [
        `Halo! 👋 Selamat datang di *${store.name}*`,
        store.tagline ? `_${store.tagline}_` : null,
        '',
        'Cara pesan:',
        '1. Ketik *list*',
        '2. Ketik *nama layanan* (mis. canva)',
        '3. Balas *nama paket* atau nomornya',
        '4. Bayar lewat QRIS yang dikirim',
        '5. Kirim bukti transfer',
        '',
        'Ketik *list* untuk mulai 🛒',
    ]
        .filter((baris) => baris !== null)
        .join('\n')
        .trim();
}

/** Sapaan saat pembeli sedang di tengah alur pesanan. */
export function sapaanSingkat() {
    return [
        'Halo! 👋 Lanjutkan pilihan Anda, atau ketik:',
        '• *batal* — hentikan proses ini',
        '• *list* — kembali ke katalog',
    ].join('\n');
}

export function bantuan(store) {
    return [
        '💬 *Bantuan*',
        GARIS,
        '',
        '*Cara pesan:*',
        '1. *list* — lihat katalog',
        '2. Ketik *nama layanan*',
        '3. Balas *nama paket* atau nomornya',
        '4. Bayar lewat QRIS',
        '5. Kirim bukti transfer',
        '',
        '*Perintah lain:*',
        '• *status* — cek pesanan',
        '• *riwayat* — daftar pesanan Anda',
        '• *batal* — batalkan proses',
        '',
        '*Butuh bantuan?*',
        `Chat admin: wa.me/${store.whatsapp}`,
    ].join('\n');
}

/**
 * Daftar katalog bergaya "LIST MENU STORE" — header sapaan + tanggal/jam WIB,
 * lalu satu nama layanan per baris tanpa nomor urut. Pembeli membalas dengan
 * NAMA layanan (mis. "netflix"); pencarian nama sudah ada di `engine.js`
 * (`cariProduk`) dan teks hasil render tetap nama asli dari server.
 *
 * @param {{name: string}} store
 * @param {Array<{name: string, products: Array}>} categories
 * @param {object} [opsi]
 * @param {Date} [opsi.sekarang] untuk uji: tanggal/jam tetap
 */
export function katalog(store, categories, opsi = {}) {
    const { tanggal, jam } = tanggalWaktuWIB(opsi.sekarang);
    const namaRules = (opsi.namaToko || store.name || 'STORE').toUpperCase();

    const baris = [
        '「 𝗟𝗜𝗦𝗧 𝗠𝗘𝗡𝗨 𝗦𝗧𝗢𝗥𝗘 」',
        'ᯤ.﹀﹀﹀﹀﹀﹀﹀﹀﹀.ᯤ',
        `⊱┊ *${sapaanWaktu(opsi.sekarang)}*`,
        `⊱┊ *Tanggal : ${tanggal}*`,
        `⊱┊ *Jam : ${jam}*`,
        '        ﹉﹉﹉﹉﹉﹉﹉﹉',
    ];

    for (const kategori of categories) {
        const produk = kategori.products || [];
        if (produk.length === 0) continue;

        for (const p of produk) {
            baris.push(`𐚁๋࣭⭑  ${escapeWa(p.name).toUpperCase()}`);
        }
    }

    baris.push(
        '',
        `⋆ 𝙍u𝙡e𝙨 *${escapeWa(namaRules)}*`,
        'ⓘ 𝙠e𝙩i𝙠 𝙣a𝙢a 𝙡a𝙮a𝙣a𝙣 𝙙ia𝙩a𝙨',
        'ⓘ 𝙙i𝙡a𝙧a𝙣g 𝙨p𝙖m 𝙗o𝙩',
        `ⓘ 𝙩r𝙖n𝙨a𝙠𝙨i 𝙝a𝙣y𝙖 𝙠e𝙥a𝙙a 𝙖d𝙢i𝙣 𝙜r𝙤𝙪𝙥 *!*`,
        'ⓘ 𝙨e𝙬a 𝙗o𝙩 𝙠e𝙩i𝙠 .𝙤w𝙣e𝙧',
    );

    return baris.join('\n');
}

/**
 * Detail satu produk + daftar paket, gaya "menu toko": judul produk huruf
 * besar, lalu tiap paket dalam bentuk "Nama : *harga*" — harga tanpa "Rp",
 * diikuti catatan data wajib (`required_fields`) berhuruf miring matematika.
 * Paket dipilih pembeli lewat NAMA paket (angka tetap diterima sebagai
 * kompatibilitas; pemilihan nama ditangani `cariVarian()` di engine).
 *
 * Harga tetap datang dari API Laravel — tidak ada angka yang dihitung di sini.
 *
 * @param {object} produk
 * @param {Array} varian
 */
export function detailProduk(produk, varian) {
    if (varian.length === 0) {
        return [
            `*${escapeWa(produk.name).toUpperCase()}*`,
            '',
            '⚠️ Belum ada paket tersedia.',
            'Ketik *bantuan* untuk tanya admin.',
        ].join('\n');
    }

    const baris = [`*${escapeWa(produk.name).toUpperCase()}*`, ''];

    for (const v of varian) {
        // Nama yang tampil di bot bisa dibedakan dari web lewat bot_label
        // (server). Pesanan tetap dibuat berdasarkan id varian, jadi nama web
        // tetap masuk order_items.
        const nama = escapeWa(v.bot_label || v.name);
        baris.push(`${nama} : *${angka(v.price)}*${v.purchasable ? '' : ' ❌'}`);

        for (const label of v.required_fields || []) {
            baris.push(`* ${hurufMiring(label)}`);
        }
    }

    return baris.join('\n');
}

/**
 * Ringkasan pesanan setelah dibuat + instruksi bayar.
 */
export function pesananDibuat(pesanan, pembayaran, store) {
    const baris = [
        '✅ *Pesanan dibuat*',
        GARIS,
        '',
        `🧾 Invoice: *${pesanan.invoice}*`,
        '',
        '*Rincian:*',
    ];

    for (const item of pesanan.items) {
        baris.push(`• ${item.product_name} — ${item.variant_name}`);
        baris.push(`   ${item.qty} × ${rupiah(item.subtotal / item.qty)} = ${rupiah(item.subtotal)}`);
    }

    baris.push('', `💰 *Total: ${rupiah(pesanan.total)}*`, '', GARIS, '', '💳 *Cara bayar:*');

    if (pembayaran.qris_url) {
        baris.push('Scan QRIS yang saya kirim setelah pesan ini.');
        if (pembayaran.holder) baris.push(`Atas nama: ${pembayaran.holder}`);
        baris.push('', 'Bayar sesuai total di atas.');
    } else {
        baris.push(
            'QRIS belum tersedia. Hubungi admin untuk instruksi pembayaran:',
            `wa.me/${store.whatsapp}`,
        );
    }

    if (pesanan.expires_at) {
        const menit = Math.max(0, Math.round((new Date(pesanan.expires_at) - Date.now()) / 60000));
        baris.push('', `⏳ Bayar dalam *${menit} menit* atau pesanan dibatalkan otomatis.`);
    }

    baris.push(
        '',
        GARIS,
        '',
        '📸 *Setelah bayar,* kirim bukti transfer ke chat ini.',
        'Admin akan memverifikasi, lalu isi akun Anda dikirim otomatis.',
    );

    return baris.join('\n').trim();
}

/** Konfirmasi bukti transfer diterima. */
export function buktiDiterima(adaPesanan) {
    if (!adaPesanan) {
        return [
            '📸 Terima kasih atas buktinya.',
            '',
            'Namun saya tidak menemukan pesanan yang menunggu pembayaran dari nomor ini.',
            '',
            'Ketik *riwayat* untuk melihat pesanan Anda.',
        ].join('\n');
    }

    return [
        '📸 *Bukti transfer diterima!*',
        '',
        'Pesanan Anda sedang diverifikasi admin.',
        'Isi akun akan dikirim otomatis begitu pembayaran dinyatakan lunas.',
        '',
        'Terima kasih 🙏',
    ].join('\n');
}

/**
 * Isi akun setelah pesanan selesai (dikirim otomatis oleh poller).
 */
export function pesananSelesai(pesanan, isi) {
    const baris = [
        '🎉 *Pesanan selesai!*',
        GARIS,
        '',
        `🧾 *${pesanan.invoice}*`,
        `Status: *${pesanan.status_label}*`,
        '',
        '🔑 *Isi akun Anda:*',
        '',
    ];

    for (const grup of isi) {
        baris.push(`*${grup.product_name} — ${grup.variant_name}*`);
        for (const konten of grup.contents) {
            baris.push('```', konten, '```');
        }
        baris.push('');
    }

    baris.push('⚠️ Jangan bagikan isi akun ini ke siapa pun.');
    baris.push('', 'Ketik *list* untuk pesan lagi. 🙏');

    return baris.join('\n').trim();
}

/**
 * Status pesanan + isi akun (kalau sudah lunas).
 */
export function statusPesanan(pesanan, isi) {
    const baris = [
        `🧾 *${pesanan.invoice}*`,
        GARIS,
        '',
        `Status bayar: *${pesanan.payment_label}*`,
        `Status pesanan: *${pesanan.status_label}*`,
        `Total: *${rupiah(pesanan.total)}*`,
        '',
        '*Rincian:*',
    ];

    for (const item of pesanan.items) {
        baris.push(`• ${item.product_name} — ${item.variant_name} (${item.qty}x)`);
    }

    if (isi && isi.length > 0) {
        baris.push('', GARIS, '', '🔑 *Isi akun Anda:*', '');

        for (const grup of isi) {
            baris.push(`*${grup.product_name} — ${grup.variant_name}*`);
            for (const konten of grup.contents) {
                baris.push('```', konten, '```');
            }
            baris.push('');
        }

        baris.push('⚠️ Jangan bagikan isi akun ini ke siapa pun.');
    } else if (pesanan.is_paid) {
        baris.push('', '⏳ Pembayaran diterima. Isi akun sedang disiapkan admin.');
    } else if (pesanan.can_pay) {
        baris.push('', '💳 Belum dibayar. Kirim bukti transfer ke admin untuk diproses.');
    } else {
        baris.push('', '❌ Pesanan ini sudah tidak aktif.');
    }

    return baris.join('\n').trim();
}

/** Riwayat pesanan satu nomor. */
export function riwayatPesanan(daftar) {
    if (daftar.length === 0) {
        return ['Belum ada pesanan dari nomor ini.', '', 'Ketik *list* untuk mulai belanja.'].join('\n');
    }

    const baris = ['📋 *Riwayat pesanan Anda*', GARIS, ''];

    for (const p of daftar) {
        baris.push(`🧾 *${p.invoice}*`, `${p.payment_label} • ${rupiah(p.total)}`, `_${p.status_label}_`, '');
    }

    baris.push('Ketik nomor invoice untuk lihat detail & isi akun.');

    return baris.join('\n').trim();
}

/**
 * Minta nomor WhatsApp pembeli.
 *
 * Hanya muncul kalau chat memakai alamat @lid (Linked Identity) yang tidak
 * bisa diubah menjadi nomor HP. Tanpa nomor ini, pesanan tidak bisa dibuat.
 */
export function mintaNomor(produk, varian) {
    return [
        `📝 *${produk.name} — ${varian.name}*`,
        `${rupiah(varian.price)}`,
        '',
        'Sebelum saya buatkan pesanan, saya butuh *nomor WhatsApp* Anda',
        '(format Indonesia, contoh: *0823xxxxxxx*).',
        '',
        'Ini dipakai untuk mengirim isi akun & status pesanan.',
    ].join('\n');
}

/** Permintaan data wajib sebelum pesanan dibuat. */
export function mintaDataWajib(produk, varian, label) {    const baris = [
        `📝 *${produk.name} — ${varian.name}*`,
        `${rupiah(varian.price)}`,
        '',
        'Untuk memproses pesanan, saya butuh:',
    ];

    label.forEach((l, i) => {
        baris.push(`${i + 1}. ${l}`);
    });

    baris.push('', 'Kirim dalam *satu pesan*, dipisah baris baru.');

    return baris.join('\n').trim();
}

/** Data wajib kurang lengkap. */
export function dataKurang(label, jumlah) {
    return [
        `❌ Butuh ${label.length} data, Anda mengirim ${jumlah}.`,
        '',
        'Kirim ulang, satu data per baris:',
        ...label.map((l, i) => `${i + 1}. ${l}`),
    ].join('\n');
}

/** Paket habis. */
export function varianHabis(nama) {
    return [
        `❌ *${nama}* sedang tidak tersedia.`,
        '',
        'Pilih paket lain, atau ketik *bantuan* untuk tanya admin.',
    ].join('\n');
}

/** Produk tidak ditemukan. */
export function tidakDitemukan(kata) {
    return [`❌ Produk "${potong(kata, 40)}" tidak ditemukan.`, '', 'Ketik *list* untuk lihat semua produk.'].join('\n');
}

/** Nomor di luar jangkauan pilihan produk. */
export function nomorSalah(maks) {
    return [`❌ Nomor tidak ada. Pilih antara 1 sampai ${maks}.`, '', 'Ketik *list* untuk lihat daftar.'].join('\n');
}

/** Nomor di luar jangkauan paket pada layar detail produk. */
export function nomorVarianSalah(maks) {
    return [
        `❌ Nomor paket tidak ada. Pilih antara 1 sampai ${maks}.`,
        '',
        'Ketik *list* untuk kembali ke katalog.',
    ].join('\n');
}

/** Terlalu banyak pesan. */
export function terlaluCepat(detik) {
    return `⏳ Terlalu banyak pesan. Coba lagi dalam ${detik} detik.`;
}

/** Gangguan sementara. */
export function gangguan(pesan) {
    return ['⚠️ Maaf, layanan sedang gangguan.', '', pesan, '', 'Coba lagi beberapa saat, atau hubungi admin.'].join('\n');
}

/** Dibatalkan. */
export function dibatalkan() {
    return ['✅ Dibatalkan.', '', 'Ketik *list* untuk mulai lagi.'].join('\n');
}

/** Teks tak dikenal. */
export function fallback() {
    return [
        'Hmm, saya belum mengerti maksudnya. 🤔',
        '',
        'Coba ketik:',
        '• *list* — lihat katalog',
        '• *status* — cek pesanan',
        '• *bantuan* — hubungi admin',
    ].join('\n');
}
