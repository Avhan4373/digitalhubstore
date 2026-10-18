/**
 * Skrip uji sekali jalan: kirim pesan yang MENG-QUOTE pesan terakhir di satu
 * chat. Dipakai untuk memastikan fitur reply/quote benar-benar menempel di
 * whatsapp-web.js v1.34.7 (di mana `msg.id` tidak punya `_serialized`).
 *
 * Jalankan (bot harus berhenti dulu supaya sesi tidak dipakai dua proses):
 *   node uji-quote.mjs 1203xxxxxxxxx@g.us "teks uji"
 */
import pkg from 'whatsapp-web.js';

import { argumenChrome, cariChrome } from './src/adapters/whatsapp.js';
import { config } from './src/config.js';
import { patchWhatsappWeb } from './src/patch-lib.js';

const { Client, LocalAuth } = pkg;

const tujuan = process.argv[2];
const teks = process.argv[3] || '🧪 UJI QUOTE — balasan ini harus menempel di pesan Anda.';

if (!tujuan) {
    console.error('pakai: node uji-quote.mjs <chatId> [teks]');
    process.exit(1);
}

console.log(`[uji] patch media: ${patchWhatsappWeb()}`);

const client = new Client({
    authStrategy: new LocalAuth({ dataPath: config.sessionPath }),
    puppeteer: {
        headless: config.headless,
        executablePath: cariChrome(),
        args: argumenChrome(),
    },
});

client.on('ready', async () => {
    try {
        // Ambil chat + pesan terakhir dari store WhatsApp Web:
        // `chat.fetchMessages()` di v1.34.7 melempar error minified ("r").
        const bacaChat = (chatId) => client.pupPage.evaluate((id) => {
            const { Chat, Msg } = window.require('WAWebCollections');
            const chat = Chat.get(id);

            if (!chat) return { error: 'chat-tidak-ada' };

            const dariChat = chat.msgs && chat.msgs.getModelsArray
                ? chat.msgs.getModelsArray()
                : [];
            const semua = dariChat.length > 0
                ? dariChat
                : Msg.getModelsArray().filter((m) => m?.id?.remote === id);

            return {
                jumlah: semua.length,
                pesan: semua.slice(-4).map((m) => ({
                    fromMe: Boolean(m.id?.fromMe),
                    body: String(m.body || '').slice(0, 40),
                    kandidat: [m.id?.$1, m.id?._serialized].filter(Boolean),
                    quotedMsg: Boolean(m.quotedMsg),
                    quotedStanzaId: m.quotedStanzaId || null,
                })),
            };
        }, chatId);

        const awal = await bacaChat(tujuan);
        if (awal.error) throw new Error(awal.error);

        // Quote pesan PEMBELI (fromMe=false) seperti yang dilakukan bot.
        const milikPembeli = awal.pesan.filter((p) => !p.fromMe);
        const target = milikPembeli[milikPembeli.length - 1] || awal.pesan[awal.pesan.length - 1];
        const idQuote = target.kandidat.find((k) => k) || null;

        console.log(`[uji] ${awal.jumlah} pesan di store; quote ke: "${target.body}"`);
        console.log(`[uji] id quote: ${idQuote}`);

        const kirim = await client.sendMessage(tujuan, teks, { quotedMessageId: idQuote });
        console.log(`[uji] nilai balik sendMessage: ${kirim === undefined ? 'undefined (bug v1.34.7)' : 'objek pesan'}`);

        // Kebenaran quote dibaca dari pesan yang tersimpan di WhatsApp.
        const akhir = await bacaChat(tujuan);
        const terkirim = akhir.pesan[akhir.pesan.length - 1];

        console.log(`[uji] pesan terakhir setelah kirim: "${terkirim.body}" (fromMe=${terkirim.fromMe})`);
        console.log(`[uji] quote=${terkirim.quotedMsg ? 'MENEMPEL' : 'TIDAK menempel'} | quotedStanzaId=${terkirim.quotedStanzaId || '-'}`);
    } catch (e) {
        console.error(`[uji] gagal: ${e.message}`);
    } finally {
        await client.destroy();
        process.exit(0);
    }
});

client.initialize();
