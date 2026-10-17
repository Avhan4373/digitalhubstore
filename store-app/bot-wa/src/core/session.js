/**
 * State percakapan per nomor.
 *
 * Disimpan di memori proses. Bot sengaja TIDAK menyimpan state ke disk:
 * percakapan belanja berumur pendek, dan state basi lebih berbahaya (pembeli
 * dikirimi ringkasan pesanan lama) daripada kehilangan state saat restart.
 * Data yang benar-benar penting — pesanan — ada di database Laravel.
 */

const TTL_MS = 30 * 60 * 1000; // 30 menit

/** @type {Map<string, {state: object, touched: number}>} */
const sessions = new Map();

function kunci(chatId) {
    return String(chatId || '');
}

export function getState(chatId) {
    const key = kunci(chatId);
    const entry = sessions.get(key);

    if (!entry) return null;

    if (Date.now() - entry.touched > TTL_MS) {
        sessions.delete(key);
        return null;
    }

    return entry.state;
}

export function setState(chatId, state) {
    sessions.set(kunci(chatId), { state, touched: Date.now() });
}

export function clearState(chatId) {
    sessions.delete(kunci(chatId));
}

/**
 * Bersihkan sesi kedaluwarsa. Dipanggil berkala supaya Map tidak tumbuh
 * tanpa batas di proses yang hidup berhari-hari.
 */
export function sweepSessions() {
    const now = Date.now();
    let removed = 0;

    for (const [key, entry] of sessions) {
        if (now - entry.touched > TTL_MS) {
            sessions.delete(key);
            removed += 1;
        }
    }

    return removed;
}

/** Jumlah sesi aktif — untuk log/diagnostik. */
export function sessionCount() {
    return sessions.size;
}

export const STATE = {
    /** Tidak ada percakapan berjalan. */
    IDLE: 'idle',
    /** Menampilkan hasil pencarian; `angka` memilih dari hasil itu. */
    PILIH_CARI: 'pilih_cari',
    /** Sedang melihat detail produk; `angka` memilih varian. */
    DETAIL: 'detail',
    /** Menunggu nomor WhatsApp pembeli (hanya jika LID tidak bisa diubah). */
    NOMOR: 'nomor',
    /** Menunggu data wajib (mis. email akun). */
    DATA: 'data',
};
