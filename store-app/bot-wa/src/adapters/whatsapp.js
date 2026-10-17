/**
 * Adapter WhatsApp Web (whatsapp-web.js + Chrome).
 *
 * Semua detail teknis WhatsApp dikurung di file ini: pencarian Chrome,
 * penyimpanan sesi, filter pesan, dan pengiriman media. `engine.js` tidak tahu
 * apa pun soal WhatsApp, jadi alur bisa diuji tanpa Chrome.
 *
 * Jebakan yang sudah ditangani (lihat SKILL whatsapp-web-bot):
 *   - whatsapp-web.js itu CJS  -> default import lalu destructure
 *   - Chrome terlalu baru       -> pakai Chrome 146 dari @puppeteer/browsers
 *   - WA Web reload            -> --disable-service-worker
 *   - balas broadcast/status   -> filter @g.us, isStatus, fromMe, broadcast
 *   - SingletonLock menghalangi -> dibersihkan di start.sh
 */
import fs from 'node:fs';
import path from 'node:path';

import pkg from 'whatsapp-web.js';

import { config } from '../config.js';
import { log, maskNumber, nomorPenuh } from '../core/logger.js';
import { alasanDiabaikan, harusDiabaikan, nomorDariChatId } from '../core/wa-filter.js';

// whatsapp-web.js adalah CJS: named import gagal di proyek ESM.
const { Client, LocalAuth, MessageMedia } = pkg;

/**
 * Cari binary Chrome.
 *
 * Urutan: env BOT_CHROME_PATH -> Chrome 146 bundel -> Chrome sistem.
 * Flatpak sengaja TIDAK dipakai: Chrome-nya terlalu baru dan membuat
 * whatsapp-web.js gagal inject ("Execution context was destroyed").
 */
export function cariChrome() {
    if (config.chromePath) {
        if (!fs.existsSync(config.chromePath)) {
            throw new Error(`BOT_CHROME_PATH menunjuk berkas yang tidak ada: ${config.chromePath}`);
        }
        return config.chromePath;
    }

    const bundel = path.join(config.root, 'chrome', 'linux-146.0.7680.153', 'chrome-linux64', 'chrome');
    if (fs.existsSync(bundel)) return bundel;

    for (const kandidat of ['/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser']) {
        if (fs.existsSync(kandidat)) return kandidat;
    }

    throw new Error(
        'Chrome tidak ditemukan. Jalankan: npx @puppeteer/browsers install chrome@146.0.7680.153\n' +
            'lalu set BOT_CHROME_PATH ke chrome/linux-146.0.7680.153/chrome-linux64/chrome',
    );
}

/** Argumen Chrome yang wajib untuk server tanpa display. */
export function argumenChrome() {
    return [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        // /dev/shm kecil di VPS: tanpa ini Chrome crash saat render
        '--disable-dev-shm-usage',
        '--disable-gpu',
        // mencegah WA Web reload sendiri yang memutus execution context
        '--disable-service-worker',
        '--disable-background-networking',
        '--disable-extensions',
        '--disable-sync',
        '--no-first-run',
        '--no-default-browser-check',
    ];
}

/**
 * Pesan yang harus diabaikan — lihat `core/wa-filter.js` (diuji terpisah).
 *
 * `broadcast` paling sering terlewat: tanpa filter ini bot membalas setiap
 * pesan daftar siaran dan bisa membanjiri kontak.
 */
export { harusDiabaikan, nomorDariChatId };

/** Ambil gambar dari URL menjadi MessageMedia. */
async function mediaDariUrl(url) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 20_000);

    let res;
    try {
        res = await fetch(url, { signal: controller.signal });
    } finally {
        clearTimeout(timer);
    }

    if (!res.ok) throw new Error(`gambar membalas ${res.status}`);

    const buf = Buffer.from(await res.arrayBuffer());

    // Placeholder 0 byte pernah membuat Chrome crash di bot sebelumnya.
    if (buf.length < 100) throw new Error('berkas gambar kosong/terlalu kecil');

    const mime = res.headers.get('content-type') || 'image/jpeg';

    return new MessageMedia(mime, buf.toString('base64'), 'qris.jpg');
}

