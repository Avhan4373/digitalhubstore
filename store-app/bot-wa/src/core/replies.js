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
        'Saya bot yang bisa bantu kamu:',
        '• *list* — lihat katalog produk',
        '• *status* — cek pesanan & isi akun',
        '• *bantuan* — hubungi admin',
        '',
        'Ketik *list* untuk mulai belanja 🛒',
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
        '*Perintah yang tersedia:*',
        '• *list* — daftar produk & harga',
        '• *status* — cek status pesanan',
        '• *batal* — batalkan proses yang sedang berjalan',
        '• *bantuan* — tampilkan pesan ini',
        '',
        '*Cara pesan:*',
        '1. Ketik *list*',
        '2. Balas *nomor* produk',
        '3. Balas *nomor* varian',
        '4. Isi data yang diminta',
        '5. Bayar lewat QRIS',
        '',
        '*Butuh bantuan lain?*',
        `Chat admin: wa.me/${store.whatsapp}`,
    ].join('\n');
}

/**
 * Daftar katalog per kategori.
 * @param {{name: string}} store
 * @param {Array<{name: string, products: Array}>} categories
 */
export function katalog(store, categories) {
    const baris = [
        `🛍️ *${store.name}*`,
        GARIS,
        '',
    ];

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

    baris.push(
        'Ketik *nomor* produk untuk lihat detail.',
        'Contoh: *1*',
        '',
        `Butuh bantuan? Ketik *bantuan*`,
    );

    return baris.join('\n').trim();
}

/**
 * Detail satu produk + daftar varian.
 * @param {object} produk
 * @param {Array} varian
 */
export function detailProduk(produk, varian) {
    const baris = [
        `📦 *${escapeWa(produk.name)}*`,
        GARIS,
        '',
    ];

    if (produk.description) {
        baris.push(potong(escapeWa(produk.description), 300), '');
    }

    if (produk.warranty) {
        baris.push(`🛡️ Garansi: ${escapeWa(produk.warranty)}`, '');
    }

    if (varian.length === 0) {
        baris.push('⚠️ Belum ada varian tersedia.', '', 'Ketik *bantuan* untuk tanya admin.');
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
 * Ringkasan pesanan setelah dibuat.
 */
export function pesananDibuat(pesanan, pembayaran, store) {
    const baris = [
        '✅ *Pesanan dibuat*',
        GARIS,
        '',
        `🧾 Invoice: *${pesanan.invoice}*`,
        `👤 Nama: ${pesanan.buyer_name}`,
        '',
        '*Rincian:*',
    ];

    for (const item of pesanan.items) {
        baris.push(`• ${item.product_name} — ${item.variant_name}`);
        baris.push(`   ${item.qty} × ${rupiah(item.subtotal / item.qty)} = ${rupiah(item.subtotal)}`);
    }

    baris.push(
        '',
        `💰 *Total: ${rupiah(pesanan.total)}*`,
        '',
        GARIS,
        '',
        '💳 *Cara bayar:*',
    );

    if (pembayaran.qris_url) {
        baris.push('Scan QRIS yang saya kirim setelah pesan ini.');

        if (pembayaran.holder) {
            baris.push(`Atas nama: ${pembayaran.holder}`);
        }

        baris.push('', 'Setelah bayar, kirim bukti transfer ke admin.');
    } else {
        baris.push(
            'QRIS belum tersedia. Hubungi admin untuk instruksi pembayaran:',
            `wa.me/${store.whatsapp}`,
        );
    }

    if (pesanan.expires_at) {
        const batas = new Date(pesanan.expires_at);
        const menit = Math.max(0, Math.round((batas - Date.now()) / 60000));
        baris.push('', `⏳ Bayar dalam *${menit} menit* atau pesanan dibatalkan otomatis.`);
    }

    baris.push(
        '',
        'Ketik *status* lalu kirim nomor invoice untuk cek pesanan.',
    );

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
        baris.push('', GARIS, '', '🔑 *Isi pesanan Anda:*', '');

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

/**
 * Riwayat pesanan satu nomor.
 */
export function riwayatPesanan(daftar) {
    if (daftar.length === 0) {
        return [
            'Belum ada pesanan dari nomor ini.',
            '',
            'Ketik *list* untuk mulai belanja.',
        ].join('\n');
    }

    const baris = ['📋 *Riwayat pesanan Anda*', GARIS, ''];

    for (const p of daftar) {
        baris.push(
            `🧾 *${p.invoice}*`,
            `${p.payment_label} • ${rupiah(p.total)}`,
            `_${p.status_label}_`,
            '',
        );
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

    baris.push(
        '',
        'Kirim dalam *satu pesan*, dipisah baris baru.',
        'Contoh:',
        label.map((l, i) => `<${l.toLowerCase()}>`).join('\n'),
    );

    return baris.join('\n').trim();
}

/** Permintaan nama pembeli. */
export function mintaNama(produk, varian) {
    return [
        `📝 *${produk.name} — ${varian.name}*`,
        `${rupiah(varian.price)}`,
        '',
        'Siapa nama Anda? (untuk invoice)',
    ].join('\n');
}

/** Konfirmasi sebelum pesanan dibuat. */
export function konfirmasiPesanan(produk, varian, nama, data) {
    const baris = [
        '🛒 *Konfirmasi pesanan*',
        GARIS,
        '',
        `Produk: ${produk.name}`,
        `Paket: ${varian.name}`,
        `Harga: ${rupiah(varian.price)}`,
        `Nama: ${nama}`,
    ];

    const entri = Object.entries(data || {});
    if (entri.length > 0) {
        baris.push('');
        for (const [k, v] of entri) {
            baris.push(`${k}: ${v}`);
        }
    }

    baris.push(
        '',
        'Ketik *ya* untuk buat pesanan, atau *batal* untuk membatalkan.',
    );

    return baris.join('\n').trim();
}

/** Pesan ketika produk/varian tidak ditemukan. */
export function tidakDitemukan(kata) {
    return [
        `❌ Produk "${potong(kata, 40)}" tidak ditemukan.`,
        '',
        'Ketik *list* untuk lihat semua produk.',
    ].join('\n');
}

/** Nomor di luar jangkauan pilihan produk. */
export function nomorSalah(maks) {
    return [
        `❌ Nomor tidak ada. Pilih antara 1 sampai ${maks}.`,
        '',
        'Ketik *list* untuk lihat daftar.',
    ].join('\n');
}

/** Nomor di luar jangkauan paket pada layar detail produk. */
export function nomorVarianSalah(maks) {
    return [
        `❌ Nomor paket tidak ada. Pilih antara 1 sampai ${maks}.`,
        '',
        'Ketik *list* untuk kembali ke katalog.',
    ].join('\n');
}

/** Pesan ketika pengguna terlalu banyak mengirim pesan. */
export function terlaluCepat(detik) {
    return `⏳ Terlalu banyak pesan. Coba lagi dalam ${detik} detik.`;
}

/** Bot sedang sibuk / gangguan sementara. */
export function gangguan() {
    return [
        '⚠️ Maaf, layanan sedang gangguan.',
        '',
        'Coba lagi beberapa saat, atau hubungi admin langsung.',
    ].join('\n');
}

/** Dibatalkan. */
export function dibatalkan() {
    return [
        '✅ Dibatalkan.',
        '',
        'Ketik *list* untuk mulai lagi.',
    ].join('\n');
}
