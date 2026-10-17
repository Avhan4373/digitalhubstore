/**
 * Uji alur percakapan end-to-end dengan API tiruan.
 *
 * Alur sederhana yang diuji:
 *   list -> nomor produk -> nomor paket -> pesanan dibuat + QRIS
 *   kirim bukti -> dikonfirmasi -> admin verifikasi -> isi akun dikirim
 *
 * Tidak butuh Chrome: `fetch` diganti stub.
 */
import assert from 'node:assert/strict';
import { afterEach, beforeEach, test } from 'node:test';

process.env.BOT_API_TOKEN = 'token-uji';
process.env.BOT_API_URL = 'http://toko.test';

const KATALOG = {
    store: { name: 'Creative Solutions', whatsapp: '6282395124106', tagline: 'Akun premium resmi' },
    categories: [
        {
            id: 1,
            name: 'AI',
            slug: 'ai',
            products: [
                { id: 2, slug: 'gemini-pro', name: 'Gemini Pro', category: 'AI', price_min: 5000, price_max: 35000, sold_count: 0, warranty: 'Garansi Login' },
                { id: 3, slug: 'canva-pro', name: 'Canva Pro', category: 'AI', price_min: 8000, price_max: 60000, sold_count: 0, warranty: 'Resmi 1 Bulan' },
            ],
        },
        {
            id: 2,
            name: 'Stream',
            slug: 'stream',
            products: [
                { id: 4, slug: 'netflix', name: 'Netflix', category: 'Stream', price_min: 11000, price_max: 11000, sold_count: 0, warranty: '' },
            ],
        },
    ],
};

const DETAIL = {
    product: { id: 2, slug: 'gemini-pro', name: 'Gemini Pro', description: 'Akses Gemini Pro penuh', warranty: 'Garansi Login', price_min: 5000, price_max: 35000, sold_count: 0 },
    variants: [
        { id: 10, name: 'Sharing 1 Bulan', price: 5000, original_price: null, stock: 5, purchasable: true, delivery: 'Otomatis', required_fields: [] },
        { id: 11, name: 'Private 1 Bulan', price: 35000, original_price: 40000, stock: 2, purchasable: true, delivery: 'Otomatis', required_fields: [] },
        { id: 12, name: 'Private 1 Tahun', price: 200000, original_price: null, stock: 0, purchasable: false, delivery: 'Otomatis', required_fields: [] },
    ],
};

const PESANAN = {
    invoice: 'INV261017ABCDE',
    buyer_name: 'Budi',
    total: 5000,
    payment_status: 'pending',
    payment_label: 'Menunggu pembayaran',
    order_status: 'new',
    status_label: 'Baru',
    is_paid: false,
    is_delivered: false,
    can_pay: true,
    expires_at: new Date(Date.now() + 3600_000).toISOString(),
    items: [{ product_name: 'Gemini Pro', variant_name: 'Sharing 1 Bulan', qty: 1, subtotal: 5000, is_fulfilled: false }],
};

let panggilan = [];
let asliFetch;

function stubFetch(handler) {
    globalThis.fetch = async (url, opts) => {
        const path = new URL(url).pathname;
        panggilan.push({ path, method: opts?.method || 'GET', body: opts?.body ? JSON.parse(opts.body) : null });

        const hasil = handler(path, opts);

        if (!hasil) {
            return { ok: false, status: 404, text: async () => JSON.stringify({ message: 'tidak ada' }) };
        }

        return { ok: true, status: 200, text: async () => JSON.stringify(hasil) };
    };
}

function stubDefault() {
    stubFetch((path) => {
        if (path === '/api/bot/katalog') return KATALOG;
        if (path === '/api/bot/produk/gemini-pro') return DETAIL;
        if (path === '/api/bot/produk/canva-pro') return { ...DETAIL, product: { ...DETAIL.product, slug: 'canva-pro', name: 'Canva Pro' } };
        if (path === '/api/bot/produk/netflix') return { ...DETAIL, product: { ...DETAIL.product, slug: 'netflix', name: 'Netflix' } };
        if (path === '/api/bot/pesanan') return { order: PESANAN, payment: { method: 'qris_manual', qris_url: 'https://toko.test/qris.jpg', holder: 'Creative Solutions', expires_at: null } };
        if (path === '/api/bot/pesanan/status') return { order: PESANAN, contents: [] };
        if (path === '/api/bot/pesanan/riwayat') return { orders: [PESANAN] };
        return null;
    });
}

