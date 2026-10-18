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
import { catatanPesanan, pecahPesan } from './core/format.js';
import { log, maskNumber } from './core/logger.js';
import { sweepSessions } from './core/session.js';
import * as R from './core/replies.js';
import { patchWhatsappWeb } from './patch-lib.js';

assertConfig();

// Kiriman media (QRIS!) rusak di whatsapp-web.js v1.34.7 tanpa patch ini.
// Dipanggil sebelum client dibuat; idempoten dan aman diulang tiap start.
const statusPatch = patchWhatsappWeb();

if (statusPatch === 'dipasang') {
    log.info('Patch pengiriman media whatsapp-web.js dipasang (bug #201921).');
} else if (statusPatch !== 'sudah-ada') {
    log.warn(`Patch media whatsapp-web.js TIDAK terpasang (${statusPatch}) — kiriman gambar bisa gagal.`);
}

let adapter = null;

/** Invoice yang dibuat bot per chat, untuk poller. @type {Map<string, Set<string>>} */
const pesananBot = new Map();

/**
 * Ringkasan pesanan terakhir per chat: dipakai balasan admin `proses`/`done`
 * (catatan + nama pembeli). Bukan sumber kebenaran — pesanan aslinya di DB.
 * @type {Map<string, {invoice: string, pembeli: string, catatan: string}>}
 */
const transaksiTerakhir = new Map();

function catatPesanan(chatId, order) {
    if (!order?.invoice) return;

    const set = pesananBot.get(chatId) || new Set();
    set.add(order.invoice);
    pesananBot.set(chatId, set);

    transaksiTerakhir.set(chatId, {
        invoice: order.invoice,
        pembeli: String(order.buyer_name || '').trim(),
        catatan: catatanPesanan(order),
    });
}

/**
 * Kirim balasan engine ke pembeli.
 *
 * Teks dipecah supaya katalog panjang tidak menjadi satu pesan raksasa, dan
 * gambar QRIS dikirim terpisah setelah ringkasan pesanan. Potongan PERTAMA
 * dikirim sebagai reply/quote ke pesan pembeli (jika ID-nya tersedia) supaya
 * di grup terlihat jelas pertanyaan mana yang diberi jawaban.
 */
async function kirimHasil(chatId, hasil, balasId = null) {
    let pesanPertama = true;

    // Gambar+teks jadi SATU pesan (perintah `pay`): caption gambar langsung
    // berisi info pembayarannya, dan pesan ini juga meng-quote pesan pembeli.
    if (hasil.qris && hasil.qrisDulu) {
        await adapter.kirimMedia(chatId, hasil.qris, hasil.qrisCaption || '💳 QRIS RESMI', {
            balasId: pesanPertama ? balasId : null,
        });
        pesanPertama = false;
    }

    for (const teks of hasil.replies || []) {
        for (const potongan of pecahPesan(teks)) {
            const opsi = pesanPertama && balasId ? { balasId } : {};
            await adapter.kirim(chatId, potongan, opsi);
            pesanPertama = false;
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
    if (hasil.qris && !hasil.order && !hasil.qrisDulu) {
        await adapter.kirimMedia(chatId, hasil.qris, `💳 QRIS — ${hasil.invoice}`);
    }

    if (hasil.order) {
        await beritahuAdmin(hasil.order);
    }
}

/**
 * Kabari admin bahwa ada bukti transfer masuk.
 *
 * Bot DIAM ke pembeli (lihat `hasil.bukti`): verifikasi manual oleh admin, dan
 * pembeli baru dikabari lewat perintah admin `proses`/`done`. Notifikasi ini
 * dikirim ke chat PRIBADI tiap nomor di `BOT_ADMIN_NUMBERS` — **beserta gambar
 * buktinya** kalau pembeli mengirim gambar (satu pesan: gambar + caption).
 */
async function beritahuBukti({ nomor, nama, grup, transaksi, gambar = null }) {
    if (config.adminNumbers.length === 0) return;

    const pesan = R.buktiMasuk({ nama, nomor, grup, transaksi });

    for (const nomorAdmin of config.adminNumbers) {
        const tujuan = `${nomorAdmin}@c.us`;

        if (gambar) {
            try {
                await adapter.kirimMedia(tujuan, gambar, pesan);
                continue;
            } catch (e) {
                log.warn(
                    `Bukti bergambar ke admin ${maskNumber(nomorAdmin)} gagal (${e.message}) `
                    + '— dikirim sebagai teks saja.',
                );
            }
        }

        try {
            await adapter.kirim(tujuan, pesan);
        } catch (e) {
            log.warn(`Notifikasi bukti ke admin ${maskNumber(nomorAdmin)} gagal: ${e.message}`);
        }
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

async function tanganiPesan({ teks, chatId, nomor, sendTo, nama, hasMedia, pesanId, grup = '', buktiGambar = null }) {
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
            grup,
            transaksiTerakhir: transaksiTerakhir.get(chatId) || null,
            batasRate: config.rateLimitPerMinute,
        });

        if (hasil.order) catatPesanan(chatId, hasil.order);

        // Bukti transfer: bot sengaja DIAM ke pembeli. Verifikasi manual oleh
        // admin — jadi yang dikabari justru ADMIN (chat pribadi), dan pembeli
        // baru mendengar kabar lewat perintah admin `proses`/`done`.
        if (hasil.bukti) {
            log.info(
                `Bukti transfer dari ${maskNumber(nomor)} (${buktiGambar ? 'bergambar' : 'teks'}) `
                + '— bot tidak membalas, notifikasi dikirim ke admin.',
            );

            await beritahuBukti({
                nomor,
                nama,
                grup,
                transaksi: transaksiTerakhir.get(chatId) || null,
                gambar: buktiGambar,
            });
        } else if (hasil.buktiDitolak) {
            // Penting untuk diagnosis: tanpa log ini, bukti yang ditolak tidak
            // meninggalkan jejak apa pun (bot hanya membalas pembeli).
            log.warn(
                `Bukti transfer dari ${maskNumber(nomor)} (${buktiGambar ? 'bergambar' : 'teks'}) `
                + '— DITOLAK: nomor ini belum punya pesanan di server. '
                + 'Pembeli diberi arahan memilih produk; admin tidak dikabari.',
            );
        }

        // Kirim ke CHAT ASAL (adapter yang menyelesaikan tujuan per chat) dan
        // bawa ID pesan pemicu supaya potongan pertama jadi quote/reply.
        await kirimHasil(chatId, hasil, pesanId);
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
