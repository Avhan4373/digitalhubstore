/**
 * Skrip uji sekali jalan: kirim NOTIFIKASI BUKTI TRANSFER (gambar + keterangan)
 * ke nomor admin, persis seperti yang dilakukan bot saat pembeli mengirim bukti
 * (`beritahuBukti()` di `src/index.js`).
 *
 * Dipakai untuk membuktikan notifikasi bergambar benar-benar sampai ke DM admin
 * — jalur ini tidak bisa diuji dari dalam bot karena butuh pesan asli dari
 * pembeli. Gambarnya dibaca lokal lalu dibungkus `MessageMedia`, PERSIS bentuk
 * yang dikembalikan `msg.downloadMedia()` di bot.
 *
 * Jalankan (bot harus berhenti dulu supaya sesi tidak dipakai dua proses):
 *   node uji-bukti.mjs                      # pakai qris.jpeg sebagai gambar uji
 *   node uji-bukti.mjs /path/gambar.jpg     # gambar lain
 */
import fs from 'node:fs';
import path from 'node:path';

import whatsappWeb from 'whatsapp-web.js';

import { buatAdapterWhatsApp } from './src/adapters/whatsapp.js';
import { config } from './src/config.js';
import { buktiMasuk } from './src/core/replies.js';
import { patchWhatsappWeb } from './src/patch-lib.js';

const { MessageMedia } = whatsappWeb;

const berkas = process.argv[2] || config.qrisLokal;

console.log(`[uji] patch media: ${patchWhatsappWeb()}`);
console.log(`[uji] admin: ${config.adminNumbers.join(', ') || '(BOT_ADMIN_NUMBERS kosong)'}`);

if (config.adminNumbers.length === 0) {
    console.error('[uji] tidak ada nomor admin yang bisa diuji.');
    process.exit(1);
}

if (!berkas || !fs.existsSync(berkas)) {
    console.error(`[uji] gambar uji tidak ditemukan: ${berkas}`);
    process.exit(1);
}

const ekstensi = path.extname(berkas).toLowerCase();
const mime = ekstensi === '.png' ? 'image/png' : 'image/jpeg';

// Bentuk yang sama dengan msg.downloadMedia() di adapter.
const gambar = new MessageMedia(mime, fs.readFileSync(berkas).toString('base64'), path.basename(berkas));

// Isi contoh — sama bentuknya dengan yang dikirim bot saat bukti masuk.
const pesan = buktiMasuk({
    nama: 'Uji Bot (abaikan)',
    nomor: '6282395124106',
    grup: 'DIGITALHUB OFFICIAL',
    transaksi: { invoice: 'INV-UJI', catatan: 'GPT PLUS SHARE 1 BULAN' },
});

console.log(`[uji] gambar: ${berkas} (${mime}, ${Math.round(gambar.data.length / 1024)} KB base64)`);

const adapter = buatAdapterWhatsApp({
    async onReady() {
        let gagal = 0;

        for (const nomor of config.adminNumbers) {
            try {
                // `undefined` dari sendMessage BUKAN kegagalan (kuirk v1.34.7);
                // hanya throw yang dianggap gagal.
                await adapter.kirimMedia(`${nomor}@c.us`, gambar, pesan);
                console.log(`[uji] notifikasi bergambar ke ${nomor}: TERKIRIM`);
            } catch (e) {
                gagal++;
                console.log(`[uji] notifikasi bergambar ke ${nomor}: GAGAL — ${e.message}`);
            }
        }

        await adapter.berhenti();
        process.exit(gagal === 0 ? 0 : 1);
    },
});

await adapter.mulai();
