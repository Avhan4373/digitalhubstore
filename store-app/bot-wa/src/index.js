/**
 * Titik masuk bot WhatsApp Creative Solutions.
 *
 * Tugas file ini hanya menyambungkan tiga bagian:
 *   1. adapter WhatsApp (Chrome + whatsapp-web.js)
 *   2. engine percakapan (murni, sudah diuji tanpa Chrome)
 *   3. pengiriman hasil (teks + gambar QRIS + notifikasi admin)
 *
 * Jalankan: node src/index.js
 */
import qrcode from 'qrcode-terminal';

import { buatAdapterWhatsApp } from './adapters/whatsapp.js';
import { assertConfig, config } from './config.js';
import { handlePesan, resetRateLimit } from './core/engine.js';
import { pecahPesan } from './core/format.js';
import { log, maskNumber } from './core/logger.js';
import { sweepSessions } from './core/session.js';

assertConfig();

let adapter = null;

/**
 * Kirim balasan engine ke pembeli.
 *
 * Teks dipecah supaya katalog panjang tidak menjadi satu pesan raksasa, dan
 * gambar QRIS dikirim terpisah setelah ringkasan pesanan.
 */
async function kirimHasil(chatId, hasil) {
    for (const teks of hasil.replies || []) {
        for (const potongan of pecahPesan(teks)) {
            await adapter.kirim(chatId, potongan);
        }
    }

    // QRIS hanya dikirim kalau pesanan baru benar-benar dibuat.
    if (hasil.order && hasil.payment?.qris_url) {
        await adapter.kirimMedia(
            chatId,
            hasil.payment.qris_url,
            `💳 QRIS — ${hasil.order.invoice}\nTotal: Rp${Number(hasil.order.total).toLocaleString('id-ID')}`,
        );
    }

    // Pembeli cek status pesanan yang belum dibayar: kirim ulang QRIS-nya,
    // karena gambar sebelumnya sering hilang di riwayat chat.
    if (hasil.qris && !hasil.order) {
        await adapter.kirimMedia(chatId, hasil.qris, `💳 QRIS — ${hasil.invoice}`);
    }

    if (hasil.order) {
        await beritahuAdmin(hasil.order);
    }
}

/** Beri tahu admin bahwa ada pesanan baru dari bot. */
async function beritahuAdmin(order) {
    if (config.adminNumbers.length === 0) return;

    const pesan = [
        '🔔 *Pesanan baru (WhatsApp)*',
        '',
        `🧾 ${order.invoice}`,
        `👤 ${order.buyer_name}`,
        `💰 Rp${Number(order.total).toLocaleString('id-ID')}`,
        '',
        ...(order.items || []).map((i) => `• ${i.product_name} — ${i.variant_name} (${i.qty}x)`),
        '',
        'Buka panel admin untuk verifikasi pembayaran.',
    ].join('\n');

    for (const nomor of config.adminNumbers) {
        try {
            await adapter.kirim(`${nomor}@c.us`, pesan);
        } catch (e) {
            log.warn(`Notifikasi admin ke ${maskNumber(nomor)} gagal: ${e.message}`);
        }
    }
}

async function tanganiPesan({ teks, chatId, nomor }) {
    // Allowlist: kalau diisi, hanya nomor itu yang dilayani. Berguna saat
    // mencoba bot di produksi tanpa mengganggu pembeli sungguhan.
    if (config.allowlist.length > 0 && !config.allowlist.includes(nomor)) {
        log.debug(`Abaikan ${maskNumber(nomor)} (di luar allowlist).`);
        return;
    }

    log.info(`Pesan masuk dari ${maskNumber(nomor)}: ${String(teks || '').slice(0, 60)}`);

    try {
        const hasil = await handlePesan({
            teks,
            nomor,
            chatId,
            batasRate: config.rateLimitPerMinute,
        });

        await kirimHasil(chatId, hasil);
    } catch (e) {
        // Jangan biarkan satu pesan yang gagal mematikan proses bot atau
        // meninggalkan pembeli tanpa balasan.
        log.error(`Gagal memproses pesan dari ${maskNumber(nomor)}: ${e?.message || e}`);

        try {
            await adapter.kirim(
                chatId,
                [
                    '⚠️ Maaf, terjadi gangguan sementara.',
                    '',
                    'Coba kirim ulang pesan Anda, atau hubungi admin.',
                ].join('\\n'),
            );
        } catch (sendErr) {
            log.error(`Gagal mengirim pesan gangguan: ${sendErr?.message || sendErr}`);
        }
    }
}

function mulaiAdapter() {
    adapter = buatAdapterWhatsApp({
        onMessage: tanganiPesan,

        onQr(qr) {
            log.info('Scan QR berikut dengan WhatsApp > Perangkat Tertaut.');
            qrcode.generate(qr, { small: true });
        },

        onReady() {
            log.info(`Bot aktif. Admin: ${config.adminNumbers.map(maskNumber).join(', ') || '(belum diisi)'}`);
        },

        onDisconnected(alasan) {
            // Keluar dengan kode error supaya supervisor (systemd/start.sh)
            // menjalankan ulang proses. Sesi LocalAuth tetap tersimpan.
            log.error(`Bot berhenti: ${alasan}. Restart dalam 5 detik…`);
            setTimeout(() => process.exit(1), 5000);
        },
    });

    adapter.mulai().catch((e) => {
        log.error(`Gagal memulai WhatsApp: ${e.message}`);
        process.exit(1);
    });
}

// Bersihkan sesi percakapan kedaluwarsa tiap 10 menit.
setInterval(() => {
    const jumlah = sweepSessions();
    if (jumlah > 0) log.debug(`${jumlah} sesi percakapan dibersihkan.`);
}, 10 * 60 * 1000).unref();

async function matikan(sinyal) {
    log.info(`Menerima ${sinyal}, menutup koneksi…`);

    if (adapter) await adapter.berhenti();

    process.exit(0);
}

process.on('SIGINT', () => matikan('SIGINT'));
process.on('SIGTERM', () => matikan('SIGTERM'));

process.on('unhandledRejection', (e) => {
    log.error(`Promise ditolak tanpa penangan: ${e?.message || e}`);
});

process.on('uncaughtException', (e) => {
    log.error(`Exception tak tertangani: ${e?.message || e}`);
});

log.info(`Bot Creative Solutions — API ${config.apiUrl}`);
mulaiAdapter();

// Diekspor untuk pengujian/manual.
export { kirimHasil, tanganiPesan, resetRateLimit };
