/**
 * Aturan penyaringan pesan WhatsApp.
 *
 * Dipisah dari adapter supaya bisa diuji tanpa memasang `whatsapp-web.js`
 * maupun Chrome. Kalau filter ini bocor, bot membalas pesan siaran dan status
 * — kesalahan yang paling sering terjadi pada bot WhatsApp.
 */
import { normalisasiNomor, nomorValid } from './format.js';

/**
 * Apakah alamat ini chat pribadi (bukan grup/siaran/kanal)?
 *
 * WhatsApp kini mengirim sebagian chat dengan alamat `@lid` (Linked Identity)
 * dan bukan `@c.us`. Filter lama yang hanya menerima `@c.us` membuat bot
 * mengabaikan SEMUA pesan masuk — bug ini diam-diam karena tidak ada log.
 */
function chatPribadi(from) {
    return from.endsWith('@c.us') || from.endsWith('@lid');
}

/**
 * Alasan pesan diabaikan, untuk log.
 *
 * Dipisah dari `harusDiabaikan` supaya log bisa memberi tahu pengguna kenapa
 * bot tidak merespon — sebelumnya pesan ditolak tanpa jejak.
 *
 * @param {object} msg pesan whatsapp-web.js
 * @param {boolean} allowSelf izinkan pesan dari diri sendiri (uji tanpa HP kedua)
 * @param {string[]} grupDiizinkan ID grup (@g.us) yang boleh dilayani
 */
export function alasanDiabaikan(msg, allowSelf = false, grupDiizinkan = []) {
    if (!msg) return 'pesan kosong';
    if (msg.isStatus) return 'pesan status';
    if (msg.broadcast) return 'pesan siaran';
    if (msg.fromMe) {
        return allowSelf ? '' : 'pesan dari diri sendiri (uji: set BOT_ALLOW_SELF=true)';
    }

    const from = String(msg.from || '');

    // Grup diabaikan, KECUALI grup yang didaftarkan eksplisit di
    // BOT_GROUP_ALLOWLIST (mis. grup uji coba milik sendiri). Tanpa
    // pengecualian ini bot membalas setiap grup pelanggan dan mudah dituduh
    // spam.
    if (from.includes('@g.us')) {
        return grupDiizinkan.includes(from) ? '' : 'chat grup';
    }

    if (from.includes('@broadcast')) return 'chat siaran';
    if (from.includes('@newsletter')) return 'kanal/newsletter';
    if (!chatPribadi(from)) return `bukan chat pribadi (${from})`;

    return '';
}

/**
 * Pesan yang harus diabaikan.
 *
 * `broadcast` paling sering terlewat: tanpa pemeriksaan ini bot membalas
 * setiap pesan daftar siaran dan bisa membanjiri kontak.
 *
 * `fromMe` (pesan sendiri) diabaikan secara default. Aktifkan `allowSelf`
 * hanya saat uji coba dari nomor yang sama dengan bot (tanpa HP kedua).
 * Jangan aktifkan di produksi — bisa memicu loop balasan.
 */
export function harusDiabaikan(msg, allowSelf = false, grupDiizinkan = []) {
    return alasanDiabaikan(msg, allowSelf, grupDiizinkan) !== '';
}

/** Apakah chat memakai alamat @lid (Linked Identity) baru? */
export function adalahLid(chatId) {
    return String(chatId || '').endsWith('@lid');
}

/**
 * Nomor untuk identitas pembeli.
 *
 * Untuk chat `@lid`, bagian angka di depan adalah LID (bukan nomor HP). Nilai
 * ini tetap dipakai supaya bot konsisten: pesanan dibuat dan dicek dengan
 * identitas yang sama, jadi alur bot berjalan. Kalau nomor HP asli tersedia
 * (mis. dari `msg.author`), pemanggil boleh mengirimnya.
 */
export function nomorDariChatId(chatId) {
    return String(chatId || '')
        .split('@')[0]
        .replace(/\D/g, '');
}

/** 628123456789 -> 628123456789@c.us */
export function chatIdDariNomor(nomor) {
    const digits = String(nomor || '').replace(/\D/g, '');
    return digits === '' ? '' : `${digits}@c.us`;
}

/**
 * Nomor HP dari objek kontak `whatsapp-web.js`.
 *
 * Untuk chat `@lid`, whatsapp-web.js sudah menerjemahkan LID ke JID nomor HP
 * di `kontak.id` (mis. `628123456789@c.us`), sedangkan `kontak.number` SELALU
 * kosong pada v1.34. Jadi nomor dibaca dari `id`, bukan dari `number`.
 *
 * JID `@lid` sengaja ditolak: bagian angkanya bukan nomor HP dan membuat API
 * Laravel menolak dengan "Nomor WhatsApp tidak valid".
 *
 * @param {{id?: {_serialized?: string, user?: string}|string, number?: string, numberFormatted?: string}} kontak
 * @returns {string|null} nomor 62xxx, atau null kalau tidak bisa dipastikan
 */
export function nomorDariKontak(kontak) {
    if (!kontak) return null;

    const jid = String(kontak.id?._serialized || kontak.id?.user || kontak.id || '');
    const [bagianAngka, server = 'c.us'] = jid.split('@');

    if (bagianAngka !== '' && server !== 'lid') {
        const dariJid = normalisasiNomor(bagianAngka);
        if (nomorValid(dariJid)) return dariJid;
    }

    const dariProperti = normalisasiNomor(kontak.number || kontak.numberFormatted || '');
    if (nomorValid(dariProperti)) return dariProperti;

    return null;
}
