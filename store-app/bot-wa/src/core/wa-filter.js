/**
 * Aturan penyaringan pesan WhatsApp.
 *
 * Dipisah dari adapter supaya bisa diuji tanpa memasang `whatsapp-web.js`
 * maupun Chrome. Kalau filter ini bocor, bot membalas pesan siaran dan status
 * — kesalahan yang paling sering terjadi pada bot WhatsApp.
 */

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
 */
export function alasanDiabaikan(msg, allowSelf = false) {
    if (!msg) return 'pesan kosong';
    if (msg.isStatus) return 'pesan status';
    if (msg.broadcast) return 'pesan siaran';
    if (msg.fromMe) {
        return allowSelf ? '' : 'pesan dari diri sendiri (uji: set BOT_ALLOW_SELF=true)';
    }

    const from = String(msg.from || '');

    if (from.includes('@g.us')) return 'chat grup';
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
export function harusDiabaikan(msg, allowSelf = false) {
    return alasanDiabaikan(msg, allowSelf) !== '';
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
