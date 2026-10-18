/**
 * Patch runtime untuk whatsapp-web.js v1.34.7 — WAJIB sebelum `client.initialize()`.
 *
 * Bug (issue upstream #201921 / #201922, diperbaiki di PR #201923):
 * `processMediaData()` mengembalikan model **MediaData** yang punya properti
 * internal enumerable `__x_id`. Di `window.WWebJS.sendMessage()` seluruh model
 * itu disebar ke objek pesan keluar (`...mediaOptions`), sehingga `__x_id`
 * menimpa ID pesan yang baru dibuat. WhatsApp Web lalu melempar:
 *
 *   Data passed to getter must include an id property (it's how we memoize)
 *   but got undefined
 *
 * Akibatnya SEMUA kiriman media (gambar/video/audio/dokumen) gagal — pesan teks
 * tidak terpengaruh. Gejalanya di bot ini: QRIS gagal dikirim dan hanya teks
 * penggantinya yang sampai ke pembeli.
 *
 * Perbaikannya satu baris: buang `__x_id` sebelum objek pesan dipakai.
 * `npm install` menimpa `node_modules`, jadi patch diterapkan ulang setiap bot
 * start (idempoten — kalau sudah ada, tidak diapa-apakan).
 */
import fs from 'node:fs';
import path from 'node:path';

import { ROOT } from './config.js';
import { log } from './core/logger.js';

/** Penanda bahwa patch sudah terpasang. */
const PENANDA = 'delete message.__x_id;';

/** Baris yang jadi titik sisip: akhir objek pesan keluar di `sendMessage()`. */
const JANGKAR_FUNGSI = 'window.WWebJS.sendMessage = async';
const JANGKAR_OBJEK = '...extraOptions,\n        };';

/** Path berkas injeksi whatsapp-web.js. */
export function berkasUtils() {
    return path.join(ROOT, 'node_modules', 'whatsapp-web.js', 'src', 'util', 'Injected', 'Utils.js');
}

/**
 * Terapkan patch ke berkas injeksi whatsapp-web.js.
 *
 * @returns {'sudah-ada'|'dipasang'|'berkas-tidak-ada'|'jangkar-tidak-ada'}
 */
export function patchWhatsappWeb(berkas = berkasUtils()) {
    if (!fs.existsSync(berkas)) return 'berkas-tidak-ada';

    const asli = fs.readFileSync(berkas, 'utf8');

    if (asli.includes(PENANDA)) return 'sudah-ada';

    // Sisipkan hanya di dalam `window.WWebJS.sendMessage` (pola objek pesan
    // yang sama juga dipakai fungsi lain, mis. status/story).
    const mulai = asli.indexOf(JANGKAR_FUNGSI);
    const jangkar = mulai === -1 ? -1 : asli.indexOf(JANGKAR_OBJEK, mulai);

    if (jangkar === -1) return 'jangkar-tidak-ada';

    const sisip = jangkar + JANGKAR_OBJEK.length;
    const komentar = [
        '',
        '        // Patch bot: buang properti internal MediaData yang menimpa ID',
        '        // pesan keluar (whatsapp-web.js #201921 / #201922).',
        `        ${PENANDA}`,
    ].join('\n');

    fs.writeFileSync(berkas, `${asli.slice(0, sisip)}${komentar}${asli.slice(sisip)}`, 'utf8');

    return 'dipasang';
}

/** Jalankan langsung: `node src/patch-lib.js` (mis. dari `postinstall`). */
if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname)) {
    const hasil = patchWhatsappWeb();

    console.log(`[patch-lib] whatsapp-web.js: ${hasil}`);
}
