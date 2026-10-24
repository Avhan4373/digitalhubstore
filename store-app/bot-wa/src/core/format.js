/**
 * Pemformat teks balasan WhatsApp.
 *
 * Aturan tampilan disamakan dengan storefront supaya pembeli melihat angka dan
 * istilah yang sama di web maupun di WhatsApp.
 */

/** 15000 -> "15.000" */
export function angka(value) {
    return Number(value || 0).toLocaleString('id-ID');
}

/** 15000 -> "Rp15.000" */
export function rupiah(value) {
    return `Rp${angka(value)}`;
}

/**
 * Ubah huruf Latin jadi huruf miring matematika (𝑖𝑛𝑣𝑖𝑡𝑒, 𝐴𝑐𝑐) untuk
 * catatan gaya "papan menu" di bot. Karakter non-huruf dibiarkan apa adanya;
 * Huruf H/h miring di luar rentang berurutan (ℎ, U+210E).
 */
export function hurufMiring(text) {
    const basisBesar = 0x1d434 - 65; // 'A'
    const basisKecil = 0x1d44e - 97; // 'a'
    let hasil = '';

    for (const karakter of String(text || '')) {
        const kode = karakter.codePointAt(0);

        if (karakter === 'H') hasil += '\u210e';
        else if (karakter === 'h') hasil += '\u210e';
        else if (kode >= 65 && kode <= 90) hasil += String.fromCodePoint(kode + basisBesar);
        else if (kode >= 97 && kode <= 122) hasil += String.fromCodePoint(kode + basisKecil);
        else hasil += karakter;
    }

    return hasil;
}

/**
 * Rentang harga: satu harga atau "Rp5.000 – Rp35.000".
 */
export function rentangHarga(min, max) {
    const a = Number(min || 0);
    const b = Number(max || 0);

    if (a <= 0 && b <= 0) return 'Hubungi admin';
    if (b <= 0 || a === b) return rupiah(a);

    return `${rupiah(a)} – ${rupiah(b)}`;
}

/**
 * Angka terjual gaya storefront: 850 -> "850", 30000 -> "30RB+".
 */
export function labelTerjual(count) {
    const value = Number(count || 0);

    return value >= 1000 ? `${Math.floor(value / 1000)}RB+` : String(value);
}

/** Potong teks panjang tanpa memotong di tengah kata. */
export function potong(text, max) {
    const value = String(text || '').trim();
    if (value.length <= max) return value;

    const cut = value.slice(0, max);
    const lastSpace = cut.lastIndexOf(' ');

    return `${(lastSpace > max * 0.6 ? cut.slice(0, lastSpace) : cut).trimEnd()}…`;
}

/**
 * WhatsApp membatasi satu pesan ~65.535 karakter, tapi pesan sepanjang itu
 * tidak terbaca di ponsel. Balasan katalog dipecah per bagian.
 */
export const BATAS_PESAN = 3500;

/**
 * Bagi teks panjang menjadi beberapa pesan pada batas baris.
 * @returns {string[]}
 */
export function pecahPesan(text, batas = BATAS_PESAN) {
    const value = String(text || '');
    if (value.length <= batas) return [value];

    const out = [];
    let buffer = '';

    for (const line of value.split('\n')) {
        if (buffer.length + line.length + 1 > batas && buffer !== '') {
            out.push(buffer.trimEnd());
            buffer = '';
        }
        buffer += `${line}\n`;
    }

    if (buffer.trim() !== '') out.push(buffer.trimEnd());

    return out;
}

/**
 * Catatan transaksi dari pesanan, mis. "GEMINI PRO SHARING 1 BULAN".
 *
 * Dipakai perintah admin `proses`/`done` kalau admin tidak menulis catatan
 * sendiri: nama produk + varian dari pesanan terakhir di chat itu, huruf besar
 * seperti format pesan transaksi.
 */
export function catatanPesanan(order) {
    const isi = (order?.items || [])
        .map((item) => [item.product_name, item.variant_name].filter(Boolean).join(' '))
        .join(', ');

    return isi.replace(/\s+/g, ' ').trim().toUpperCase();
}

/** Escape karakter yang di WhatsApp punya arti khusus (*, _, ~, `). */
export function escapeWa(text) {
    return String(text || '').replace(/([*_~`])/g, '\\$1');
}

/**
 * Nomor untuk ditampilkan ke pembeli: 6282395124106 -> "082395124106".
 * Nomor yang sudah diawali 0 dibiarkan apa adanya.
 */
export function nomorLokal(number) {
    const digits = String(number || '').replace(/\D/g, '');

    if (digits === '') return '';
    if (digits.startsWith('62')) return `0${digits.slice(2)}`;

    return digits;
}

/** Normalisasi nomor WA Indonesia ke 62xxx. */
export function normalisasiNomor(number) {
    const digits = String(number || '').replace(/\D/g, '');

    if (digits === '') return '';
    if (digits.startsWith('62')) return digits;
    if (digits.startsWith('0')) return `62${digits.slice(1)}`;
    if (digits.startsWith('8')) return `62${digits}`;

    return digits;
}

/**
 * Nomor WhatsApp Indonesia yang masuk akal: 62 + 8..14 digit.
 *
 * Dipakai untuk menolak LID (Linked Identity) yang bagian angkanya bukan
 * nomor HP — kalau tidak, pesanan dibuat dengan identitas yang tidak bisa
 * dihubungi admin lewat wa.me.
 */
export function nomorValid(nomor) {
    return /^62\d{8,14}$/.test(String(nomor || '').replace(/\D/g, ''));
}

/** 6282395124106 -> "+62 823-9512-4106" */
export function tampilkanNomor(number) {
    const digits = normalisasiNomor(number);
    if (!digits.startsWith('62')) return String(number || '');

    const local = digits.slice(2);
    const parts = local.match(/.{1,4}/g) || [];

    return `+62 ${parts.join('-')}`;
}
