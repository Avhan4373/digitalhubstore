/**
 * Skrip uji sekali jalan: kirim gambar QRIS ke satu chat tanpa menunggu pesan
 * pembeli. Dipakai untuk membuktikan pengiriman media benar-benar jalan setelah
 * patch whatsapp-web.js.
 *
 * Jalankan (bot harus berhenti dulu supaya sesi tidak dipakai dua proses):
 *   node uji-media.mjs 628xxxxxxxxxx@c.us "caption uji"
 */
import { buatAdapterWhatsApp } from './src/adapters/whatsapp.js';
import { config } from './src/config.js';
import { patchWhatsappWeb } from './src/patch-lib.js';

const tujuan = process.argv[2];
const caption = process.argv[3] || '🧪 UJI MEDIA — abaikan pesan ini';

if (!tujuan) {
    console.error('pakai: node uji-media.mjs <chatId tujuan> [caption]');
    process.exit(1);
}

console.log(`[uji] patch media: ${patchWhatsappWeb()}`);
console.log(`[uji] berkas: ${config.qrisLokal}`);
console.log(`[uji] tujuan: ${tujuan}`);

const adapter = buatAdapterWhatsApp({
    async onReady() {
        const terkirim = await adapter.kirimMedia(tujuan, config.qrisLokal, caption);

        console.log(`[uji] hasil kirim media: ${terkirim ? 'TERKIRIM' : 'GAGAL'}`);
        await adapter.berhenti();
        process.exit(terkirim ? 0 : 1);
    },
});

await adapter.mulai();