let engine;

beforeEach(async () => {
    asliFetch = globalThis.fetch;
    panggilan = [];
    stubDefault();

    engine = await import('../src/core/engine.js');

    const { clearState } = await import('../src/core/session.js');
    clearState('chat-uji');
    engine.resetRateLimit('6282395124106');
});

afterEach(() => {
    globalThis.fetch = asliFetch;
});

async function kirim(teks, extra = {}) {
    return engine.handlePesan({ teks, nomor: '6282395124106', chatId: 'chat-uji', ...extra });
}

// ------------------------------------------------------------------ katalog

test('list menampilkan katalog dengan nomor urut', async () => {
    const { replies } = await kirim('list');

    assert.equal(replies.length, 1);
    const teks = replies[0];

    assert.match(teks, /Creative Solutions/);
    assert.match(teks, /\*AI\*/);
    assert.match(teks, /1\. Gemini Pro/);
    assert.match(teks, /2\. Canva Pro/);
    assert.match(teks, /3\. Netflix/);
    assert.match(teks, /Rp5\.000 – Rp35\.000/);
});

test('angka setelah list membuka detail produk yang benar', async () => {
    await kirim('list');
    const { replies } = await kirim('2');

    assert.match(replies[0], /Canva Pro/);

    const produkCall = panggilan.filter((p) => p.path.startsWith('/api/bot/produk/'));
    assert.equal(produkCall.at(-1).path, '/api/bot/produk/canva-pro', 'nomor 2 harus menunjuk Canva Pro');
});

test('detail menampilkan semua varian + harga + stok', async () => {
    await kirim('list');
    const { replies } = await kirim('1');
    const teks = replies[0];

    assert.match(teks, /Gemini Pro/);
    assert.match(teks, /1\. Sharing 1 Bulan — Rp5\.000/);
    assert.match(teks, /2\. Private 1 Bulan — ~Rp40\.000~ Rp35\.000/);
    assert.match(teks, /stok 5/);
    assert.match(teks, /3\. Private 1 Tahun.*❌/);
});

// ---------------------------------------------------------------- alur pesan

test('pilih paket langsung buat pesanan + QRIS (tanpa tanya nama)', async () => {
    await kirim('list');
    await kirim('1');

    const selesai = await kirim('1');

    assert.match(selesai.replies[0], /Pesanan dibuat/);
    assert.match(selesai.replies[0], /INV261017ABCDE/);
    assert.match(selesai.replies[0], /Rp5\.000/);
    assert.match(selesai.replies[0], /QRIS/);
    assert.ok(selesai.order, 'pesanan harus dikembalikan untuk pengiriman QRIS');
    assert.equal(selesai.payment.qris_url, 'https://toko.test/qris.jpg');

    const orderCall = panggilan.find((p) => p.path === '/api/bot/pesanan' && p.method === 'POST');
    assert.ok(orderCall, 'harus memanggil API pesanan');
    assert.equal(orderCall.body.variant_id, 10);
    assert.equal(orderCall.body.whatsapp, '6282395124106');
    // Nama otomatis dari WhatsApp, bukan ditanya.
    assert.equal(orderCall.body.name, 'Pembeli');
});

test('nama tampilan WhatsApp dipakai untuk invoice', async () => {
    await kirim('list');
    await kirim('1');

    await kirim('1', { nama: 'Budi Santoso' });

    const orderCall = panggilan.find((p) => p.path === '/api/bot/pesanan' && p.method === 'POST');
    assert.equal(orderCall.body.name, 'Budi Santoso');
});

test('batal di tengah alur menghentikan pesanan', async () => {
    await kirim('list');
    await kirim('1');

    const batal = await kirim('batal');
    assert.match(batal.replies[0], /Dibatalkan/);

    const { replies } = await kirim('1');
    assert.ok(!/Pesanan dibuat/.test(replies[0]));
    assert.ok(!panggilan.some((p) => p.path === '/api/bot/pesanan'));
});

