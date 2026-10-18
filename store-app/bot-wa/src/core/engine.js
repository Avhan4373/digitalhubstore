/**
 * Mesin percakapan bot (versi sederhana).
 *
 * Alur yang diinginkan pembeli:
 *   1. `list` -> pilih nomor produk -> pilih nomor paket
 *   2. Bot langsung buat pesanan & kirim QRIS (tanpa tanya nama/konfirmasi)
 *   3. Pembeli kirim bukti transfer -> bot kasih tahu sedang diverifikasi
 *   4. Admin verifikasi di panel -> bot kirim isi akun (lihat poller di index.js)
 *
 * Yang dipakai hanya saat varian benar-benar butuh data (mis. "Email Canva").
 *
 * Tidak ada akses WhatsApp di sini: `index.js`/adapter yang mengirim pesan.
 */
import { api, ApiError } from './api.js';
import { normalisasiNomor, nomorValid } from './format.js';
import { parsePesan } from './parser.js';
import * as R from './replies.js';
import { clearState, getState, setState, STATE } from './session.js';
import { config } from '../config.js';

/** Katalog di-cache sebentar supaya `list` tidak menembak API tiap detik. */
const CATALOG_TTL_MS = 60_000;
let catalogCache = { at: 0, data: null };

/** Penahan pesan per nomor (in-memory, murah). */
const hits = new Map();

/**
 * Nomor pembeli per chat, dipakai poller untuk memberi tahu saat pesanan
 * selesai. Disimpan di memori (bot sengaja tidak menyimpan state ke disk).
 * @type {Map<string, string>}
 */
const nomorPerChat = new Map();

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

/** Perbarui nomor pembeli untuk sebuah chat (mis. setelah diminta manual). */
export function catatNomor(chatId, nomor) {
    if (nomor) nomorPerChat.set(chatId, nomor);
}

