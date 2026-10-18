/**
 * Test patch runtime whatsapp-web.js.
 *
 * Yang dijaga di sini BUKAN kode library, melainkan kenyataan bahwa patch kita
 * benar-benar terpasang dan idempoten. Tanpa patch `getChatModel()`,
 * `client.getChatById()` selalu melempar `r` sehingga nama grup tidak terbaca
 * dan perintah tutup/buka grup gagal walau bot sudah admin.
 *
 * Test memakai salinan berkas library di folder sementara — `node_modules`
 * tidak boleh diubah oleh test.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import { patchWhatsappWeb } from '../src/patch-lib.js';

/** Blok `getChatModel()` asli (rusak) — disalin dari whatsapp-web.js v1.34.7. */
const BLOK_ASLI = `        model.lastMessage = null;
        if (model.msgs && model.msgs.length) {
            const lastMessage = chat.lastReceivedKey
                ? window
                      .require('WAWebCollections')
                      .Msg.get(chat.lastReceivedKey._serialized) ||
                  (
                      await window
                          .require('WAWebCollections')
                          .Msg.getMessagesById([
                              chat.lastReceivedKey._serialized,
                          ])
                  )?.messages?.[0]
                : null;
            lastMessage &&
                (model.lastMessage =
                    window.WWebJS.getMessageModel(lastMessage));
        }`;

/** Berkas tiruan: cukup memuat kedua jangkar patch. */
function berkasTiruan() {
    // Indentasi disalin dari library supaya jangkar patch cocok persis.
    const isi = [
        'window.WWebJS.sendMessage = async (chatId, content, options) => {',
        '        const msg = {',
        '            ...extraOptions,',
        '        };',
        '};',
        '',
        'window.WWebJS.getChatModel = async (chat, { isChannel = false } = {}) => {',
        BLOK_ASLI,
        '',
        '        delete model.msgs;',
        '',
        '        return model;',
        '};',
    ].join('\n');

    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'patch-wa-'));
    const berkas = path.join(dir, 'Utils.js');

    fs.writeFileSync(berkas, isi, 'utf8');

    return berkas;
}

test('patch getChatModel: jangkar asli dikenali dan diganti', () => {
    const berkas = berkasTiruan();
    const hasil = patchWhatsappWeb(berkas);

    assert.equal(hasil.model, 'dipasang');
    assert.equal(hasil.media, 'dipasang');

    const sesudah = fs.readFileSync(berkas, 'utf8');

    // Bug aslinya: membaca `_serialized` tanpa cadangan.
    assert.doesNotMatch(sesudah, /Msg\.get\(chat\.lastReceivedKey\._serialized\)/);
    // Penggantinya memakai `$1` sebagai cadangan dan tidak melempar.
    assert.match(sesudah, /lastReceivedKey\.\$1/);
    assert.match(sesudah, /Patch bot: lastReceivedKey bisa tanpa _serialized/);
});

test('patch getChatModel: idempoten (dua kali jalan tidak menggandakan)', () => {
    const berkas = berkasTiruan();

    assert.equal(patchWhatsappWeb(berkas).model, 'dipasang');

    const sekali = fs.readFileSync(berkas, 'utf8');

    assert.equal(patchWhatsappWeb(berkas).model, 'sudah-ada');
    assert.equal(fs.readFileSync(berkas, 'utf8'), sekali, 'isi berkas tidak boleh berubah');
});

test('patch media tetap jalan walau patch model sudah terpasang', () => {
    const berkas = berkasTiruan();

    // Patch model dulu, lalu media — keduanya harus terpasang.
    patchWhatsappWeb(berkas);

    const isi = fs.readFileSync(berkas, 'utf8');

    assert.match(isi, /delete message\.__x_id;/);
    assert.match(isi, /lastReceivedKey\.\$1/);
});

test('patch model: berkas tanpa jangkar dilaporkan, tidak merusak berkas', () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'patch-wa-'));
    const berkas = path.join(dir, 'Utils.js');

    fs.writeFileSync(berkas, '// versi library lain\n', 'utf8');

    const hasil = patchWhatsappWeb(berkas);

    assert.equal(hasil.model, 'jangkar-tidak-ada');
    assert.equal(fs.readFileSync(berkas, 'utf8'), '// versi library lain\n');
});

test('patch: berkas tidak ada tidak melempar', () => {
    const hasil = patchWhatsappWeb(path.join(os.tmpdir(), 'tidak-ada-xyz', 'Utils.js'));

    assert.equal(hasil.media, 'berkas-tidak-ada');
    assert.equal(hasil.model, 'berkas-tidak-ada');
});
