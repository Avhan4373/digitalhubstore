/**
 * Patch runtime untuk whatsapp-web.js v1.34.7 — WAJIB sebelum `client.initialize()`.
 *
 * Ada DUA bug upstream yang ditambal di sini. Keduanya tidak bisa diperbaiki
 * dari kode bot: yang rusak ada di berkas injeksi library, dan `npm install`
 * menimpa `node_modules`. Patch diterapkan ulang setiap bot start (idempoten).
 *
 * 1) `processMediaData()` mengembalikan model **MediaData** yang punya properti
 *    internal enumerable `__x_id`. Di `window.WWebJS.sendMessage()` seluruh
 *    model itu disebar ke objek pesan keluar (`...mediaOptions`), sehingga
 *    `__x_id` menimpa ID pesan yang baru dibuat. WhatsApp Web lalu melempar:
 *
 *      Data passed to getter must include an id property (it's how we memoize)
 *      but got undefined
 *
 *    Akibatnya SEMUA kiriman media (gambar/video/audio/dokumen) gagal — pesan
 *    teks tidak terpengaruh. Gejalanya: QRIS gagal dikirim dan hanya teks
 *    penggantinya yang sampai ke pembeli.
 *
 * 2) `getChatModel()` membaca `chat.lastReceivedKey._serialized` untuk
 *    mengambil pesan terakhir. Di WhatsApp Web sekarang `lastReceivedKey`
 *    kadang berupa objek biasa yang HANYA punya `$1` (tanpa `_serialized`),
 *    sehingga `Msg.get(undefined)` melempar:
 *
 *      DataError: Failed to execute 'get' on 'IDBObjectStore':
 *      No key or key range specified.
 *
 *    Error itu menyeberang puppeteer menjadi `r` dan membuat SELURUH
 *    `getChatModel()` gagal — jadi `client.getChatById()` selalu melempar.
 *    Dampaknya di bot ini: nama grup tidak pernah terbaca (baris "📂 Grup"
 *    kosong / berisi ID grup) dan `GroupChat.setMessagesAdminsOnly()` gagal
 *    dengan error `r` walaupun bot SUDAH admin grup.
 */
import fs from 'node:fs';
import path from 'node:path';

import { ROOT } from './config.js';
import { log } from './core/logger.js';

/** Penanda bahwa patch media sudah terpasang. */
const PENANDA = 'delete message.__x_id;';

/** Baris yang jadi titik sisip: akhir objek pesan keluar di `sendMessage()`. */
const JANGKAR_FUNGSI = 'window.WWebJS.sendMessage = async';
const JANGKAR_OBJEK = '...extraOptions,\n        };';

/** Penanda bahwa patch `getChatModel()` sudah terpasang. */
const PENANDA_MODEL = 'Patch bot: lastReceivedKey bisa tanpa _serialized';

/**
 * Blok `getChatModel()` yang rusak, disalin persis dari library.
 *
 * `Msg.get(undefined)` di baris ini yang melempar DataError. Dibanding menambal
 * satu baris, seluruh blok diganti supaya pesan terakhir tetap diambil lewat
 * `$1` (bukan sekadar dilewati) dan kegagalannya tidak lagi mematikan model.
 */
const BLOK_MODEL_ASLI = `        model.lastMessage = null;
        if (model.msgs && model.msgs.length) {
            const lastMessage = chat.lastReceivedKey
                ? window
                      .require('WAWebCollections')
                      .Msg.get(chat.lastReceivedKey._serialized) ||
                  (
                      await window
                          .require('WAWebCollections')
                          .Msg.getMessagesById([
                              chat.lastReceivedKey._serialized,
                          ])
                  )?.messages?.[0]
                : null;
            lastMessage &&
                (model.lastMessage =
                    window.WWebJS.getMessageModel(lastMessage));
        }`;

const BLOK_MODEL_BARU = `        model.lastMessage = null;
        // Patch bot: lastReceivedKey bisa tanpa _serialized
        // (model MsgKey kadang hanya punya $1). Tanpa penjagaan ini
        // Msg.get(undefined) melempar DataError dan SELURUH getChatModel gagal,
        // sehingga nama grup dan setMessagesAdminsOnly() ikut mati.
        if (model.msgs && model.msgs.length) {
            const kunciTerakhir = chat.lastReceivedKey
                ? chat.lastReceivedKey._serialized ||
                  chat.lastReceivedKey.$1 ||
                  ''
                : '';
            let lastMessage = null;
            if (kunciTerakhir) {
                try {
                    lastMessage =
                        window
                            .require('WAWebCollections')
                            .Msg.get(kunciTerakhir) ||
                        (
                            await window
                                .require('WAWebCollections')
                                .Msg.getMessagesById([kunciTerakhir])
                        )?.messages?.[0];
                } catch (e) {
                    // Pesan terakhir bersifat pelengkap: kegagalan di sini
                    // tidak boleh menggagalkan seluruh model chat.
                    lastMessage = null;
                }
            }
            lastMessage &&
                (model.lastMessage =
                    window.WWebJS.getMessageModel(lastMessage));
        }`;


/** Path berkas injeksi whatsapp-web.js. */
export function berkasUtils() {
    return path.join(ROOT, 'node_modules', 'whatsapp-web.js', 'src', 'util', 'Injected', 'Utils.js');
}

/**
 * Terapkan kedua patch ke berkas injeksi whatsapp-web.js.
 *
 * Dijalankan terpisah per patch supaya instalasi lama (yang sudah kena patch
 * media) tetap mendapat patch `getChatModel()` tanpa perlu `npm install` ulang.
 *
 * @returns {{media: string, model: string}}
 *   tiap nilai: 'sudah-ada' | 'dipasang' | 'berkas-tidak-ada' | 'jangkar-tidak-ada'
 */
export function patchWhatsappWeb(berkas = berkasUtils()) {
    if (!fs.existsSync(berkas)) {
        return { media: 'berkas-tidak-ada', model: 'berkas-tidak-ada' };
    }

    return {
        media: patchMedia(berkas),
        model: patchModel(berkas),
    };
}

/**
 * Patch 1: buang `__x_id` dari objek pesan keluar.
 *
 * Disisipkan hanya di dalam `window.WWebJS.sendMessage` (pola objek pesan yang
 * sama juga dipakai fungsi lain, mis. status/story).
 */
function patchMedia(berkas) {
    const asli = fs.readFileSync(berkas, 'utf8');

    if (asli.includes(PENANDA)) return 'sudah-ada';

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

/** Patch 2: `getChatModel()` tahan `lastReceivedKey` tanpa `_serialized`. */
function patchModel(berkas) {
    const asli = fs.readFileSync(berkas, 'utf8');

    if (asli.includes(PENANDA_MODEL)) return 'sudah-ada';

    if (!asli.includes(BLOK_MODEL_ASLI)) return 'jangkar-tidak-ada';

    // `replace` dengan string: aman dari `$&` dsb. karena blok baru tidak
    // memuat pola penggantian khusus.
    fs.writeFileSync(berkas, asli.replace(BLOK_MODEL_ASLI, BLOK_MODEL_BARU), 'utf8');

    return 'dipasang';
}

/** Jalankan langsung: `node src/patch-lib.js` (mis. dari `postinstall`). */
if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname)) {
    const hasil = patchWhatsappWeb();

    console.log(`[patch-lib] whatsapp-web.js media: ${hasil.media}`);
    console.log(`[patch-lib] whatsapp-web.js getChatModel: ${hasil.model}`);
}
