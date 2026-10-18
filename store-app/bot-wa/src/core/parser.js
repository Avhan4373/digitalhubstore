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
    // Info pembayaran resmi (nomor owner + QRIS resmi)
    pay: ['pay', 'payment', 'pembayaran', 'bayar'],
};

/**
 * Perintah ADMIN di chat (notifikasi transaksi ke pembeli). Ditangani terpisah
 * dari ALIAS karena boleh diikuti catatan: "proses GPT PLUS SHARE 1 BULAN".
 * Hanya nomor admin (BOT_ADMIN_NUMBERS) yang dilayani — lihat route().
 */
const ADMIN_PENDING = ['proses', 'pending', 'diproses'];
const ADMIN_DONE = ['done', 'selesai', 'berhasil'];

/**
 * Perintah admin untuk mengatur grup (fitur WhatsApp "announcement").
 * Ditulis persis seperti ini — tidak menerima catatan tambahan.
 * Hanya dilayani untuk nomor admin DAN hanya di dalam grup (lihat route()).
 */
const ADMIN_GRUP_TUTUP = [
    'tutup grup', 'tutupgroup', 'tutup grub', 'tutupgrub',
    'close group', 'closegroup', 'grup tutup', 'grup close',
];
const ADMIN_GRUP_BUKA = [
    'buka grup', 'bukagroup', 'buka grub', 'bukagrub',
    'open group', 'opengroup', 'grup buka', 'grup open',
];

function normalisasi(text) {
    return String(text || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, ' ');
}

/** Alias yang boleh dicocokkan sebagai substring (kata dalam kalimat). */
const ALIAS_SUBSTRING = new Set(['list', 'katalog', 'catalog', 'produk', 'menu', 'daftar', 'harga', 'price']);

/**
 * Cocokkan teks ke salah satu alias.
 * Cek persis dulu, lalu cek substring hanya untuk perintah katalog.
 * @returns {string|null} nama perintah
 */
function cocokAlias(teks) {
    for (const [perintah, daftar] of Object.entries(ALIAS)) {
        if (daftar.includes(teks)) return perintah;
    }
    // Substring match hanya untuk perintah list/katalog supaya
    // "daftar produk canva" tetap dikenali sebagai permintaan katalog.
    const kata = teks.split(' ');
    for (const k of kata) {
        if (ALIAS_SUBSTRING.has(k)) return 'list';
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

// 2. "list 2" / "katalog 3" -> buka produk ke-N (sebelum cocokAlias supaya tidak tertangkap substring)
    const listNomor = teks2.match(/^(?:list|katalog|produk|menu|daftar)\s+(\d{1,3})$/);
    if (listNomor) {
        return bungkus({ type: 'pilih_produk', nomor: Number(listNomor[1]) });
    }

    // 2. Perintah admin: "proses" / "proses GPT PLUS SHARE 1 BULAN" (begitu juga
    //    "done"/"selesai"/"berhasil"). Catatan diambil dari teks ASLI supaya
    //    huruf besar/kecilnya tidak berubah.
    const [kataPertama, ...sisaKata] = asli.split(/\s+/);
    const kunciAdmin = kataPertama.toLowerCase();

    if (ADMIN_PENDING.includes(kunciAdmin) || ADMIN_DONE.includes(kunciAdmin)) {
        return bungkus({
            type: ADMIN_DONE.includes(kunciAdmin) ? 'admin_done' : 'admin_proses',
            catatan: sisaKata.join(' ').trim(),
        });
    }

    // 2b. Perintah admin mengatur grup: "tutup grup" / "buka grup".
    //     Dicocokkan pada SELURUH teks (bukan kata pertama) karena perintahnya
    //     dua kata. Harus dicek sebelum pencarian produk, kalau tidak
    //     "tutup grup" akan dianggap nama produk yang tidak ada.
    if (ADMIN_GRUP_TUTUP.includes(teks2)) {
        return bungkus({ type: 'admin_tutup_grup' });
    }

    if (ADMIN_GRUP_BUKA.includes(teks2)) {
        return bungkus({ type: 'admin_buka_grup' });
    }

    // 3. Perintah teks
    const perintah = cocokAlias(teks2);
    if (perintah) {
        return bungkus({ type: perintah });
    }

    // 4. Angka polos: pilih produk atau varian tergantung state
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

    // 5. Teks bebas -> pencarian
    return bungkus({ type: 'cari', kata: asli });}