/** @returns {Array<{chatId: string, nomor: string}>} */
export function nomorTerdaftar() {
    return [...nomorPerChat.entries()].map(([chatId, nomor]) => ({ chatId, nomor }));
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
 * Menerima objek katalog (`{categories}`) maupun array kategori.
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

/**
 * Nama paket dipakai memilih varian (layar detail tidak menampilkan nomor).
 * Kecocokan nama persis menang; kalau tidak ada, nama yang "berisi" kata itu.
 * Return: daftar INDEX varian yang cocok (0 = paket pertama) — kosong kalau
 * tidak cocok, sehingga pemanggil bisa lanjut ke pencarian produk.
 */
export function cariVarian(varian, kata) {
    const q = String(kata || '').toLowerCase().trim();
    if (q === '') return [];

    const indeksPersis = [];
    const indeksBerisi = [];

    (varian || []).forEach((v, i) => {
        // Nama yang dilihat pembeli di layar detail = bot_label kalau ada.
        const nama = String(v?.bot_label || v?.name || '').toLowerCase();

        if (nama === q) indeksPersis.push(i);
        else if (nama.includes(q)) indeksBerisi.push(i);
    });

    return indeksPersis.length > 0 ? indeksPersis : indeksBerisi;
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

/** Kata kunci yang menandakan pembeli mengirim bukti transfer. */
const KATA_BUKTI = ['bukti', 'transfer', 'sudah bayar', 'sudah transfer', 'sudah kirim', 'pembayaran', 'bayar', 'screenshot'];

/** Perintah yang tetap dikenali saat bot sedang menunggu isian. */
const PERINTAH_DARURAT = ['list', 'lanjut', 'status', 'riwayat', 'bantuan', 'batal'];

function adalahPerintah(teks) {
    const t = String(teks || '').trim().toLowerCase();

    return PERINTAH_DARURAT.some((kata) => t === kata || t.startsWith(`${kata} `));
}

function isBukti(ctx, aksi) {
    if (ctx.hasMedia) return true;

    const teks = String(aksi.teks || '').toLowerCase();

    if (KATA_BUKTI.some((kata) => teks.includes(kata))) return true;

    // "tf" singkat: hanya cocok sebagai kata utuh, bukan di dalam "netflix".
    return /(^|\s)tf(\s|$)/.test(teks);
}

/**
 * Apakah nomor ini punya pesanan yang sedang dibayar (pending / belum lunas)?
 *
 * Dipakai validasi bukti transfer: hanya pembeli yang memang punya order
 * pending boleh mengirim bukti — supaya user lain (user2) yang belum order
 * tidak memicu notifikasi palsu ke admin.
 *
 * - `true`  : ada order pending -> bukti diterima, bot diam.
 * - `false` : tidak ada order pending -> bukti ditolak, bot memberi tahu pembeli.
 * - `null`  : belum bisa validasi (nomor LID belum jadi nomor HP, atau API gagal)
 *            -> tetap perlakukan sebagai bukti (bot diam, admin cek manual).
 */
async function buktiValid(nomor) {
    if (!nomor || !nomorValid(nomor)) return null;

    try {
        const { orders } = await api.orderHistory(nomor);

        return (orders || []).some(
            (o) =>
                !o.is_paid &&
                o.can_pay !== false &&
                !['cancelled', 'failed', 'expired'].includes(o.order_status),
        );
    } catch {
        // API gagal: jangan false (bisa menolak pembeli yang akan bayar),
        // dan jangan true (bisa menerima notifikasi palsu). null = biarkan
        // bot diam dan admin yang cek manual.
        return null;
    }
}

/**
 * Jawaban untuk sebuah bukti transfer (gambar atau teks kata kunci).
 * Bot tidak membalas pembeli yang punya order pending (`bukti: true`), tapi
 * menolak balasan ke DM bila tidak ada order pending — agar tidak membanjiri
 * admin notifikasi palsu.
 */
async function buktiAtauTolak(nomor) {
    const valid = await buktiValid(nomor);

    if (valid === null) {
        return { replies: [], bukti: true };
    }

    if (valid === false) {
        return { replies: [R.buktiTidakValid()] };
    }

    return { replies: [], bukti: true };
}

/**
 * Titik masuk utama.
 *
 * @param {object} params
 * @param {string} params.teks isi pesan
 * @param {string} params.nomor nomor pengirim (62xxx / LID)
 * @param {string} params.chatId id chat untuk kunci sesi
 * @param {number} [params.batasRate] batas pesan per menit
 * @param {string} [params.nama] nama tampilan WhatsApp (dipakai untuk invoice)
 * @param {boolean} [params.hasMedia] pesan membawa gambar (bukti transfer)
 * */
export async function handlePesan({
    teks,
    nomor,
    chatId,
    batasRate = 20,
    nama = '',
    hasMedia = false,
    grup = '',
    transaksiTerakhir = null,
}) {
    const nomorBersih = normalisasiNomor(nomor);

    const tunggu = kenaRateLimit(nomorBersih || chatId, batasRate);
    if (tunggu > 0) {
        return { replies: [R.terlaluCepat(tunggu)] };
    }

    if (nomorBersih) nomorPerChat.set(chatId, nomorBersih);

    const aksi = parsePesan(teks);
    const state = getState(chatId);
    const ctx = {
        nama,
        hasMedia,
        teks: String(teks || ''),
        /** Nama grup WhatsApp (kosong kalau chat pribadi). */
        grup,
        /** Pesanan terakhir yang dibuat bot di chat ini (lihat index.js). */
        transaksiTerakhir,
        /** Perintah `proses`/`done` hanya untuk nomor admin. */
        admin: nomorBersih !== '' && config.adminNumbers.includes(nomorBersih),
    };

    try {
        return await route(aksi, state, chatId, nomorBersih, ctx);
    } catch (e) {
        if (e instanceof ApiError) {
            // Pesan validasi dari Laravel (mis. stok habis) diteruskan apa adanya.
            if (e.status === 422 || e.status === 403 || e.status === 404) {
                return { replies: [`⚠️ ${e.message}`] };
            }

            if (e.status === 503) {
                return { replies: [R.gangguan('bot belum aktif di server toko')] };
            }

            if (e.status === 401) {
                return { replies: [R.gangguan('token bot tidak cocok')] };
            }

            if (e.status === 0) {
                return { replies: [R.gangguan('server toko tidak bisa dihubungi')] };
            }
        }

        throw e;
    }
}

/**
 * Pengumuman transaksi dari admin (`proses` = pending, `done` = berhasil).
 *
 * Semua nilai dari konteks nyata: jam & tanggal WIB saat perintah ditulis,
 * nama grup WhatsApp tempat perintah ditulis (fallback nama toko), dan
 * catatan — dari teks perintah dahulu, lalu dari pesanan terakhir di chat itu.
 *
 * Bot **selalu** mengirimkan TRANSAKSI PENDING/DONE: jika belum ada catatan,
 * field `📝 Catatan` saja diabaikan — admin diharapkan melengkapi secara manual
 * di panel. Jangan meminta admin menulis catatan lewat balasan bot, karena itu
 * hanya menambah langkah sebelum pembeli diberi tahu.
 *
 * @param {object} aksi
 * @param {'admin_proses'|'admin_done'} aksi.type
 * @param {string} [aksi.catatan] catatan diketik admin (boleh kosong)
 */
async function notifikasiTransaksi(aksi, ctx) {
    const transaksi = ctx.transaksiTerakhir || null;
    const catatan = String(aksi.catatan || transaksi?.catatan || '').trim();

    const store = await infoToko();

    return {
        replies: [R.transaksi({
            status: aksi.type === 'admin_done' ? 'berhasil' : 'pending',
            grup: ctx.grup || store.name,
            catatan,
        })],
    };
}

async function route(aksi, state, chatId, nomor, ctx) {
    // ---------------------------------------------------------- bukti transfer
    // Gambar selalu dianggap bukti transfer, apa pun teksnya (bisa kosong).
    // Bot TIDAK membalas: verifikasi pembayaran dilakukan admin secara manual,
    // dan pembeli baru mendengar kabar lewat perintah admin `proses`/`done`.
    //
    // Tapi bukti hanya DITERIMA dari pembeli yang punya pesanan pending — supaya
    // user lain (mis. user2) yang belum order tidak mengirimkan notifikasi
    // palsu ke admin. Validasi ada di `buktiAtauTolak()`. Gambar di-cek PERTAMA
    // sebelum perintah lain supaya "gambar + proses" tetap dianggap bukti.
    if (ctx.hasMedia) {
        return buktiAtauTolak(nomor);
    }

    // ---------------------------------------------------------------- global
    if (aksi.type === 'kosong') {
        return { replies: [R.bantuan(await infoToko())] };
    }

    if (aksi.type === 'bantuan') {
        clearState(chatId);
        return { replies: [R.bantuan(await infoToko())] };
    }

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

    // ------------------------------------------------- notifikasi admin
    // Admin menulis `proses <catatan>` (pending) atau `done <catatan>`
    // (berhasil); bot mengirim pengumuman transaksi ke chat itu. Pembeli yang
    // menulis kata yang sama TIDAK dilayani — diperlakukan sebagai pencarian.
    if (aksi.type === 'admin_proses' || aksi.type === 'admin_done') {
        // State percakapan pembeli TIDAK dihapus: pengumuman admin tidak ada
        // hubungannya dengan keranjang/percakapan yang sedang jalan.
        if (ctx.admin) return notifikasiTransaksi(aksi, ctx);

        aksi = { ...aksi, type: 'cari', kata: aksi.teks };
    }

    // --------------------------------------------- state yang menunggu isian
    // Saat menunggu data wajib, teks apa pun dianggap data — KECUALI perintah
    // jelas seperti "status" atau "list" (pembeli mungkin berubah pikiran).
    if (state?.tahap === STATE.DATA && !adalahPerintah(ctx.teks)) {
        return terimaData(state, ctx.teks, chatId, nomor, ctx);
    }

    // Menunggu nomor WhatsApp pembeli (hanya terjadi kalau chat @lid tidak
    // bisa diubah menjadi nomor HP).
    if (state?.tahap === STATE.NOMOR && !adalahPerintah(ctx.teks)) {
        const t = normalisasiNomor(ctx.teks);

        if (!nomorValid(t)) {
            return { replies: [R.mintaNomor(state.produk, state.varianDipilih)] };
        }

        catatNomor(chatId, t);

        return buatPesanan({ ...state, nomor: t }, chatId, t, { ...ctx, nomor: t });
    }

    // Perintah darurat tetap bisa keluar dari layar pilih produk.
    if (state?.tahap === STATE.DETAIL && PERINTAH_DARURAT.includes(aksi.type)) {
        clearState(chatId);
    }

    // ---------------------------------------------------------------- katalog
    if (aksi.type === 'list' || aksi.type === 'lanjut') {
        clearState(chatId);
        const data = await ambilKatalog();
        const kategori = batasiKatalog(data, config.batasProduk);

        return { replies: [R.katalog(data.store, kategori)] };
    }

    // Info pembayaran resmi: nomor owner sah + QRIS resmi.
    // Dikirim sebagai SATU pesan: gambar QRIS dengan teksnya sebagai caption,
    // supaya kalimat "QRIS diatas" menunjuk ke gambar di pesan itu sendiri.
    if (aksi.type === 'pay') {
        // Validasi: `pay` hanya berlaku saat sudah dalam alur order (produk
        // sudah dipilih). Kalau belum ada state, arahkan ke katalog supaya
        // tidak kirim QRIS tanpa konteks pesanan.
        if (!state?.tahap) {
            return { replies: [R.mintaPilihProdukDulu()] };
        }

        clearState(chatId);
        const data = await ambilKatalog();
        const gambar = config.qrisLokal || data.store.qris_url || null;
        const teks = R.pembayaranResmi(data.store, Boolean(gambar));

        // Tanpa gambar (belum diunggah admin & berkas lokal tidak ada), teksnya
        // tetap terkirim sebagai pesan biasa.
        if (!gambar) {
            return { replies: [teks] };
        }

        return {
            replies: [],
            qris: gambar,
            qrisCaption: teks,
            qrisDulu: true,
        };
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
                    'Belum tahu nomornya? Ketik *riwayat*.',
                ].join('\n'),
            ],
        };
    }

    if (aksi.type === 'riwayat') {
        const data = await api.orderHistory(nomor);
        return { replies: [R.riwayatPesanan(data.orders || [])] };
    }

    // ---------------------------------------------------------- bukti transfer
    // Diperiksa sebelum pencarian: "sudah transfer" bukan nama produk. Sama
    // seperti gambar, pesan ini TIDAK dibalas bot — admin yang mengabari
    // pembeli lewat `proses`/`done` setelah mengecek pembayaran. Validasi
    // order pending juga diterapkan (`buktiAtauTolak`), jadi pembeli yang belum
    // order tidak bisa mengirim notifikasi palsu.
    if (isBukti(ctx, aksi)) {
        return buktiAtauTolak(nomor);
    }

    // -------------------------------------------------------------- pencarian
    if (aksi.type === 'cari') {
        // Layar detail tanpa nomor urut: teks bisa berupa NAMA paket
        // ("1 bulan famplan"). Kecocokan tunggal langsung dipesan; kalau
        // ambigu/tidak ada, lanjut ke pencarian produk seperti biasa.
        if (state?.tahap === STATE.DETAIL) {
            const pilihanPaket = cariVarian(state.varian || [], aksi.kata);

            if (pilihanPaket.length === 1) {
                return pilihVarian(state, pilihanPaket[0] + 1, chatId, nomor, ctx);
            }
        }

        const data = await ambilKatalog();
        const daftar = daftarProdukDatar(batasiKatalog(data, config.batasProduk));
        const hasil = cariProduk(daftar, aksi.kata);

        if (hasil.length === 0) {
            return { replies: [R.tidakDitemukan(aksi.kata)] };
        }

        if (hasil.length === 1) {
            return bukaProduk(hasil[0].slug, chatId);
        }

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
                return pilihVarian(state, aksi.nomor, chatId, nomor, ctx);
            }

            if (aksi.type === 'order') {
                return pilihVarian(state, 1, chatId, nomor, ctx);
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
    return { replies: [R.fallback()] };
}