/**
 * Apakah sesi WhatsApp (LocalAuth) sudah pernah disimpan?
 *
 * Kalau ya, bot seharusnya login otomatis tanpa QR. Kalau tidak, QR akan
 * muncul dan pembeli harus scan sekali.
 *
 * @param {string} [akar] folder sesi (default: config.sessionPath)
 */
export function sesiTersimpanAda(akar = config.sessionPath) {

    if (!fs.existsSync(akar)) return false;

    let ada = false;

    try {
        for (const nama of fs.readdirSync(akar)) {
            if (!nama.startsWith('session')) continue;

            const jalur = path.join(akar, nama);

            if (!fs.statSync(jalur).isDirectory()) continue;

            if (fs.readdirSync(jalur).length > 0) {
                ada = true;
                break;
            }
        }
    } catch {
        return false;
    }

    return ada;
}

/**
 * Buat adapter WhatsApp.
 *
 * @param {{onMessage: (pesan: {teks: string, chatId: string, nomor: string}) => Promise<void>}} handler
 */
/**
 * Identitas pembeli dari sebuah pesan.
 *
 * WhatsApp kini sering mengirim chat dengan alamat `@lid` (Linked Identity),
 * yang bagian angkanya BUKAN nomor HP. Kita coba ubah ke JID berbasis nomor
 * HP supaya pesanan & pengiriman memakai nomor yang benar; kalau gagal, pakai
 * LID (tetap konsisten untuk pesanan yang dibuat & dicek bot).
 *
 * @returns {Promise<{nomor: string, lid: string|null, sendTo: string}>}
 */
async function identitasPembeli(msg, client) {
    const chatId = String(msg.from || '');
    const dasar = nomorDariChatId(chatId);

    // Chat biasa (@c.us / @s.whatsapp.net): tidak ada yang perlu diubah.
    if (!chatId.endsWith('@lid')) {
        return { nomor: dasar, lid: null, sendTo: chatId };
    }

    // ---------------------------------------------------------------- strategi 1
    // getNumberId mengubah LID menjadi JID nomor HP. whatsapp-web.js kini
    // memakai @s.whatsapp.net (bukan @c.us) untuk JID berbasis nomor.
    try {
        const hasil = await client.getNumberId(dasar);
        const jid = hasil?._serialized;

        log.debug(`getNumberId(${dasar}) -> ${jid || '(kosong)'}`);

        if (jid && (jid.endsWith('@c.us') || jid.endsWith('@s.whatsapp.net'))) {
            const nomor = nomorDariChatId(jid);

            if (nomorValid(nomor)) {
                log.debug(`@lid ${maskNumber(dasar)} -> nomor HP ${maskNumber(nomor)}`);
                return { nomor, lid: dasar, sendTo: jid };
            }
        }
    } catch (e) {
        log.debug(`getNumberId gagal: ${e.message}`);
    }

    // ---------------------------------------------------------------- strategi 2
    // Data kontak bisa memuat nomor HP walau JID-nya @lid.
    try {
        const kontak = await client.getContactById(chatId);
        const mentah = kontak?.number || kontak?.numberFormatted || '';
        const nomor = String(mentah).replace(/\D/g, '');

        log.debug(`kontak ${chatId} -> number=${mentah || '(kosong)'}`);

        if (nomorValid(nomor)) {
            return { nomor, lid: dasar, sendTo: `${nomor}@c.us` };
        }
    } catch (e) {
        log.debug(`getContactById gagal: ${e.message}`);
    }

    // ---------------------------------------------------------------- strategi 3
    // Tetap pakai LID. Bot bisa membuat & mengecek pesanan, tapi admin tidak
    // bisa menghubungi pembeli lewat wa.me. Pembeli akan diminta nomor HP-nya.
    log.debug(`Chat @lid, pakai LID ${maskNumber(dasar)} sebagai identitas pembeli.`);
    return { nomor: dasar, lid: dasar, sendTo: chatId };
}

