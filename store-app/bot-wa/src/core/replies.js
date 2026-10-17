/**
 * Penyusun teks balasan bot.
 *
 * Semua fungsi di sini murni (input data -> string), jadi bisa diuji tanpa
 * Chrome maupun jaringan. Tidak ada angka yang dihitung ulang di sini: harga
 * dan stok datang dari API Laravel.
 */
import { escapeWa, labelTerjual, potong, rentangHarga, rupiah } from './format.js';

const GARIS = '━━━━━━━━━━━━━━━';

/** Alias supaya pemanggil bisa memakai R.rupiahRange tanpa impor terpisah. */
export const rupiahRange = rentangHarga;

export function sambutan(store) {
    return [
        `Halo! 👋 Selamat datang di *${store.name}*`,
        store.tagline ? `_${store.tagline}_` : null,
        '',
        'Cara pesan:',
        '1. Ketik *list*',
        '2. Balas *nomor* produk',
        '3. Balas *nomor* paket',
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
        '2. Balas *nomor* produk',
        '3. Balas *nomor* paket',
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
 * Daftar katalog per kategori.
 * @param {{name: string}} store
 * @param {Array<{name: string, products: Array}>} categories
 */
export function katalog(store, categories) {
    const baris = [`🛍️ *${store.name}*`, GARIS, ''];
    let nomor = 0;

    for (const kategori of categories) {
        const produk = kategori.products || [];
        if (produk.length === 0) continue;

        baris.push(`*${escapeWa(kategori.name)}*`);

        for (const p of produk) {
            nomor += 1;
            baris.push(
                `${nomor}. ${escapeWa(p.name)}`,
                `    ${rentangHarga(p.price_min, p.price_max)}${p.sold_count > 0 ? ` • ${labelTerjual(p.sold_count)} terjual` : ''}`,
            );
        }

        baris.push('');
    }

    baris.push('Ketik *nomor* produk untuk lihat paket.', 'Contoh: *1*');

    return baris.join('\n').trim();
}

/**
 * Detail satu produk + daftar paket.
 * @param {object} produk
 * @param {Array} varian
 */
export function detailProduk(produk, varian) {
    const baris = [`📦 *${escapeWa(produk.name)}*`, GARIS, ''];

    if (produk.description) {
        baris.push(potong(escapeWa(produk.description), 300), '');
    }

    if (produk.warranty) {
        baris.push(`🛡️ Garansi: ${escapeWa(produk.warranty)}`, '');
    }

    if (varian.length === 0) {
        baris.push('⚠️ Belum ada paket tersedia.', '', 'Ketik *bantuan* untuk tanya admin.');
        return baris.join('\n').trim();
    }

    baris.push('💰 *Pilih paket:*');

    varian.forEach((v, i) => {
        const habis = !v.purchasable;
        const stok = v.stock === null ? '' : v.stock > 0 ? ` (stok ${v.stock})` : ' (habis)';
        const harga = v.original_price
            ? `~${rupiah(v.original_price)}~ ${rupiah(v.price)}`
            : rupiah(v.price);

        baris.push(`${i + 1}. ${escapeWa(v.name)} — ${harga}${habis ? ' ❌' : stok}`);
    });

    baris.push(
        '',
        'Ketik *nomor* paket untuk pesan.',
        'Contoh: *1*',
        '',
        'Ketik *list* untuk kembali ke katalog.',
    );

    return baris.join('\n').trim();
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

/** Permintaan data wajib sebelum pesanan dibuat. */
export function mintaDataWajib(produk, varian, label) {
    const baris = [
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