async function infoToko() {
    const data = await ambilKatalog();
    return data.store;
}

async function pilihProdukNomor(nomorPilih, chatId) {
    const data = await ambilKatalog();
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

/**
 * Paket dipilih -> pesanan langsung dibuat + QRIS dikirim.
 *
 * Tidak ada tanya nama/konfirmasi: nama diambil dari tampilan WhatsApp
 * (fallback "Pembeli"), dan QRIS langsung dikirim. Hanya varian yang punya
 * data wajib yang masih ditanya.
 */
async function pilihVarian(state, nomorVarian, chatId, nomor, ctx) {
    const varian = state.varian?.[nomorVarian - 1];

    if (!varian) {
        return { replies: [R.nomorVarianSalah(state.varian?.length || 0)] };
    }

    if (!varian.purchasable) {
        return { replies: [R.varianHabis(varian.name)] };
    }

    const label = varian.required_fields || [];

    // Nomor harus valid supaya pesanan tidak dibuat dengan LID (yang tidak
    // bisa dihubungi admin lewat wa.me). Kalau belum, tanya sekali.
    if (!nomorValid(nomor)) {
        setState(chatId, { ...state, tahap: STATE.NOMOR, varianDipilih: varian, nomor });

        return { replies: [R.mintaNomor(state.produk, varian)] };
    }

    // Tanpa data wajib: langsung buat pesanan + kirim QRIS.
    if (label.length === 0) {
        return buatPesanan({ ...state, varianDipilih: varian, nomor }, chatId, nomor, ctx);
    }

    setState(chatId, { ...state, tahap: STATE.DATA, varianDipilih: varian, nomor });

    return { replies: [R.mintaDataWajib(state.produk, varian, label)] };
}

async function terimaData(state, teks, chatId, nomor, ctx) {
    const label = state.varianDipilih?.required_fields || [];
    const baris = teks
        .split('\n')
        .map((l) => l.trim())
        .filter((l) => l !== '');

    if (baris.length < label.length) {
        return { replies: [R.dataKurang(label, baris.length)] };
    }

    const data = {};
    label.forEach((l, i) => {
        data[l] = baris[i];
    });

    return buatPesanan({ ...state, data }, chatId, nomor, ctx);
}

async function buatPesanan(state, chatId, nomor, ctx) {
    const varian = state.varianDipilih;
    const label = varian.required_fields || [];

    // Nilai dikirim sesuai URUTAN label dari server (label tidak pernah
    // dipercaya dari klien).
    const requiredInfo = label.map((l) => state.data?.[l] ?? '');

    const hasil = await api.createOrder({
        name: (ctx.nama || '').trim().slice(0, 80) || 'Pembeli',
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

    return {
        replies: [R.statusPesanan(data.order, data.contents)],
        // Diteruskan ke index.js supaya QRIS bisa dikirim ulang sebagai gambar.
        qris: data.payment?.qris_url || null,
        invoice: data.order?.invoice,
    };
}