/** Nomor WhatsApp Indonesia yang masuk akal: 62 + 8..14 digit. */
export function nomorValid(nomor) {
    return /^62\d{8,14}$/.test(String(nomor || '').replace(/\D/g, ''));
}

export function buatAdapterWhatsApp(handler) {
    const chrome = cariChrome();

    log.info(`Chrome: ${chrome}`);

    // Sebelum inisialisasi: kalau sesi sudah ada, beri tahu pengguna bahwa bot
    // akan login otomatis tanpa QR.
    if (sesiTersimpanAda()) {
        log.info('Sesi tersimpan ditemukan — bot akan login otomatis tanpa QR.');
    } else {
        log.info('Belum ada sesi tersimpan — QR akan muncul, scan sekali saja.');
    }

    const opsi = {
        authStrategy: new LocalAuth({ dataPath: config.sessionPath }),
        puppeteer: {
            headless: config.headless,
            executablePath: chrome,
            args: argumenChrome(),
        },
    };

    // Pin versi WA Web kalau diminta. Tanpa pin, pembaruan WhatsApp di server
    // mereka bisa memutus proses inject.
    if (config.webVersion) {
        opsi.webVersion = config.webVersion;
        opsi.webVersionCache = {
            type: 'remote',
            remotePath: `https://raw.githubusercontent.com/wppconnectteam/wa-version/main/html/${config.webVersion}.html`,
        };
        log.info(`WA Web dipin ke ${config.webVersion}`);
    }

    const client = new Client(opsi);

    /** Antrean kirim per chat supaya balasan tidak datang tidak berurutan. */
    const antrean = new Map();

    /**
     * JID tujuan per chat. Untuk chat `@lid` kita simpan JID berbasis nomor
     * HP (kalau berhasil diubah) supaya pengiriman tidak bergantung pada
     * dukungan `@lid` di whatsapp-web.js.
     */
    const sendToPerChat = new Map();

    function antre(chatId, tugas) {
        const sebelumnya = antrean.get(chatId) || Promise.resolve();
        const berikutnya = sebelumnya.then(tugas, tugas).catch((e) => {
            log.error(`Gagal mengirim ke ${maskNumber(nomorDariChatId(chatId))}: ${e.message}`);
        });

        antrean.set(chatId, berikutnya);

        // Bersihkan entri saat antrean kosong supaya Map tidak tumbuh.
        berikutnya.finally(() => {
            if (antrean.get(chatId) === berikutnya) antrean.delete(chatId);
        });

        return berikutnya;
    }

    /**
     * Kirim teks dengan retry saat WA Web sedang reload.
     * `Execution context was destroyed` / `detached Frame` bersifat sementara.
     */
    async function kirimTeks(chatId, teks) {
        for (let i = 0; i < 3; i += 1) {
            try {
                await client.sendMessage(chatId, teks);
                log.debug(`Terkirim ke ${maskNumber(nomorDariChatId(chatId))} (${teks.length} karakter)`);
                return true;
            } catch (e) {
                const sementara = /Execution context was destroyed|detached Frame|Target closed/i.test(e.message || '');

                if (!sementara || i === 2) throw e;

                log.warn(`Konteks WA hilang, ulangi ${i + 1}/3…`);
                await new Promise((r) => setTimeout(r, 3000));
            }
        }

        return false;
    }

    /** Kirim gambar; kalau gagal, kirim teks pengganti supaya pembeli tetap tahu. */
    async function kirimGambar(chatId, url, caption) {
        try {
            const media = await mediaDariUrl(url);
            await client.sendMessage(chatId, media, { caption });
            return true;
        } catch (e) {
            log.warn(`Gambar gagal dikirim (${e.message}); fallback teks.`);
            await kirimTeks(chatId, caption);
            return false;
        }
    }

    client.on('qr', (qr) => {
        // QR hanya perlu ditampilkan kalau sesi tersimpan TIDAK cukup untuk
        // login otomatis. Kalau sesi ada, WhatsApp Web biasanya langsung
        // `authenticated` tanpa QR — jadi handler ini tidak dipanggil.
        if (sesiTersimpanAda()) {
            log.warn('Sesi tersimpan ada, tapi WhatsApp tetap meminta QR. Sesi mungkin kedaluwarsa — scan ulang.');
        } else {
            log.info('Belum ada sesi tersimpan. Scan QR berikut dengan WhatsApp > Perangkat Tertaut.');
        }

    // QR ditampilkan oleh index.js lewat qrcode-terminal. Jangan panggil
    // qrcode.generate di sini agar tidak duplikat.
    if (handler.onQr) {
        handler.onQr(qr);
    }
    });

    client.on('authenticated', () => {
        // Event ini dipanggil kalau login berhasil dari sesi tersimpan
        // MAUPUN dari scan QR. Ini bukti bahwa sesi valid.
        log.info('Autentikasi berhasil. Sesi tersimpan untuk login otomatis berikutnya.');
    });

    client.on('ready', () => {
        // Cetak nomor bot sendiri supaya mudah dibandingkan dengan nomor
        // pengirim — penyebab paling umum bot "diam" adalah pesan dari diri
        // sendiri yang difilter.
        const wid = client.info?.wid?._serialized || client.info?.wid || '(tidak terbaca)';
        const nomorBot = nomorDariChatId(wid);

        log.info(`WhatsApp siap. Nomor bot: ${nomorPenuh(nomorBot)}`);
        log.info('Bot siap menerima pesan (tanpa perlu scan ulang).');
        handler.onReady?.();
    });

    client.on('auth_failure', (pesan) => {
        log.error(`Autentikasi gagal: ${pesan}. Hapus ${config.sessionPath} lalu scan ulang.`);
    });

    client.on('disconnected', (alasan) => {
        log.error(`Terputus dari WhatsApp: ${alasan}`);
        handler.onDisconnected?.(alasan);
    });

    client.on('message', async (msg) => {
        const alasan = alasanDiabaikan(msg, config.allowSelf);

        if (alasan !== '') {
            log.debug(`Pesan diabaikan (${alasan}) dari ${maskNumber(nomorDariChatId(msg.from))}`);
            return;
        }

        const chatId = msg.from;
        const { nomor, lid, sendTo } = await identitasPembeli(msg, client);
        sendToPerChat.set(chatId, sendTo);
        const teks = String(msg.body || '');
        const nama = String(msg._data?.notifyName || msg._data?.verifiedName || '').slice(0, 80);
        const hasMedia = Boolean(msg.hasMedia);

        // Satu pesan error tidak boleh mematikan proses bot.
        try {
            log.info(`Pesan masuk dari ${maskNumber(nomor)}${lid ? ` (lid ${maskNumber(lid)})` : ''}${hasMedia ? ' [gambar]' : ''}: ${teks.slice(0, 60)}`);
            await handler.onMessage({ teks, chatId, nomor, sendTo, nama, hasMedia });
        } catch (e) {
            log.error(`Handler pesan gagal: ${e.message}`);
        }
    });

    return {
        client,

        mulai: () => client.initialize(),

        async kirim(chatId, teks) {
            const tujuan = sendToPerChat.get(chatId) || chatId;

            return antre(chatId, () => kirimTeks(tujuan, teks));
        },

        async kirimMedia(chatId, url, caption) {
            const tujuan = sendToPerChat.get(chatId) || chatId;

            return antre(chatId, () => kirimGambar(tujuan, url, caption));
        },

        async berhenti() {
            try {
                await client.destroy();
            } catch {
                // diabaikan: proses memang sedang berhenti
            }
        },
    };
}
