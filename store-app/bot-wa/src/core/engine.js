/**
 * Mesin percakapan bot.
 *
 * Menerima teks + identitas pengirim, mengembalikan daftar balasan. Tidak
 * menyentuh WhatsApp maupun Chrome, sehingga seluruh alur bisa diuji dengan
 * `npm test` tanpa scan QR.
 */
import { api, ApiError } from './api.js';
import { normalisasiNomor, potong } from './format.js';
import { parsePesan } from './parser.js';
import * as R from './replies.js';
import { clearState, getState, setState, STATE } from './session.js';
import { config } from '../config.js';

/** Katalog di-cache sebentar supaya `list` tidak menembak API tiap detik. */
const CATALOG_TTL_MS = 60_000;
let catalogCache = { at: 0, data: null };

/** Penahan pesan per nomor (in-memory, murah). */
const hits = new Map();

function kenaRateLimit(nomor, batas) {
    const now = Date.now();
    const windowStart = now - 60_000;
    const daftar = (hits.get(nomor) || []).filter((t) => t > windowStart);

    if (daftar.length >= batas) {
        hits.set(nomor, daftar);
        return Math.max(1, Math.ceil((daftar[0] + 60_000 - now) / 1000));
    }

    daftar.push(now);
    hits.set(nomor, daftar);

    return 0;
}

export function resetRateLimit(nomor) {
    hits.delete(nomor);
}

async function ambilKatalog(force = false) {
    const sekarang = Date.now();

    if (!force && catalogCache.data && sekarang - catalogCache.at < CATALOG_TTL_MS) {
        return catalogCache.data;
    }

    const data = await api.catalog();
    catalogCache = { at: sekarang, data };

    return data;
}

/**
 * Batasi katalog ke `maks` produk TOTAL (bukan per kategori), supaya satu
 * balasan tidak menjadi pesan raksasa. Struktur kategori dipertahankan.
 */
export function batasiKatalog(katalogData, maks) {
    const out = [];
    let sisa = Math.max(1, maks);

    for (const kategori of katalogData.categories || []) {
        if (sisa <= 0) break;

        const produk = (kategori.products || []).slice(0, sisa);
        if (produk.length === 0) continue;

        sisa -= produk.length;
        out.push({ ...kategori, products: produk });
    }

    return out;
}

/**
 * Daftar produk datar dengan urutan SAMA seperti yang dirender `katalog()`.
 * Nomor yang dilihat pembeli harus menunjuk produk yang sama di sini.
 *
 * Menerima objek katalog (`{categories}`) maupun array kategori hasil
 * `batasiKatalog()`.
 */
export function daftarProdukDatar(katalogData) {
    const kategori = Array.isArray(katalogData) ? katalogData : katalogData?.categories || [];
    const out = [];

    for (const k of kategori) {
        for (const produk of k.products || []) {
            out.push({ ...produk, category: k.name });
        }
    }

    return out;
}

/** Cari produk berdasarkan kata kunci (nama/kategori). */
export function cariProduk(daftar, kata) {
    const q = String(kata || '').toLowerCase().trim();
    if (q === '') return [];

    const persis = daftar.filter((p) => p.name.toLowerCase() === q);
    if (persis.length > 0) return persis;

    return daftar.filter(
        (p) =>
            p.name.toLowerCase().includes(q) ||
            String(p.category || '').toLowerCase().includes(q),
    );
}

function formatPilihanProduk(hasil) {
    const baris = ['🔍 *Hasil pencarian*', ''];

    hasil.slice(0, 10).forEach((p, i) => {
        baris.push(`${i + 1}. ${p.name}`, `    ${R.rupiahRange(p.price_min, p.price_max)}`);
    });

    if (hasil.length > 10) {
        baris.push('', `_dan ${hasil.length - 10} produk lain. Persempit kata kunci._`);
    }

    baris.push('', 'Balas *nomor* untuk lihat detail, atau ketik nama produknya.');

    return baris.join('\n');
}

/**
 * Titik masuk utama.
 *
 * @param {object} params
 * @param {string} params.teks isi pesan
 * @param {string} params.nomor nomor pengirim (62xxx)
 * @param {string} params.chatId id chat untuk kunci sesi
 * @param {number} [params.batasRate] batas pesan per menit
 * @returns {Promise<{replies: string[], state?: string}>}
 */
export async function handlePesan({ teks, nomor, chatId, batasRate = 20 }) {
    const nomorBersih = normalisasiNomor(nomor);

    const tunggu = kenaRateLimit(nomorBersih || chatId, batasRate);
    if (tunggu > 0) {
        return { replies: [R.terlaluCepat(tunggu)] };
    }

    const aksi = parsePesan(teks);
    const state = getState(chatId);

    try {
        return await route(aksi, state, chatId, nomorBersih);
    } catch (e) {
        if (e instanceof ApiError) {
            // Pesan validasi dari Laravel (mis. stok habis) diteruskan apa adanya.
            if (e.status === 422 || e.status === 403 || e.status === 404) {
                return { replies: [`⚠️ ${e.message}`] };
            }
        }

        throw e;
    }
}

