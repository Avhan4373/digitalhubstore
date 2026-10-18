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
import { alasanDiabaikan, harusDiabaikan, adalahLid, nomorDariChatId, nomorDariKontak } from '../core/wa-filter.js';

// whatsapp-web.js adalah CJS: named import gagal di proyek ESM.
const { Client, LocalAuth, MessageMedia } = pkg;

/**
 * ID penuh (bentuk store) pesan WhatsApp, untuk quote/reply.
 *
 * Di whatsapp-web.js v1.34 `msg.id` TIDAK lagi punya `_serialized` langsung.
 * Bentuk nyatanya (dibuktikan lewat log runtime):
 *   {fromMe, remote, id, participant?, $1}
 * `$1` = kunci store asli yang dipakai `WAWebCollections.Msg.get()`
 * (mis. `false_...@g.us_3EB0ABCD_545...@lid` — grup menyertakan participant).
 * Dipakai lebih dulu; kalau tidak ada, susun sendiri dari bagian-bagiannya.
 */
function idPesanPenuh(msg) {
    const id = msg?.id;

    if (!id) return null;

    if (typeof id === 'string') return id;
    if (id._serialized) return id._serialized;
    if (id.$1) return id.$1;

    const remote = typeof id.remote === 'object'
        ? id.remote?._serialized || ''
        : String(id.remote || '');

    if (!remote || !id.id) return null;

    const peserta = id.participant
        ? `_${typeof id.participant === 'object' ? id.participant._serialized : id.participant}`
        : '';

    return `${id.fromMe ? 'true' : 'false'}_${remote}_${id.id}${peserta}`;
}

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

/** Tebak mime dari ekstensi berkas gambar lokal. */
function mimeDariJalur(jalur) {
    const ext = path.extname(jalur).toLowerCase();

    if (ext === '.png') return 'image/png';
    if (ext === '.webp') return 'image/webp';
    if (ext === '.gif') return 'image/gif';

    return 'image/jpeg';
}

/** Baca gambar dari berkas lokal (mis. `qris.jpeg` milik bot) jadi MessageMedia. */
function mediaDariBerkas(jalur) {
    // Placeholder 0 byte pernah membuat Chrome crash di bot sebelumnya.
    if (!fs.existsSync(jalur)) throw new Error(`berkas tidak ada: ${jalur}`);

    const buf = fs.readFileSync(jalur);

    if (buf.length < 100) throw new Error('berkas gambar kosong/terlalu kecil');

    return new MessageMedia(mimeDariJalur(jalur), buf.toString('base64'), path.basename(jalur));
}

/**
 * Gambar (MessageMedia) dari URL http(s) ATAU berkas lokal.
 *
 * Berkas lokal dipakai untuk QRIS statis milik bot supaya tidak bergantung
 * pada API Laravel; URL tetap didukung untuk QRIS dari pengaturan toko.
 */