test('varian habis ditolak dengan pesan jelas', async () => {
    await kirim('list');
    await kirim('1');
    const { replies } = await kirim('3');

    assert.match(replies[0], /tidak tersedia/);
    assert.ok(!panggilan.some((p) => p.path === '/api/bot/pesanan'));
});

test('nomor varian di luar jangkauan ditolak', async () => {
    await kirim('list');
    await kirim('1');
    const { replies } = await kirim('99');

    assert.match(replies[0], /Nomor paket tidak ada/);
});

test('nomor produk di luar jangkauan ditolak', async () => {
    const { replies } = await kirim('99');
    assert.match(replies[0], /Nomor tidak ada/);
});

test('list bisa dipakai lagi dari layar pilih paket', async () => {
    await kirim('list');
    await kirim('1');

    const katalogLagi = await kirim('list');
    assert.match(katalogLagi.replies[0], /Creative Solutions/);

    // State keluar: angka berikutnya membuka produk lagi.
    const detailLagi = await kirim('1');
    assert.match(detailLagi.replies[0], /Pilih paket/);
});

// ------------------------------------------------------------------- pencarian

test('pencarian satu hasil langsung membuka detail', async () => {
    const { replies } = await kirim('netflix');
    assert.match(replies[0], /Netflix/);
});

test('pencarian banyak hasil menampilkan pilihan', async () => {
    const { replies } = await kirim('pro');
    assert.match(replies[0], /Hasil pencarian/);
    assert.match(replies[0], /Gemini Pro/);
    assert.match(replies[0], /Canva Pro/);
});

test('pencarian tanpa hasil memberi saran', async () => {
    const { replies } = await kirim('zzzz');
    assert.match(replies[0], /tidak ditemukan/);
});

// ---------------------------------------------------------------- bukti transfer

test('kirim bukti transfer (gambar) dikonfirmasi', async () => {
    await kirim('list');
    await kirim('1');
    await kirim('1');

    const { replies } = await kirim('', { hasMedia: true });

    assert.match(replies[0], /Bukti transfer diterima/);
    assert.ok(panggilan.some((p) => p.path === '/api/bot/pesanan/riwayat'));
});

test('kirim bukti transfer (teks) dikonfirmasi', async () => {
    await kirim('list');
    await kirim('1');
    await kirim('1');

    const { replies } = await kirim('sudah transfer');

    assert.match(replies[0], /Bukti transfer diterima/);
});

// ------------------------------------------------------------------ pesanan

test('invoice memanggil status pesanan', async () => {
    const { replies } = await kirim('INV261017ABCDE');

    assert.match(replies[0], /INV261017ABCDE/);
    assert.match(replies[0], /Menunggu pembayaran/);

    const call = panggilan.find((p) => p.path === '/api/bot/pesanan/status');
    assert.equal(call.body.invoice, 'INV261017ABCDE');
    assert.equal(call.body.whatsapp, '6282395124106');
});

test('riwayat memanggil endpoint riwayat', async () => {
    const { replies } = await kirim('riwayat');
    assert.match(replies[0], /Riwayat pesanan/);
    assert.ok(panggilan.some((p) => p.path === '/api/bot/pesanan/riwayat'));
});

test('status tanpa invoice meminta nomor invoice', async () => {
    const { replies } = await kirim('status');
    assert.match(replies[0], /Kirim nomor invoice/);
});

// ------------------------------------------------------------------ lain-lain

test('bantuan menampilkan cara pesan', async () => {
    const { replies } = await kirim('bantuan');
    assert.match(replies[0], /\*list\*/);
    assert.match(replies[0], /wa\.me\/6282395124106/);
});

test('sapaan menampilkan sambutan', async () => {
    const { replies } = await kirim('halo');
    assert.match(replies[0], /Selamat datang/);
});

test('teks tak dikenal mencari produk lalu memberi saran', async () => {
    const { replies } = await kirim('asdfghjkl');
    assert.match(replies[0], /tidak ditemukan/);
});

test('rate limit menahan pesan berlebihan', async () => {
    for (let i = 0; i < 5; i++) {
        await engine.handlePesan({ teks: 'bantuan', nomor: '628111111111', chatId: 'chat-rate', batasRate: 3 });
    }

    const { replies } = await engine.handlePesan({ teks: 'bantuan', nomor: '628111111111', chatId: 'chat-rate', batasRate: 3 });
    assert.match(replies[0], /Terlalu banyak pesan/);
});

