/**
 * Skrip uji sekali jalan: kirim NOTIFIKASI BUKTI TRANSFER ke nomor admin,
 * persis seperti yang dilakukan bot saat pembeli mengirim bukti
 * (`beritahuBukti()` di `src/index.js`).
 *
 * Dipakai untuk membuktikan notifikasi benar-benar sampai ke DM admin —
 * jalur ini tidak bisa diuji dari dalam bot karena butuh pesan asli dari
 * pembeli.
 *
 * Jalankan (bot harus berhenti dulu supaya sesi tidak dipakai dua proses):
 *   node uji-bukti.mjs
 */
import { buatAdapterWhatsApp } from './src/adapters/whatsapp.js';
import { config } from './src/config.js';
import { buktiMasuk } from './src/core/replies.js';
import { patchWhatsappWeb } from './src/patch-lib.js';

console.log(`[uji] patch media: ${patchWhatsappWeb()}`);
console.log(`[uji] admin: ${config.adminNumbers.join(', ') || '(BOT_ADMIN_NUMBERS kosong)'}`);

if (config.adminNumbers.length === 0) {
    console.error('[uji] tidak ada nomor admin yang bisa diuji.');
    process.exit(1);
}

// Isi contoh — sama bentuknya dengan yang dikirim bot saat bukti masuk.
const pesan = buktiMasuk({
    nama: 'Uji Bot (abaikan)',
    nomor: '6282395124106',
    grup: 'DIGITALHUB OFFICIAL',
    transaksi: { invoice: 'INV-UJI', catatan: 'GPT PLUS SHARE 1 BULAN' },
});

const adapter = buatAdapterWhatsApp({
    async onReady() {
        let gagal = 0;

        for (const nomor of config.adminNumbers) {
            try {
                // `undefined` dari sendMessage BUKAN kegagalan (kuirk v1.34.7);
                // hanya throw yang dianggap gagal.
                await adapter.kirim(`${nomor}@c.us`, pesan);
                console.log(`[uji] notifikasi ke ${nomor}: TERKIRIM`);
            } catch (e) {
                gagal++;
                console.log(`[uji] notifikasi ke ${nomor}: GAGAL — ${e.message}`);
            }
        }

        await adapter.berhenti();
        process.exit(gagal === 0 ? 0 : 1);
    },
});

await adapter.mulai();
