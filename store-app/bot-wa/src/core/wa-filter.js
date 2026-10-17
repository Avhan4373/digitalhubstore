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
 * setiap pesan daftar siaran.
 */
export function harusDiabaikan(msg) {
    if (!msg) return true;
    if (msg.fromMe) return true;
    if (msg.isStatus) return true;
    if (msg.broadcast) return true;

    const from = String(msg.from || '');

    if (from.includes('@g.us')) return true;
    if (from.includes('@broadcast')) return true;
    if (from.includes('@newsletter')) return true;

    // Hanya chat pribadi.
    return !from.endsWith('@c.us');
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
