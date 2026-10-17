/**
 * Simulator percakapan tanpa WhatsApp.
 *
 * Menjalankan engine yang sama dengan bot sungguhan, tapi lewat terminal.
 * Berguna untuk mencoba alur katalog/pesanan tanpa scan QR, dan untuk
 * memastikan API Laravel + data katalog sudah benar.
 *
 * Pakai:
 *   npm run simulate                     # interaktif
 *   NOMOR=628111111111 npm run simulate  # nomor lain
 *   printf 'list\n1\n' | npm run simulate  # mode pipa (skrip/uji cepat)
 *
 * Perintah saat berjalan: /reset (bersihkan sesi), /quit
 */
import readline from 'node:readline';

import { assertConfig, config } from '../src/config.js';
import { handlePesan, resetRateLimit } from '../src/core/engine.js';
import { pecahPesan } from '../src/core/format.js';
import { clearState } from '../src/core/session.js';

assertConfig();

const nomor = (process.env.NOMOR || '6282395124106').replace(/\D/g, '');
const chatId = `${nomor}@c.us`;

function cetakBalasan(hasil) {
    for (const balasan of hasil.replies || []) {
        console.log('');
        for (const potongan of pecahPesan(balasan)) {
            console.log('Bot  > ' + potongan.split('\n').join('\n       '));
        }
    }

    if (hasil.order) {
        console.log('');
        console.log(
            `(pesanan ${hasil.order.invoice} dibuat · total Rp${Number(hasil.order.total).toLocaleString('id-ID')})`,
        );

        if (hasil.payment?.qris_url) {
            console.log(`(QRIS: ${hasil.payment.qris_url})`);
        } else {
            console.log('(QRIS belum diunggah admin — pembeli diarahkan ke wa.me)');
        }
    }

    // QRIS dikirim ulang saat pembeli mengecek pesanan yang belum dibayar.
    if (hasil.qris && !hasil.order) {
        console.log(`(QRIS dikirim ulang: ${hasil.qris})`);
    }

    console.log('');
}

/** Proses satu baris masukan; mengembalikan false kalau harus berhenti. */
async function proses(teks) {
    const perintah = teks.trim();

    if (perintah === '/quit' || perintah === '/exit') return false;

    if (perintah === '/reset') {
        clearState(chatId);
        resetRateLimit(nomor);
        console.log('(sesi dibersihkan)\n');
        return true;
    }

    if (perintah === '') return true;

    try {
        const hasil = await handlePesan({ teks: perintah, nomor, chatId });
        cetakBalasan(hasil);
    } catch (e) {
        console.log('');
        console.log(`⚠️  ${e.message}`);
        console.log('');
    }

    return true;
}

function banner() {
    console.log('');
    console.log('════════════════════════════════════════════');
    console.log('  Simulator Bot WhatsApp — Creative Solutions');
    console.log(`  API   : ${config.apiUrl}`);
    console.log(`  Nomor : ${nomor}`);
    console.log('  /reset untuk bersihkan sesi · /quit keluar');
    console.log('════════════════════════════════════════════');
    console.log('');
}

banner();

// Mode pipa: stdin bukan terminal, jadi tidak ada prompt. Baca semua baris
// secara berurutan supaya hasilnya tidak terpotong saat stdin ditutup.
if (!process.stdin.isTTY) {
    const baris = [];

    const rl = readline.createInterface({ input: process.stdin, terminal: false });

    rl.on('line', (l) => baris.push(l));

    rl.on('close', async () => {
        for (const l of baris) {
            console.log(`Anda > ${l}`);
            const lanjut = await proses(l);
            if (!lanjut) break;
        }

        console.log('Sampai jumpa.\n');
    });
} else {
    const rl = readline.createInterface({
        input: process.stdin,
        output: process.stdout,
        prompt: 'Anda > ',
    });

    rl.prompt();

    rl.on('line', async (l) => {
        const lanjut = await proses(l);
        if (!lanjut) {
            rl.close();
            return;
        }
        rl.prompt();
    });

    rl.on('close', () => {
        console.log('\nSampai jumpa.\n');
        process.exit(0);
    });
}
