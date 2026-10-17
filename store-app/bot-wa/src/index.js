/**
 * Titik masuk bot WhatsApp Creative Solutions.
 *
 * Tugas file ini hanya menyambungkan tiga bagian:
 *   1. adapter WhatsApp (Chrome + whatsapp-web.js)
 *   2. engine percakapan (murni, sudah diuji tanpa Chrome)
 *   3. pengiriman hasil (teks + gambar QRIS + notifikasi admin)
 *
 * Plus: poller yang memberi tahu pembeli saat pesanan sudah selesai
 * (setelah admin memverifikasi pembayaran di panel).
 *
 * Jalankan: node src/index.js
 */
import qrcode from 'qrcode-terminal';

import { buatAdapterWhatsApp } from './adapters/whatsapp.js';
import { assertConfig, config } from './config.js';
import { api } from './core/api.js';
import { handlePesan, nomorTerdaftar } from './core/engine.js';
import { pecahPesan } from './core/format.js';
import { log, maskNumber } from './core/logger.js';
import { sweepSessions } from './core/session.js';
import * as R from './core/replies.js';

assertConfig();

let adapter = null;

/** Invoice yang dibuat bot per chat, untuk poller. @type {Map<string, Set<string>>} */
const pesananBot = new Map();

function catatPesanan(chatId, invoice) {
    if (!invoice) return;

    const set = pesananBot.get(chatId) || new Set();
    set.add(invoice);
    pesananBot.set(chatId, set);
}

/**
 * Kirim balasan engine ke pembeli.
 *
 * Teks dipecah supaya katalog panjang tidak menjadi satu pesan raksasa, dan
 * gambar QRIS dikirim terpisah setelah ringkasan pesanan.
 */
async function kirimHasil(sendTo, hasil) {
    for (const teks of hasil.replies || []) {
        for (const potongan of pecahPesan(teks)) {
            await adapter.kirim(sendTo, potongan);
        }
    }

    // QRIS hanya dikirim kalau pesanan baru benar-benar dibuat.
    if (hasil.order && hasil.payment?.qris_url) {
        await adapter.kirimMedia(
            sendTo,
            hasil.payment.qris_url,
            `💳 QRIS — ${hasil.order.invoice}\nTotal: Rp${Number(hasil.order.total).toLocaleString('id-ID')}`,
        );
    }

    // Pembeli cek status pesanan yang belum dibayar: kirim ulang QRIS-nya,
    // karena gambar sebelumnya sering hilang di riwayat chat.
    if (hasil.qris && !hasil.order) {
        await adapter.kirimMedia(sendTo, hasil.qris, `💳 QRIS — ${hasil.invoice}`);
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

async function tanganiPesan({ teks, chatId, nomor, sendTo, nama, hasMedia }) {
    // Allowlist: kalau diisi, hanya nomor itu yang dilayani. Berguna saat
    // mencoba bot di produksi tanpa mengganggu pembeli sungguhan.
    if (config.allowlist.length > 0 && !config.allowlist.includes(nomor)) {
        log.debug(`Abaikan ${maskNumber(nomor)} (di luar allowlist).`);
        return;
    }

    try {
        const hasil = await handlePesan({
            teks,
            nomor,
            chatId,
            nama,
            hasMedia,
            batasRate: config.rateLimitPerMinute,
        });

        const tujuan = sendTo || chatId;

        if (hasil.order) catatPesanan(chatId, hasil.order.invoice);

        await kirimHasil(tujuan, hasil);
    } catch (e) {
        // Jangan biarkan satu pesan yang gagal mematikan proses bot atau
        // meninggalkan pembeli tanpa balasan.
        log.error(`Gagal memproses pesan dari ${maskNumber(nomor)}: ${e?.message || e}`);

        try {
            await adapter.kirim(
                sendTo || chatId,
                ['⚠️ Maaf, terjadi gangguan sementara.', '', 'Coba kirim ulang pesan Anda, atau hubungi admin.'].join('\n'),
            );
        } catch (sendErr) {
            log.error(`Gagal mengirim pesan gangguan: ${sendErr?.message || sendErr}`);
        }
    }
}

/**
 * Periksa pesanan yang dibuat bot; kalau sudah selesai, kirim isi akun.
 *
 * Admin memverifikasi pembayaran di panel. Begitu pesanan berstatus terkirim,
 * pembeli langsung diberi tahu lewat WhatsApp — tanpa perlu bertanya.
 */
async function pollSelesai() {
    if (!adapter) return;

    for (const { chatId, nomor } of nomorTerdaftar()) {
        const invoices = pesananBot.get(chatId);

        if (!invoices || invoices.size === 0) continue;

        for (const invoice of [...invoices]) {
            let detail;

            try {
                detail = await api.orderStatus(invoice, nomor);
            } catch {
                // API bermasalah atau pesanan belum dikenal nomor ini.
                continue;
            }

            const order = detail.order;

            if (!order) continue;

            if (order.is_delivered || order.order_status === 'completed') {
                invoices.delete(invoice);

                if (detail.contents?.length > 0) {
                    try {
                        await adapter.kirim(chatId, R.pesananSelesai(order, detail.contents));
                    } catch (e) {
                        log.warn(`Gagal mengirim isi akun ${invoice}: ${e.message}`);
                    }
                }
            }
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
            // Mulai memantau pesanan setelah koneksi siap.
            setInterval(pollSelesai, 60_000).unref();
        },

        onDisconnected(alasan) {
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
    log.error(`Promise ditolak tanpa penanganan: ${e?.message || e}`);
});

process.on('uncaughtException', (e) => {
    log.error(`Exception tak tertangani: ${e?.message || e}`);
});

log.info(`Bot Creative Solutions — API ${config.apiUrl}`);
mulaiAdapter();

// Diekspor untuk pengujian/manual.
export { kirimHasil, tanganiPesan, pollSelesai };