// ------------------------------------------------------------- data wajib

test('data wajib diminta dan dikirim sesuai urutan label', async () => {
    stubFetch((path) => {
        if (path === '/api/bot/katalog') return KATALOG;
        if (path === '/api/bot/produk/gemini-pro') {
            return {
                ...DETAIL,
                variants: [
                    { id: 20, name: 'Private', price: 50000, original_price: null, stock: 1, purchasable: true, delivery: 'Manual', required_fields: ['Email akun', 'Password lama'] },
                ],
            };
        }
        if (path === '/api/bot/pesanan') return { order: PESANAN, payment: { qris_url: 'https://toko.test/qris.jpg', holder: '' } };
        return null;
    });

    engine.resetRateLimit('6282395124106');
    const { clearState } = await import('../src/core/session.js');
    clearState('chat-uji');

    await kirim('gemini pro');

    const mintaData = await kirim('1');
    assert.match(mintaData.replies[0], /Email akun/);
    assert.match(mintaData.replies[0], /Password lama/);

    const selesai = await kirim('budi@contoh.test\nrahasia123');
    assert.match(selesai.replies[0], /Pesanan dibuat/);

    const orderCall = panggilan.find((p) => p.path === '/api/bot/pesanan' && p.method === 'POST');
    assert.deepEqual(orderCall.body.required_info, ['budi@contoh.test', 'rahasia123']);
});

test('data wajib kurang ditolak', async () => {
    stubFetch((path) => {
        if (path === '/api/bot/katalog') return KATALOG;
        if (path === '/api/bot/produk/gemini-pro') {
            return {
                ...DETAIL,
                variants: [{ id: 21, name: 'Private', price: 50000, purchasable: true, stock: 1, required_fields: ['Email akun', 'Password lama'] }],
            };
        }
        return null;
    });

    engine.resetRateLimit('6282395124106');
    const { clearState } = await import('../src/core/session.js');
    clearState('chat-uji');

    await kirim('gemini pro');
    await kirim('1');

    const { replies } = await kirim('hanya-satu-baris');
    assert.match(replies[0], /Butuh 2 data/);
    assert.ok(!panggilan.some((p) => p.path === '/api/bot/pesanan'));
});

test('data wajib berupa angka tetap tersimpan (Player ID)', async () => {
    stubFetch((path) => {
        if (path === '/api/bot/katalog') return KATALOG;
        if (path === '/api/bot/produk/gemini-pro') {
            return {
                ...DETAIL,
                variants: [{ id: 30, name: 'Private', price: 50000, purchasable: true, stock: 5, required_fields: ['Player ID'] }],
            };
        }
        if (path === '/api/bot/pesanan') return { order: PESANAN, payment: { qris_url: null, holder: '' } };
        return null;
    });

    engine.resetRateLimit('6282395124106');
    const { clearState } = await import('../src/core/session.js');
    clearState('chat-uji');

    await kirim('gemini pro');
    await kirim('1');

    const selesai = await kirim('12345678');
    assert.match(selesai.replies[0], /Pesanan dibuat/);

    const orderCall = panggilan.find((p) => p.path === '/api/bot/pesanan' && p.method === 'POST');
    assert.deepEqual(orderCall.body.required_info, ['12345678']);
});

test('status menunggu data wajib tidak dianggap data', async () => {
    stubFetch((path) => {
        if (path === '/api/bot/katalog') return KATALOG;
        if (path === '/api/bot/produk/gemini-pro') {
            return {
                ...DETAIL,
                variants: [{ id: 31, name: 'Private', price: 50000, purchasable: true, stock: 1, required_fields: ['Email'] }],
            };
        }
        return null;
    });

    engine.resetRateLimit('6282395124106');
    const { clearState } = await import('../src/core/session.js');
    clearState('chat-uji');

    await kirim('gemini pro');
    await kirim('1');

    const { replies } = await kirim('status');
    assert.match(replies[0], /Kirim nomor invoice/);
    assert.ok(!panggilan.some((p) => p.path === '/api/bot/pesanan'));
});
