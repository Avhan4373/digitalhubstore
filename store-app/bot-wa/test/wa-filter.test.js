/**
 * Uji filter pesan WhatsApp.
 *
 * Kalau filter ini bocor, bot membalas pesan grup/siaran/status — kesalahan
 * paling umum pada bot WhatsApp, dan akibatnya bot bisa membanjiri kontak.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { chatIdDariNomor, harusDiabaikan, nomorDariChatId } from '../src/core/wa-filter.js';

test('filter: chat pribadi dilayani', () => {
    assert.equal(harusDiabaikan({ from: '6282395124106@c.us' }), false);
    assert.equal(harusDiabaikan({ from: '6282395124106@c.us', body: 'list' }), false);
});

test('filter: grup diabaikan', () => {
    assert.equal(harusDiabaikan({ from: '120363000000000000@g.us' }), true);
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
