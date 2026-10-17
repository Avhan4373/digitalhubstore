/**
 * Cek kesehatan koneksi bot -> Laravel.
 *
 * Jalankan sebelum menyalakan bot di server baru, atau saat bot tidak menjawab:
 *   npm run cek
 *
 * Yang diperiksa: token diterima, katalog terbaca, dan jumlah produk/varian
 * masuk akal. Tidak mengirim pesan WhatsApp apa pun.
 */
import { assertConfig, config } from '../src/config.js';
import { api, ApiError } from '../src/core/api.js';
import { daftarProdukDatar } from '../src/core/engine.js';

assertConfig();

function lulus(label, nilai) {
    console.log(`✔ ${label}${nilai ? ` — ${nilai}` : ''}`);
}

function gagal(label, pesan) {
    console.log(`✖ ${label} — ${pesan}`);
}

console.log('');
console.log('Cek koneksi bot → Laravel');
console.log(`API: ${config.apiUrl}`);
console.log('');

let adaMasalah = false;

try {
    const data = await api.catalog();

    lulus('Token diterima & katalog terbaca');
    lulus('Nama toko', data.store?.name || '(kosong)');
    lulus('Nomor WA toko', data.store?.whatsapp || '(kosong)');

    const kategori = data.categories || [];
    const produk = daftarProdukDatar(data);

    lulus('Kategori', kategori.length);
    lulus('Produk', produk.length);

    if (produk.length === 0) {
        gagal('Katalog kosong', 'tidak ada produk aktif dengan varian aktif');
        adaMasalah = true;
    }

    if (!data.store?.whatsapp) {
        gagal('Nomor WA toko kosong', 'isi di panel Pengaturan (store_whatsapp)');
        adaMasalah = true;
    }

    if (kategori.length > config.batasProduk) {
        console.log(`  ⚠ Katalog ${produk.length} produk > batas ${config.batasProduk}; sisanya hanya bisa dicari lewat nama.`);
    }

    // Uji satu produk untuk memastikan endpoint detail juga sehat.
    const pertama = produk[0];
    if (pertama) {
        const detail = await api.product(pertama.slug);
        const varian = detail.variants || [];

        lulus(`Detail produk "${pertama.name}"`, `${varian.length} varian`);

        if (varian.length === 0) {
            gagal('Produk tanpa varian aktif', pertama.name);
            adaMasalah = true;
        }

        const bisaBeli = varian.filter((v) => v.purchasable).length;
        if (bisaBeli === 0) {
            console.log('  ⚠ Semua varian produk pertama tidak bisa dibeli (stok habis / nonaktif).');
        }
    }
} catch (e) {
    adaMasalah = true;

    if (e instanceof ApiError) {
        gagal('Koneksi/otorisasi', `${e.status || 'jaringan'} — ${e.message}`);

        if (e.status === 401) {
            console.log('  → BOT_API_TOKEN di bot berbeda dengan BOT_API_TOKEN di Laravel.');
        }

        if (e.status === 503) {
            console.log('  → Di Laravel: set BOT_ENABLED=true dan BOT_API_TOKEN, lalu `php artisan optimize:clear`.');
        }

        if (e.status === 0) {
            console.log('  → Server tidak bisa dihubungi. Cek BOT_API_URL dan jaringan.');
        }
    } else {
        gagal('Tidak terduga', e.message);
    }
}

console.log('');

if (adaMasalah) {
    console.log('Ada masalah yang perlu diperbaiki sebelum bot dijalankan.');
    process.exit(1);
}

console.log('Semua pemeriksaan lulus. Bot siap dijalankan.');