async function mediaDari(jalurAtauUrl) {
    return /^https?:\/\//i.test(jalurAtauUrl)
        ? mediaDariUrl(jalurAtauUrl)
        : mediaDariBerkas(jalurAtauUrl);
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
 * yang bagian angkanya BUKAN nomor HP. whatsapp-web.js sudah menerjemahkan LID
 * itu ke JID nomor HP pada objek kontak (`kontak.id`), jadi di situlah nomor
 * asli pembeli dibaca.
 *
 * Dua jebakan yang sengaja dihindari:
 *   - `kontak.number` selalu kosong pada whatsapp-web.js v1.34.
 *   - `client.getNumberId()` menambahkan `@c.us` ke argumennya, sehingga angka
 *     LID diperlakukan sebagai nomor HP dan selalu gagal.
 *
 * @returns {Promise<{nomor: string, lid: string|null, sendTo: string}>}
 */
async function identitasPembeli(msg, client) {
    const chatId = String(msg.from || '');

    // Di grup, `msg.from` adalah ID grup dan pengirim sebenarnya ada di
    // `msg.author`. Identitas pembeli selalu diambil dari pengirim, sedangkan
    // balasan tetap dikirim ke chat asal (`sendTo`).
    const pengirim = String(msg.author || chatId);
    const dasar = nomorDariChatId(pengirim);

    // Chat biasa (@c.us / @s.whatsapp.net): tidak ada yang perlu diubah.
    if (!adalahLid(pengirim)) {
        return { nomor: dasar, lid: null, sendTo: chatId };
    }

    try {
        const kontak = await client.getContactById(pengirim);
        const nomor = nomorDariKontak(kontak);

        log.debug(`kontak ${pengirim} -> id=${kontak?.id?._serialized || kontak?.id || '(kosong)'}`);

        if (nomor) {
            log.debug(`@lid ${maskNumber(dasar)} -> nomor HP ${maskNumber(nomor)}`);

            // Balasan tetap dikirim ke chat asal supaya masuk ke percakapan
            // yang sama — bukan membuat chat baru.
            return { nomor, lid: dasar, sendTo: chatId };
        }
    } catch (e) {
        log.debug(`getContactById gagal: ${e.message}`);
    }

    // Nomor tetap tidak bisa dipastikan: pakai LID sebagai identitas sementara.
    // Engine akan meminta nomor HP pembeli sekali sebelum pesanan dibuat.
    log.debug(`Chat @lid, pakai LID ${maskNumber(dasar)} sebagai identitas pembeli.`);
    return { nomor: dasar, lid: dasar, sendTo: chatId };
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
     *
     * `opsi.balasId` (opsional) membuat pesan jadi reply/quote. Quote HARUS
     * dilakukan ke chat yang memuat pesan yang diquote: di chat `@lid` tujuan
     * dikonversi ke JID nomor HP, dan ID pesan `@lid` tidak dikenal di chat
     * itu — WhatsApp diam-diam membuang quote (whatsapp-web.js
     * `ignoreQuoteErrors` default true). Karena itu percobaan quote selalu ke
     * chat asal dulu; kalau gagal/tidak terkirim, kirim biasa ke tujuan.
     */
    /**
     * Baca dari store WhatsApp apakah pesan TERAKHIR di chat benar-benar
     * menyimpan data quote.
     *
     * Nilai balik `sendMessage()` tidak bisa dipercaya di v1.34.7
     * (`newMsgKey._serialized` kosong → mengembalikan `undefined` walau pesan
     * terkirim), jadi satu-satunya bukti yang sahih adalah model pesan yang
     * tersimpan: pesan ber-quote punya kunci `quotedStanzaID`/`quotedMsg`.
     */
    async function cekKutipanTersimpan(chatId) {
        return client.pupPage
            .evaluate((id) => {
                const chat = window.require('WAWebCollections').Chat.get(id);
                const daftar = chat?.msgs?.getModelsArray?.() || [];
                const terakhir = daftar[daftar.length - 1];

                if (!terakhir) return 'tidak-ada-pesan';

                const kunci = Object.keys(terakhir).filter((k) => /^quoted/i.test(k));

                return kunci.length > 0 ? `menempel (${kunci.join(',')})` : 'TIDAK menempel';
            }, chatId)
            .catch((e) => `tak-terbaca=${e.message}`);
    }

    /**
     * Catat hasil kirim + status quote.
     *
     * PENTING: whatsapp-web.js v1.34.7 mengembalikan `undefined` dari
     * `sendMessage()` kalau `newMsgKey._serialized` kosong (bug @lid MsgKey),
     * padahal pesannya BENAR-BENAR terkirim. Jadi `undefined` tidak boleh
     * dianggap gagal — status quote dibaca ulang dari store (lihat
     * `cekKutipanTersimpan`).
     */
    function logKirim(label, chatId, pesan, dicobaQuote) {
        const bisaDibaca = Boolean(pesan);
        const menempel = Boolean(pesan?.hasQuotedMsg ?? pesan?._data?.quotedMsg);
        const status = dicobaQuote
            ? ` | quote=${!bisaDibaca ? 'tak-terbaca' : menempel ? 'menempel' : 'TIDAK menempel'}`
            : '';

        log.debug(`Terkirim ke ${maskNumber(nomorDariChatId(chatId))} (${label})${status}`);
    }

    /**
     * Diagnostik quote (level debug): apakah ID pesan ada di store WhatsApp,
     * boleh dibalas, dan apa saja isi `msgContextInfo()`-nya.
     */
    async function cekQuote(pesanId, chatQuote) {
        const hasil = await client.pupPage
            .evaluate(async (id, chatId) => {
                try {
                    const Msg = window.require('WAWebCollections').Msg;
                    const pesan = Msg.get(id);

                    if (!pesan) return 'tidak-ada-di-store';

                    const Reply = window.require('WAWebMsgReply');
                    const boleh = Reply ? Reply.canReplyMsg(pesan.unsafe()) : pesan.canReply();

                    let kunci = '';
                    try {
                        const chat = await window.WWebJS.getChat(chatId, { getAsModel: false });
                        kunci = Object.keys(pesan.msgContextInfo(chat)).join(',') || '(kosong)';
                    } catch (e) {
                        kunci = `gagal=${e.message}`;
                    }

                    return `ada|canReply=${boleh}|ctx=${kunci}`;
                } catch (e) {
                    return `error=${e.message}`;
                }
            }, pesanId, chatQuote)
            .catch((e) => `tidak-bisa-cek=${e.message}`);

        log.debug(`cek-quote ${pesanId} -> ${hasil}`);
    }

    /**
     * Kirim SATU pesan dengan quote yang tidak pernah memotong balasan.
     *
     * `buat(tujuan, opsi)` membangun panggilan `client.sendMessage`. Urutannya:
     *   1. kalau tujuan kirim berbeda dari chat asal (kasus DM `@lid`), quote
     *      dicoba dulu KE CHAT ASAL — ID pesan hanya dikenal di chat itu;
     *   2. kalau panggilan itu MELEMPAR error, kirim biasa tanpa quote (dengan
     *      3x ulang untuk error konteks WA yang hilang, mis. WhatsApp reload).
     *
     * Nilai balik `sendMessage()` tidak dipakai sebagai penentu berhasil/gagal:
     * di v1.34.7 fungsi itu mengembalikan `undefined` walau pesan terkirim
     * (bug `newMsgKey._serialized`), jadi mengeceknya bisa memicu kirim DOBEL.
     *
     * @returns {Promise<boolean>} true kalau panggilan kirim tidak melempar error
     */
    async function kirimKe(chatId, tujuan, buat, label, balasId) {
        if (balasId && tujuan !== chatId) {
            try {
                logKirim(label, chatId, await buat(chatId, { quotedMessageId: balasId }), true);
                log.debug(`cek-kutipan (chat asal) -> ${await cekKutipanTersimpan(chatId)}`);
                return true;
            } catch (e) {
                log.warn(`Quote ke chat asal gagal (${e.message}); kirim biasa.`);
            }
        }

        for (let i = 0; i < 3; i += 1) {
            const opsiQuote = i === 0 && balasId && tujuan === chatId
                ? { quotedMessageId: balasId }
                : undefined;

            try {
                logKirim(label, chatId, await buat(tujuan, opsiQuote), Boolean(opsiQuote));

                if (opsiQuote) {
                    log.debug(`cek-kutipan -> ${await cekKutipanTersimpan(tujuan)}`);
                }

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

    async function kirimTeks(chatId, teks, opsi = {}) {
        const tujuan = sendToPerChat.get(chatId) || chatId;

        log.debug(`kirimTeks chat=${maskNumber(nomorDariChatId(chatId))} tujuan=${maskNumber(nomorDariChatId(tujuan))} balasId=${opsi.balasId ? 'ada' : '-'}`);

        if (opsi.balasId) await cekQuote(opsi.balasId, chatId);

        return kirimKe(
            chatId,
            tujuan,
            (kirimKeTujuan, opsiKirim) => client.sendMessage(kirimKeTujuan, teks, opsiKirim),
            `${teks.length} karakter`,
            opsi.balasId,
        );
    }

    /**
     * Kirim gambar (berkas lokal atau URL) dengan caption. Bisa ikut meng-quote
     * pesan pembeli seperti pesan teks; kalau gambarnya gagal, caption dikirim
     * sebagai teks supaya pembeli tetap tahu.
     */
    async function kirimGambar(chatId, sumber, caption, opsi = {}) {
        const tujuan = sendToPerChat.get(chatId) || chatId;

        log.debug(`kirimGambar chat=${maskNumber(nomorDariChatId(chatId))} tujuan=${maskNumber(nomorDariChatId(tujuan))} balasId=${opsi.balasId ? 'ada' : '-'}`);

        let media;
        try {
            media = await mediaDari(sumber);
        } catch (e) {
            log.warn(`Gambar gagal dibaca (${e.message}); fallback teks.`);
            return kirimTeks(chatId, caption, opsi);
        }

        if (opsi.balasId) await cekQuote(opsi.balasId, chatId);

        try {
            return await kirimKe(
                chatId,
                tujuan,
                (tujuanKirim, opsiKirim) => client.sendMessage(tujuanKirim, media, {
                    caption,
                    ...(opsiKirim || {}),
                }),
                `gambar ${path.basename(String(sumber))}`,
                opsi.balasId,
            );
        } catch (e) {
            log.warn(`Gambar gagal dikirim (${e.message}); fallback teks.`);
            return kirimTeks(chatId, caption, opsi);
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
        const alasan = alasanDiabaikan(msg, config.allowSelf, config.groupAllowlist);

        if (alasan !== '') {
            log.debug(`Pesan diabaikan (${alasan}) dari ${maskNumber(nomorDariChatId(msg.author || msg.from))}`);
            return;
        }

        const chatId = msg.from;
        const { nomor, lid, sendTo } = await identitasPembeli(msg, client);
        sendToPerChat.set(chatId, sendTo);
        const teks = String(msg.body || '');
        const nama = String(msg._data?.notifyName || msg._data?.verifiedName || '').slice(0, 80);
        const hasMedia = Boolean(msg.hasMedia);
        const dariGrup = String(chatId).includes('@g.us');

        // Nama grup (dipakai balasan ADMIN "TRANSAKSI ...": baris 📂 Grup).
        let grup = '';
        if (dariGrup) {
            grup = await msg.getChat()
                .then((chat) => String(chat?.name || '').slice(0, 80))
                .catch((e) => {
                    log.debug(`Nama grup tidak terbaca: ${e.message}`);
                    return '';
                });
        }
        // ID pesan pembeli: dipakai agar balasan pertama menjadi reply/quote
        // ke pesan ini, bukan pesan terpisah.
        const pesanId = idPesanPenuh(msg);
        log.debug(`pesanId=${pesanId ?? '(kosong)'} | mentah=${JSON.stringify(msg.id)} | chat=${maskNumber(nomorDariChatId(chatId))}`);

        // Satu pesan error tidak boleh mematikan proses bot.
        try {
            log.info(`Pesan masuk dari ${maskNumber(nomor)}${lid ? ` (lid ${maskNumber(lid)})` : ''}${dariGrup ? ' [grup]' : ''}${hasMedia ? ' [gambar]' : ''}: ${teks.slice(0, 60)}`);
            await handler.onMessage({ teks, chatId, nomor, sendTo, nama, hasMedia, pesanId, grup });
        } catch (e) {
            log.error(`Handler pesan gagal: ${e.message}`);
        }
    });

    return {
        client,

        mulai: () => client.initialize(),

        async kirim(chatId, teks, opsi = {}) {
            // chatId = chat ASAL pesan (peta sendToPerChat menunjuk tujuan
            // kirim). kirimTeks yang menyelesaikan tujuan supaya bisa memilih
            // chat mana yang dipakai untuk quote.
            return antre(chatId, () => kirimTeks(chatId, teks, opsi));
        },

        async kirimMedia(chatId, sumber, caption, opsi = {}) {
            return antre(chatId, () => kirimGambar(chatId, sumber, caption, opsi));
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