async function route(aksi, state, chatId, nomor) {
    // ---------------------------------------------------------------- global
    if (aksi.type === 'kosong') {
        return { replies: [R.bantuan(await infoToko())] };
    }

    if (aksi.type === 'bantuan') {
        clearState(chatId);
        return { replies: [R.bantuan(await infoToko())] };
    }

    // Sapaan pertama: sambutan singkat, bukan panduan penuh. Kalau pembeli
    // menyapa di tengah alur, jangan sampai state pesanan ikut hilang.
    if (aksi.type === 'sapa') {
        if (state?.tahap) return { replies: [R.sapaanSingkat()] };

        return { replies: [R.sambutan(await infoToko())] };
    }

    if (aksi.type === 'batal') {
        clearState(chatId);
        return { replies: [R.dibatalkan()] };
    }

    if (aksi.type === 'invoice') {
        clearState(chatId);
        return cekInvoice(aksi.invoice, nomor);
    }

    // ------------------------------------------- state yang menunggu isian
    // HARUS diperiksa sebelum cabang pencarian: saat menunggu nama, pembeli
    // mengetik "Budi Santoso" — itu nama, bukan kata kunci katalog.
    switch (state?.tahap) {
        case STATE.NAMA:
            return terimaNama(state, String(aksi.teks ?? ''), chatId, nomor);

        case STATE.DATA:
            return terimaData(state, String(aksi.teks ?? ''), chatId, nomor);

        case STATE.KONFIRMASI:
            return terimaKonfirmasi(state, aksi, chatId, nomor);

        default:
            break;
    }

    // ---------------------------------------------------------------- katalog
    if (aksi.type === 'list' || aksi.type === 'lanjut') {
        clearState(chatId);
        const data = await ambilKatalog();
        const kategori = batasiKatalog(data, config.batasProduk);

        return { replies: [R.katalog(data.store, kategori)] };
    }

    if (aksi.type === 'status') {
        clearState(chatId);
        return {
            replies: [
                [
                    '🧾 *Cek pesanan*',
                    '',
                    'Kirim nomor invoice Anda.',
                    'Contoh: *INV261017ABCDE*',
                    '',
                    'Belum tahu nomornya? Ketik *riwayat* untuk lihat semua pesanan dari nomor ini.',
                ].join('\n'),
            ],
        };
    }

    if (aksi.type === 'riwayat') {
        const data = await api.orderHistory(nomor);
        return { replies: [R.riwayatPesanan(data.orders || [])] };
    }

    // -------------------------------------------------------------- pencarian
    if (aksi.type === 'cari') {
        const data = await ambilKatalog();
        const daftar = daftarProdukDatar(batasiKatalog(data, config.batasProduk));
        const hasil = cariProduk(daftar, aksi.kata);

        if (hasil.length === 0) {
            return { replies: [R.tidakDitemukan(aksi.kata)] };
        }

        if (hasil.length === 1) {
            return bukaProduk(hasil[0].slug, chatId);
        }

        // Simpan hasil supaya balasan angka berikutnya menunjuk daftar ini.
        setState(chatId, { tahap: STATE.PILIH_CARI, hasil: hasil.map((p) => p.slug) });

        return { replies: [formatPilihanProduk(hasil)] };
    }

    // --------------------------------------------------------- pilih produk
    if (aksi.type === 'pilih_produk') {
        return pilihProdukNomor(aksi.nomor, chatId);
    }

    // ------------------------------------------------------------ per-state
    switch (state?.tahap) {
        case STATE.PILIH_CARI:
            if (aksi.type === 'angka') {
                const slug = state.hasil?.[aksi.nomor - 1];

                if (!slug) {
                    return { replies: [R.nomorSalah(state.hasil?.length || 0)] };
                }

                return bukaProduk(slug, chatId);
            }
            break;

        case STATE.DETAIL:
            if (aksi.type === 'angka' || aksi.type === 'order_varian') {
                return pilihVarian(state, aksi.nomor, chatId, nomor);
            }

            if (aksi.type === 'order') {
                return pilihVarian(state, 1, chatId, nomor);
            }
            break;

        default:
            break;
    }

    // ------------------------------------------------------------ angka bebas
    if (aksi.type === 'angka') {
        return pilihProdukNomor(aksi.nomor, chatId);
    }

    // ------------------------------------------------------------- fallback
    return {
        replies: [
            [
                'Hmm, saya belum mengerti maksudnya. 🤔',
                '',
                'Coba ketik:',
                '• *list* — lihat katalog',
                '• *status* — cek pesanan',
                '• *bantuan* — hubungi admin',
            ].join('\n'),
        ],
    };
}

async function infoToko() {
    const data = await ambilKatalog();
    return data.store;
}

