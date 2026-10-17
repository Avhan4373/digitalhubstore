/**
 * Aturan penyaringan pesan WhatsApp.
 *
 * Dipisah dari adapter supaya bisa diuji tanpa memasang `whatsapp-web.js`
 * maupun Chrome. Kalau filter ini bocor, bot membalas pesan siaran dan status
 * — kesalahan yang paling sering terjadi pada bot WhatsApp.
 */

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
export function harusDiabaikan(msg, allowSelf = false) {
    if (!msg) return true;
    if (msg.isStatus) return true;
    if (msg.broadcast) return true;

    const from = String(msg.from || '');

    if (from.includes('@g.us')) return true;
    if (from.includes('@broadcast')) return true;
    if (from.includes('@newsletter')) return true;

    // Hanya chat pribadi.
    if (!from.endsWith('@c.us')) return true;

    // Pesan sendiri: diabaikan kecuali uji coba.
    if (msg.fromMe && !allowSelf) return true;

    return false;
}

/** 628123456789@c.us -> 628123456789 */
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
