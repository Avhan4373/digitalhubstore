/**
 * Konfigurasi bot dari environment.
 *
 * Semua nilai punya default yang aman: tanpa `.env` yang benar bot menolak
 * start (bukan jalan dengan setelan setengah jadi).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { normalisasiNomor } from './core/format.js';

export const ROOT = path.dirname(path.dirname(fileURLToPath(import.meta.url)));

/** Baca .env sederhana (tanpa dependency tambahan). */
function loadEnv() {
    const file = path.join(ROOT, '.env');
    if (!fs.existsSync(file)) return;

    for (const line of fs.readFileSync(file, 'utf8').split('\n')) {
        const trimmed = line.trim();
        if (trimmed === '' || trimmed.startsWith('#')) continue;

        const eq = trimmed.indexOf('=');
        if (eq === -1) continue;

        const key = trimmed.slice(0, eq).trim();
        let value = trimmed.slice(eq + 1).trim();

        // buang kutip pembungkus
        if (
            (value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"))
        ) {
            value = value.slice(1, -1);
        }

        if (process.env[key] === undefined) process.env[key] = value;
    }
}

loadEnv();

/**
 * Daftar nomor dari env: "0823…, +62 823…" -> ["62823…"].
 *
 * Ditulis `08…` atau `62…` sama saja — dinormalkan supaya perbandingan
 * `includes()` di engine selalu cocok tanpa bergantung cara penulisan di `.env`.
 */
function daftarNomor(nilai) {
    return String(nilai || '')
        .split(',')
        .map((n) => normalisasiNomor(n))
        .filter(Boolean);
}

/** Path absolut berkas QRIS lokal (BOT_QRIS_FILE, default `qris.jpeg` di root bot). */
function berkasQrisLokal() {
    const jalur = path.resolve(ROOT, process.env.BOT_QRIS_FILE || 'qris.jpeg');

    return fs.existsSync(jalur) ? jalur : null;
}

function required(name) {
    const value = (process.env[name] || '').trim();
    if (value === '') {
        throw new Error(
            `Konfigurasi ${name} belum diisi. Salin .env.example menjadi .env lalu isi nilainya.`,
        );
    }
    return value;
}

export const config = {
    /** Folder proyek bot (untuk mencari Chrome bundel). */
    root: ROOT,

    /** URL aplikasi Laravel (tanpa garis miring di akhir). */
    apiUrl: (process.env.BOT_API_URL || 'http://127.0.0.1:8000').replace(/\/+$/, ''),

    /** Token yang sama dengan BOT_API_TOKEN di .env Laravel. */
    apiToken: required('BOT_API_TOKEN'),

    /**
     * Nomor admin — satu-satunya nomor yang boleh memakai perintah
     * `proses`/`done`, sekaligus penerima notifikasi pesanan baru & bukti
     * transfer. Boleh ditulis `08…` atau `62…` (dinormalkan ke `62…`).
     */
    adminNumbers: daftarNomor(process.env.BOT_ADMIN_NUMBERS),

    /** Folder sesi WhatsApp (LocalAuth). */
    sessionPath: process.env.BOT_SESSION_PATH || path.join(ROOT, '.wwebjs_auth'),

    /** Folder cache Chrome. */
    cachePath: process.env.BOT_CACHE_PATH || path.join(ROOT, '.wwebjs_cache'),

    /** Path Chrome. Kosong = cari otomatis. */
    chromePath: process.env.BOT_CHROME_PATH || '',

    /** Tampilkan QR di terminal. */
    headless: process.env.BOT_HEADLESS !== 'false',

    /**
     * Batas waktu protokol CDP untuk operasi Chrome (ms).
     *
     * Default puppeteer 180 detik. Kalau mesin sedang sibuk (load tinggi,
     * RAM tipis, Chrome pertama kali membuka profil sesi), inject WhatsApp
     * Web bisa melewati batas itu dan bot mati dengan
     * `Runtime.callFunctionOn timed out` padahal Chrome-nya sehat.
     */
    protocolTimeout: Number(process.env.BOT_PROTOCOL_TIMEOUT || 420_000),

    /** Batas pesan masuk per nomor per menit (mirror pengaturan Laravel). */
    rateLimitPerMinute: Number(process.env.BOT_RATE_LIMIT || 20),

    /** Balas hanya ke nomor ini kalau diisi (untuk uji coba produksi). */
    allowlist: daftarNomor(process.env.BOT_ALLOWLIST),

    /**
     * ID grup (@g.us) yang boleh dilayani. Kosong = SEMUA grup diabaikan
     * (default produksi). Isi hanya untuk grup uji coba milik sendiri.
     */
    groupAllowlist: (process.env.BOT_GROUP_ALLOWLIST || '')
        .split(',')
        .map((g) => g.trim())
        .filter(Boolean),

    /** Log level: debug | info | warn | error */
    logLevel: process.env.BOT_LOG_LEVEL || 'info',

    /** Uji coba dari nomor yang sama dengan bot (tanpa HP kedua). */
    allowSelf: process.env.BOT_ALLOW_SELF === 'true',

    /** Berkas log opsional (mis. /tmp/bot-wa.log) untuk membaca riwayat. */
    logFile: process.env.BOT_LOG_FILE || '',

    /** Versi WA Web yang dipin (mencegah update WhatsApp merusak inject). */
    webVersion: process.env.BOT_WEB_VERSION || '',

    /**
     * Batas produk yang ditampilkan sekali `list`. Katalog toko bisa ratusan
     * produk; satu pesan sepanjang itu tidak terbaca di ponsel. Produk di luar
     * batas tetap bisa dicari lewat nama.
     */
    batasProduk: Math.max(1, Number(process.env.BOT_MAX_PRODUCTS || 50)),

    /**
     * Berkas QRIS statis milik bot yang dikirim perintah `pay` (path absolut),
     * atau `null` kalau berkasnya tidak ada — pemanggil lalu jatuh ke gambar
     * QRIS dari pengaturan toko (`store.qris_url`). Berkas lokal diprioritaskan
     * karena tidak bergantung pada API/penyimpanan Laravel.
     */
    qrisLokal: berkasQrisLokal(),
};

/** Validasi tambahan yang butuh beberapa nilai sekaligus. */
export function assertConfig() {
    if (!/^https?:\/\//.test(config.apiUrl)) {
        throw new Error(`BOT_API_URL harus diawali http:// atau https:// (sekarang: ${config.apiUrl})`);
    }

    if (config.adminNumbers.some((n) => !/^62\d{8,14}$/.test(n))) {
        throw new Error(
            'BOT_ADMIN_NUMBERS harus nomor Indonesia yang masuk akal '
            + '(mis. 082395124106 atau 6282395124106), pisahkan dengan koma.',
        );
    }
}
