/**
 * Uji pembatasan katalog & konsistensi nomor urut.
 *
 * Nomor yang dilihat pembeli di `list` HARUS menunjuk produk yang sama saat
 * mereka membalas nomor itu. Kalau daftar yang dirender dan daftar yang dipakai
 * untuk mencari berbeda, pembeli membeli produk yang salah.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

process.env.BOT_API_TOKEN = 'token-uji';

const { batasiKatalog, daftarProdukDatar, cariProduk } = await import('../src/core/engine.js');
const R = await import('../src/core/replies.js');

function katalogBuatan(jumlahKategori = 3, perKategori = 4) {
    const categories = [];

    let n = 0;
    for (let k = 1; k <= jumlahKategori; k += 1) {
        const products = [];
        for (let i = 1; i <= perKategori; i += 1) {
            n += 1;
            products.push({
                id: n,
                slug: `produk-${n}`,
                name: `Produk ${n}`,
                price_min: 1000 * n,
                price_max: 1000 * n,
                sold_count: 0,
            });
        }
        categories.push({ id: k, name: `Kategori ${k}`, slug: `kategori-${k}`, products });
    }

    return { store: { name: 'Toko Uji', whatsapp: '6282395124106' }, categories };
}

test('batasiKatalog: total produk dibatasi, bukan per kategori', () => {
    const hasil = batasiKatalog(katalogBuatan(3, 4), 5);
    const total = hasil.reduce((n, k) => n + k.products.length, 0);

    assert.equal(total, 5);
    assert.equal(hasil.length, 2, 'kategori kedua terpotong sebagian');
    assert.equal(hasil[0].products.length, 4);
    assert.equal(hasil[1].products.length, 1);
});

test('batasiKatalog: kategori kosong dibuang', () => {
    const katalog = katalogBuatan(3, 4);
    katalog.categories[0].products = [];

    const hasil = batasiKatalog(katalog, 10);

    assert.ok(hasil.every((k) => k.products.length > 0));
});

test('batasiKatalog: batas lebih besar dari jumlah produk = semua tampil', () => {
    const hasil = batasiKatalog(katalogBuatan(2, 3), 100);
    const total = hasil.reduce((n, k) => n + k.products.length, 0);

    assert.equal(total, 6);
});

test('nomor di list menunjuk produk yang sama di daftar datar', () => {
    const katalog = katalogBuatan(3, 4);
    const terbatas = batasiKatalog(katalog, 7);

    // Ambil nomor yang tertulis di pesan katalog.
    const pesan = R.katalog(katalog.store, terbatas);
    const nomorDiPesan = [...pesan.matchAll(/^(\d+)\. (.+)$/gm)].map((m) => ({
        nomor: Number(m[1]),
        nama: m[2],
    }));

    const datar = daftarProdukDatar(terbatas);

    assert.equal(nomorDiPesan.length, datar.length);

    for (const { nomor, nama } of nomorDiPesan) {
        assert.equal(datar[nomor - 1].name, nama, `nomor ${nomor} harus ${nama}`);
    }
});

test('daftarProdukDatar menerima objek katalog maupun array kategori', () => {
    const katalog = katalogBuatan(2, 2);

    assert.equal(daftarProdukDatar(katalog).length, 4);
    assert.equal(daftarProdukDatar(katalog.categories).length, 4);
    assert.equal(daftarProdukDatar(batasiKatalog(katalog, 3)).length, 3);
});

test('pencarian mencocokkan nama dan kategori', () => {
    const datar = daftarProdukDatar(katalogBuatan(2, 2));

    assert.equal(cariProduk(datar, 'produk 3').length, 1);
    assert.equal(cariProduk(datar, 'kategori 1').length, 2);
    assert.equal(cariProduk(datar, 'zzz').length, 0);
});

test('pencarian mengutamakan nama yang persis sama', () => {
    const datar = [
        { name: 'Canva', category: 'Editor', price_min: 1000, price_max: 1000 },
        { name: 'Canva Pro', category: 'Editor', price_min: 2000, price_max: 2000 },
    ];

    const hasil = cariProduk(datar, 'canva');

    assert.equal(hasil.length, 1);
    assert.equal(hasil[0].name, 'Canva');
});
