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
import { log, maskNumber } from '../core/logger.js';
import { harusDiabaikan, nomorDariChatId } from '../core/wa-filter.js';

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
        log.info('WhatsApp siap. Bot siap menerima pesan (tanpa perlu scan ulang).');
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
        if (harusDiabaikan(msg)) return;

        const chatId = msg.from;
        const nomor = nomorDariChatId(chatId);
        const teks = String(msg.body || '');

        // Satu pesan error tidak boleh mematikan proses bot.
        try {
            log.debug(`Pesan masuk dari ${maskNumber(nomor)}: ${teks.slice(0, 60)}`);
            await handler.onMessage({ teks, chatId, nomor });
        } catch (e) {
            log.error(`Handler pesan gagal: ${e.message}`);
        }
    });

    return {
        client,

        mulai: () => client.initialize(),

        async kirim(chatId, teks) {
            return antre(chatId, () => kirimTeks(chatId, teks));
        },

        async kirimMedia(chatId, url, caption) {
            return antre(chatId, () => kirimGambar(chatId, url, caption));
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
