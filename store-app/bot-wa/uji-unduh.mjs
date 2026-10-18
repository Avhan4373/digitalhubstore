/**
 * Skrip diagnosis/verifikasi sekali jalan untuk terusan gambar bukti transfer.
 *
 * Bot harus berhenti dulu (sesi dipakai satu proses). Yang diuji:
 *   1. gambar terakhir di grup allowlist (kunci store-nya),
 *   2. `unduhMediaPesan()` — FUNGSI PRODUKSI yang sama dengan yang dipakai
 *      `mediaBukti()` di adapter,
 *   3. pengiriman hasilnya ke DM admin (persis seperti beritahuBukti()).
 *
 * Jalankan: node uji-unduh.mjs
 */
import { buatAdapterWhatsApp, unduhMediaPesan } from './src/adapters/whatsapp.js';
import { config } from './src/config.js';
import { buktiMasuk } from './src/core/replies.js';

const adapter = buatAdapterWhatsApp({
    async onReady() {
        const client = adapter.client;

        try {
            const grup = config.groupAllowlist[0];
            const kunci = grup
                ? await client.pupPage.evaluate((chatId) => {
                    const chat = window.require('WAWebCollections').Chat.get(chatId);
                    const media = (chat?.msgs?.getModelsArray?.() || [])
                        .filter((m) => m.mediaData && m.directPath && String(m.type).startsWith('image'))
                        .pop();

                    return media ? media.id?.$1 || null : null;
                }, grup)
                : null;

            console.log(`[uji] gambar terakhir di ${grup}: ${kunci || '(tidak ada)'}`);

            const media = await unduhMediaPesan(client, kunci);
            console.log(
                `[uji] unduhMediaPesan -> ${media ? `OK ${media.mimetype} ${Math.round(media.data.length / 1024)} KB` : 'null (gagal/bukan gambar)'}`,
            );

            if (media) {
                const pesan = buktiMasuk({
                    nama: 'Uji Bot (abaikan)',
                    nomor: '6282395124106',
                    grup: 'DIGITALHUB OFFICIAL',
                    transaksi: { invoice: 'INV-UJI', catatan: 'GPT PLUS SHARE 1 BULAN' },
                });

                for (const nomorAdmin of config.adminNumbers) {
                    await adapter.kirimMedia(`${nomorAdmin}@c.us`, media, pesan);
                    console.log(`[uji] kirim ke admin ${nomorAdmin}: TERKIRIM`);
                }
            }
        } catch (e) {
            console.log(`[uji] gagal: ${e.message}`);
        }

        await adapter.berhenti();
        process.exit(0);
    },
});

await adapter.mulai();
