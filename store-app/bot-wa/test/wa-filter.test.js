/**
 * Uji filter pesan WhatsApp.
 *
 * Kalau filter ini bocor, bot membalas pesan grup/siaran/status — kesalahan
 * paling umum pada bot WhatsApp, dan akibatnya bot bisa membanjiri kontak.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { alasanDiabaikan, chatIdDariNomor, harusDiabaikan, nomorDariChatId, nomorDariKontak } from '../src/core/wa-filter.js';

test('filter: chat pribadi dilayani', () => {
    assert.equal(harusDiabaikan({ from: '6282395124106@c.us' }), false);
    assert.equal(harusDiabaikan({ from: '6282395124106@c.us', body: 'list' }), false);
});

test('filter: chat @lid (Linked Identity) DILAYANI', () => {
    // WhatsApp kini mengirim sebagian chat dengan @lid. Filter lama menolaknya
    // sehingga bot tidak pernah merespon — bug nyata yang pernah terjadi.
    assert.equal(harusDiabaikan({ from: '54533652742258@lid' }), false);
    assert.equal(alasanDiabaikan({ from: '54533652742258@lid' }), '');
});

test('filter: grup diabaikan', () => {
    assert.equal(harusDiabaikan({ from: '120363000000000000@g.us' }), true);
});

test('filter: grup hanya dilayani kalau masuk BOT_GROUP_ALLOWLIST', () => {
    const grupUji = '120363429581560330@g.us';

    // Default: semua grup ditolak (perilaku produksi).
    assert.equal(harusDiabaikan({ from: grupUji }), true);
    assert.equal(alasanDiabaikan({ from: grupUji }), 'chat grup');

    // Grup yang didaftarkan eksplisit boleh dilayani.
    assert.equal(harusDiabaikan({ from: grupUji }, false, [grupUji]), false);
    assert.equal(alasanDiabaikan({ from: grupUji }, false, [grupUji]), '');

    // Grup lain tetap ditolak walau allowlist terisi.
    assert.equal(harusDiabaikan({ from: '120363999999999999@g.us' }, false, [grupUji]), true);

    // Pesan sendiri di dalam grup uji tetap ditolak (mencegah loop balasan).
    assert.equal(harusDiabaikan({ from: grupUji, fromMe: true }, false, [grupUji]), true);
});

test('filter: status diabaikan', () => {
    assert.equal(harusDiabaikan({ from: '6282395124106@c.us', isStatus: true }), true);
    assert.equal(harusDiabaikan({ from: 'status@broadcast', isStatus: true }), true);
});

test('filter: pesan sendiri diabaikan', () => {
    assert.equal(harusDiabaikan({ from: '6282395124106@c.us', fromMe: true }), true);
});

test('filter: pesan siaran diabaikan', () => {
    // Inilah yang paling sering terlewat pada bot WhatsApp.
    assert.equal(harusDiabaikan({ from: '6282395124106@c.us', broadcast: true }), true);
});

test('filter: newsletter/channel diabaikan', () => {
    assert.equal(harusDiabaikan({ from: '120363000000000000@newsletter' }), true);
});

test('alasan: memberi tahu kenapa pesan diabaikan', () => {
    assert.match(alasanDiabaikan({ from: '6282395124106@c.us' }), /^$/);
    assert.match(alasanDiabaikan({ from: '1203@g.us' }), /grup/);
    assert.match(alasanDiabaikan({ from: '6282395124106@c.us', fromMe: true }), /diri sendiri/);
    // dengan allowSelf, pesan diri sendiri tidak lagi jadi alasan
    assert.equal(alasanDiabaikan({ from: '6282395124106@c.us', fromMe: true }, true), '');
});

test('filter: allowSelf mengizinkan pesan dari diri sendiri', () => {
    // Berguna untuk uji coba tanpa HP kedua.
    assert.equal(harusDiabaikan({ from: '628984697996@c.us', fromMe: true }, true), false);
    // Default: tetap ditolak.
    assert.equal(harusDiabaikan({ from: '628984697996@c.us', fromMe: true }), true);
});

test('filter: pesan kosong/null diabaikan', () => {
    assert.equal(harusDiabaikan(null), true);
    assert.equal(harusDiabaikan(undefined), true);
    assert.equal(harusDiabaikan({}), true);
});

test('nomor dari chat id', () => {
    assert.equal(nomorDariChatId('6282395124106@c.us'), '6282395124106');
    assert.equal(nomorDariChatId('6282395124106@c.us'), '6282395124106');
    assert.equal(nomorDariChatId(''), '');
});

test('chat id dari nomor', () => {
    assert.equal(chatIdDariNomor('6282395124106'), '6282395124106@c.us');
    assert.equal(chatIdDariNomor('+62 823-9512-4106'), '6282395124106@c.us');
    assert.equal(chatIdDariNomor(''), '');
});

test('nomor kontak: ambil dari JID hasil terjemahan LID', () => {
    // Inilah bentuk nyata `getContactById('<lid>@lid')` pada whatsapp-web.js
    // v1.34: `id` sudah berisi nomor HP, sementara `number` kosong. Kalau nomor
    // tidak terbaca, bot mengirim LID ke API dan Laravel menolaknya dengan
    // "Nomor WhatsApp tidak valid".
    assert.equal(
        nomorDariKontak({ id: { _serialized: '6289520594405@c.us', user: '6289520594405' }, number: undefined }),
        '6289520594405',
    );
    assert.equal(nomorDariKontak({ id: { _serialized: '6282395124106@s.whatsapp.net' } }), '6282395124106');
});

test('nomor kontak: JID @lid TIDAK dipakai sebagai nomor', () => {
    // 135691220558028 adalah LID, bukan nomor HP.
    assert.equal(nomorDariKontak({ id: { _serialized: '135691220558028@lid' } }), null);
});

test('nomor kontak: 08xxx dinormalkan, properti `number` jadi cadangan', () => {
    assert.equal(nomorDariKontak({ id: { _serialized: '62812345678@c.us' } }), '62812345678');
    assert.equal(nomorDariKontak({ id: { _serialized: 'x@lid' }, number: '0812345678' }), '62812345678');
    assert.equal(nomorDariKontak({ numberFormatted: '+62 812-3456-78' }), '62812345678');
});

test('nomor kontak: kosong kalau tidak ada yang valid', () => {
    assert.equal(nomorDariKontak(null), null);
    assert.equal(nomorDariKontak({}), null);
    assert.equal(nomorDariKontak({ id: { _serialized: '120363429581560330@g.us' } }), null);
});