async function pilihProdukNomor(nomorPilih, chatId) {
    const data = await ambilKatalog();
    // Batas yang sama dengan `list`, kalau tidak nomor 25 bisa membuka produk
    // yang tidak pernah ditampilkan ke pembeli.
    const daftar = daftarProdukDatar(batasiKatalog(data, config.batasProduk));
    const produk = daftar[nomorPilih - 1];

    if (!produk) {
        return { replies: [R.nomorSalah(daftar.length)] };
    }

    return bukaProduk(produk.slug, chatId);
}

async function bukaProduk(slug, chatId) {
    const data = await api.product(slug);

    setState(chatId, {
        tahap: STATE.DETAIL,
        produk: data.product,
        varian: data.variants,
    });

    return { replies: [R.detailProduk(data.product, data.variants)] };
}

async function pilihVarian(state, nomorVarian, chatId, nomor) {
    const varian = state.varian?.[nomorVarian - 1];

    if (!varian) {
        return { replies: [R.nomorVarianSalah(state.varian?.length || 0)] };
    }

    if (!varian.purchasable) {
        return {
            replies: [
                [
                    `❌ *${varian.name}* sedang tidak tersedia.`,
                    '',
                    'Pilih paket lain, atau ketik *bantuan* untuk tanya admin.',
                ].join('\n'),
            ],
        };
    }

    const lanjut = {
        ...state,
        tahap: STATE.NAMA,
        varianDipilih: varian,
        nomor,
    };

    setState(chatId, lanjut);

    return { replies: [R.mintaNama(state.produk, varian)] };
}

function terimaNama(state, teks, chatId, nomor) {
    const nama = potong(teks.trim(), 80);

    if (nama.length < 2) {
        return { replies: ['❌ Nama terlalu pendek. Kirim nama lengkap Anda.'] };
    }

    const lanjut = { ...state, tahap: STATE.DATA, nama, nomor };
    const label = state.varianDipilih?.required_fields || [];

    if (label.length === 0) {
        lanjut.tahap = STATE.KONFIRMASI;
        lanjut.data = {};
        setState(chatId, lanjut);

        return {
            replies: [R.konfirmasiPesanan(state.produk, state.varianDipilih, nama, {})],
        };
    }

    setState(chatId, lanjut);

    return { replies: [R.mintaDataWajib(state.produk, state.varianDipilih, label)] };
}

function terimaData(state, teks, chatId, nomor) {
    const label = state.varianDipilih?.required_fields || [];
    const baris = teks
        .split('\n')
        .map((l) => l.trim())
        .filter((l) => l !== '');

    if (baris.length < label.length) {
        return {
            replies: [
                [
                    `❌ Butuh ${label.length} data, Anda mengirim ${baris.length}.`,
                    '',
                    'Kirim ulang, satu data per baris:',
                    ...label.map((l, i) => `${i + 1}. ${l}`),
                ].join('\n'),
            ],
        };
    }

    // Nilai dipetakan berdasarkan URUTAN; label tetap dari server.
    const data = {};
    label.forEach((l, i) => {
        data[l] = baris[i];
    });

    const lanjut = { ...state, tahap: STATE.KONFIRMASI, data, nomor };
    setState(chatId, lanjut);

    return {
        replies: [R.konfirmasiPesanan(state.produk, state.varianDipilih, state.nama, data)],
    };
}

async function terimaKonfirmasi(state, aksi, chatId, nomor) {
    const jawaban = String(aksi.teks || '').trim().toLowerCase();
    const setuju = ['ya', 'y', 'yes', 'ok', 'oke', 'betul', 'benar', 'lanjut'].includes(jawaban);

    if (!setuju) {
        if (['tidak', 'no', 'nggak', 'gak', 'batal'].includes(jawaban)) {
            clearState(chatId);
            return { replies: [R.dibatalkan()] };
        }

        return {
            replies: ['Ketik *ya* untuk membuat pesanan, atau *batal* untuk membatalkan.'],
        };
    }

    const varian = state.varianDipilih;
    const label = varian?.required_fields || [];
    const data = state.data || {};

    // Data wajib dikirim sesuai urutan label yang diminta varian.
    const requiredInfo = label.map((l) => data[l] ?? '');

    const hasil = await api.createOrder({
        name: state.nama,
        whatsapp: nomor,
        variant_id: varian.id,
        qty: 1,
        required_info: requiredInfo,
    });

    clearState(chatId);

    const store = await infoToko();

    return {
        replies: [R.pesananDibuat(hasil.order, hasil.payment, store)],
        order: hasil.order,
        payment: hasil.payment,
    };
}

async function cekInvoice(invoice, nomor) {
    const data = await api.orderStatus(invoice, nomor);

    const hasil = {
        replies: [R.statusPesanan(data.order, data.contents)],
        // Diteruskan ke index.js supaya QRIS bisa dikirim ulang sebagai gambar.
        qris: data.payment?.qris_url || null,
        invoice: data.order?.invoice,
    };

    return hasil;
}
