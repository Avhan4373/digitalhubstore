/**
 * Parser perintah teks dari pembeli.
 *
 * Dipisah dari handler WhatsApp supaya bisa diuji tanpa Chrome dan tanpa
 * koneksi WhatsApp. Semua keputusan "ini perintah apa" ada di sini.
 */

/** Kata kunci yang dianggap sama. */
const ALIAS = {
    list: ['list', 'katalog', 'catalog', 'produk', 'menu', 'daftar', 'harga', 'price'],
    bantuan: ['bantuan', 'help', 'cs', 'admin'],
    sapa: ['halo', 'hai', 'hi', 'hallo', 'p', 'start', 'mulai', 'assalamualaikum'],
    status: ['status', 'cek', 'cekpesanan', 'pesanan', 'invoice'],
    riwayat: ['riwayat', 'history', 'pesananku', 'transaksi'],
    batal: ['batal', 'cancel', 'batalkan', 'reset'],
    lanjut: ['lanjut', 'next', 'lagi', 'more'],
};

function normalisasi(text) {
    return String(text || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, ' ');
}

/**
 * Cocokkan teks ke salah satu alias.
 * @returns {string|null} nama perintah
 */
function cocokAlias(teks) {
    for (const [perintah, daftar] of Object.entries(ALIAS)) {
        if (daftar.includes(teks)) return perintah;
    }
    return null;
}

/**
 * Parse satu pesan masuk menjadi aksi.
 *
 * Urutan penting:
 *   1. Nomor invoice (INVxxxx) — jangan sampai dianggap perintah lain
 *   2. Perintah teks (list, bantuan, status, batal)
 *   3. Angka (pilih produk / varian)
 *   4. Teks bebas (cari produk)
 *
 * @returns {{type: string, [key: string]: any}}
 */
export function parsePesan(teks) {
    const asli = String(teks || '').trim();
    const teks2 = normalisasi(asli);

    // `teks` selalu ikut di setiap hasil: saat bot menunggu isian bebas (nama,
    // Player ID, email), pemanggil butuh teks APA ADANYA — bukan hasil
    // klasifikasi. Tanpa ini, Player ID berisi angka ("12345678") akan
    // dianggap "pilih nomor" dan datanya hilang.
    const bungkus = (hasil) => ({ ...hasil, teks: asli });

    if (teks2 === '') {
        return bungkus({ type: 'kosong' });
    }

    // 1. Invoice: INV + 6 digit tanggal + 5 huruf/angka (lihat Order::newInvoiceNumber)
    const invoice = asli.match(/\b(INV\d{6}[A-Z0-9]{5})\b/i);
    if (invoice) {
        return bungkus({ type: 'invoice', invoice: invoice[1].toUpperCase() });
    }

    // 2. Perintah teks
    const perintah = cocokAlias(teks2);
    if (perintah) {
        return bungkus({ type: perintah });
    }

    // "list 2" / "katalog 3" -> buka produk ke-N
    const listNomor = teks2.match(/^(?:list|katalog|produk|menu|daftar)\s+(\d{1,3})$/);
    if (listNomor) {
        return bungkus({ type: 'pilih_produk', nomor: Number(listNomor[1]) });
    }

    // 3. Angka polos: pilih produk atau varian tergantung state
    if (/^\d{1,3}$/.test(teks2)) {
        return bungkus({ type: 'angka', nomor: Number(teks2) });
    }

    // "order" / "beli" / "pesan" saat di layar detail
    if (/^(order|beli|pesan|checkout|ambil)$/.test(teks2)) {
        return bungkus({ type: 'order' });
    }

    // "order 2" -> beli varian ke-2
    const orderNomor = teks2.match(/^(?:order|beli|pesan)\s+(\d{1,3})$/);
    if (orderNomor) {
        return bungkus({ type: 'order_varian', nomor: Number(orderNomor[1]) });
    }

    // 4. Teks bebas -> pencarian
    return bungkus({ type: 'cari', kata: asli });
}
