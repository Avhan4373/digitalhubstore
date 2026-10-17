import assert from 'node:assert/strict';
import { test } from 'node:test';

import { parsePesan } from '../src/core/parser.js';
import { angka, labelTerjual, normalisasiNomor, pecahPesan, potong, rentangHarga, rupiah, tampilkanNomor } from '../src/core/format.js';

test('parse: perintah list dan aliasnya', () => {
    for (const kata of ['list', 'LIST', 'katalog', 'produk', 'menu', 'daftar', 'harga']) {
        assert.equal(parsePesan(kata).type, 'list', kata);
    }
});

test('parse: list dengan nomor menjadi pilih_produk', () => {
    assert.deepEqual(parsePesan('list 2'), { type: 'pilih_produk', nomor: 2, teks: 'list 2' });
    assert.deepEqual(parsePesan('katalog 10'), { type: 'pilih_produk', nomor: 10, teks: 'katalog 10' });
});

test('parse: invoice dikenali sebelum perintah lain', () => {
    assert.deepEqual(parsePesan('INV261017ABCDE'), { type: 'invoice', invoice: 'INV261017ABCDE', teks: 'INV261017ABCDE' });
    assert.deepEqual(parsePesan('inv261017abcde'), { type: 'invoice', invoice: 'INV261017ABCDE', teks: 'inv261017abcde' });
    // invoice di tengah kalimat tetap terdeteksi
    assert.equal(parsePesan('cek INV261017ABCDE dong').type, 'invoice');
});

test('parse: angka polos', () => {
    assert.deepEqual(parsePesan('3'), { type: 'angka', nomor: 3, teks: '3' });
    assert.deepEqual(parsePesan(' 12 '), { type: 'angka', nomor: 12, teks: '12' });
});

test('parse: order dan order varian', () => {
    assert.equal(parsePesan('order').type, 'order');
    assert.equal(parsePesan('beli').type, 'order');
    assert.deepEqual(parsePesan('order 2'), { type: 'order_varian', nomor: 2, teks: 'order 2' });
});

test('parse: teks bebas jadi pencarian', () => {
    const hasil = parsePesan('canva pro');
    assert.equal(hasil.type, 'cari');
    assert.equal(hasil.kata, 'canva pro');
});

test('parse: status dan riwayat dibedakan', () => {
    assert.equal(parsePesan('status').type, 'status');
    assert.equal(parsePesan('riwayat').type, 'riwayat');
});

test('parse: bantuan dan batal', () => {
    assert.equal(parsePesan('bantuan').type, 'bantuan');
    assert.equal(parsePesan('cs').type, 'bantuan');
    assert.equal(parsePesan('batal').type, 'batal');
});

test('parse: teks mentah selalu ikut supaya isian bebas tidak hilang', () => {
    // Player ID / nomor akun sering berupa angka. Tanpa `teks`, nilai ini
    // akan dibaca sebagai "pilih nomor" saat bot sedang menunggu data wajib.
    const hasil = parsePesan('12345678');

    assert.equal(hasil.type, 'cari');
    assert.equal(hasil.teks, '12345678');
});

test('parse: teks mentah mempertahankan spasi & huruf asli', () => {
    const hasil = parsePesan('  Budi  Santoso  ');

    assert.equal(hasil.teks, 'Budi  Santoso');
});

test('parse: pesan kosong', () => {
    assert.equal(parsePesan('').type, 'kosong');
    assert.equal(parsePesan('   ').type, 'kosong');
});

test('format: rupiah memakai pemisah ribuan Indonesia', () => {
    assert.equal(rupiah(15000), 'Rp15.000');
    assert.equal(rupiah(1500000), 'Rp1.500.000');
    assert.equal(rupiah(0), 'Rp0');
});

test('format: rentang harga', () => {
    assert.equal(rentangHarga(5000, 5000), 'Rp5.000');
    assert.equal(rentangHarga(5000, 35000), 'Rp5.000 – Rp35.000');
    assert.equal(rentangHarga(0, 0), 'Hubungi admin');
});

test('format: label terjual gaya storefront', () => {
    assert.equal(labelTerjual(0), '0');
    assert.equal(labelTerjual(850), '850');
    assert.equal(labelTerjual(30000), '30RB+');
});

test('format: normalisasi nomor Indonesia', () => {
    assert.equal(normalisasiNomor('082395124106'), '6282395124106');
    assert.equal(normalisasiNomor('+62 823-9512-4106'), '6282395124106');
    assert.equal(normalisasiNomor('82395124106'), '6282395124106');
    assert.equal(normalisasiNomor('6282395124106'), '6282395124106');
    // id chat WhatsApp ikut ternormalisasi
    assert.equal(normalisasiNomor('6282395124106@c.us'), '6282395124106');
});

test('format: tampilan nomor', () => {
    assert.equal(tampilkanNomor('6282395124106'), '+62 8239-5124-106');
});

test('format: potong tanpa memotong kata', () => {
    const hasil = potong('Beli layanan resmi dan bergaransi penuh di toko ini', 20);
    assert.ok(hasil.length <= 21, hasil);
    assert.ok(hasil.endsWith('…'));
});

test('format: pecah pesan panjang pada batas baris', () => {
    const baris = Array.from({ length: 200 }, (_, i) => `baris ${i} isi pesan`);
    const potongan = pecahPesan(baris.join('\n'), 200);

    assert.ok(potongan.length > 1);
    for (const p of potongan) {
        assert.ok(p.length <= 200, `terlalu panjang: ${p.length}`);
    }

    // tidak ada isi yang hilang
    const gabung = potongan.join('\n');
    assert.ok(gabung.includes('baris 0'));
    assert.ok(gabung.includes('baris 199'));
});

test('format: angka', () => {
    assert.equal(angka(1500), '1.500');
    assert.equal(angka(0), '0');
});
