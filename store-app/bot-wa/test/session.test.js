import assert from 'node:assert/strict';
import { test } from 'node:test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import { sesiTersimpanAda } from '../src/adapters/whatsapp.js';

test('sesi: folder tidak ada -> false', () => {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wa-'));
    assert.equal(sesiTersimpanAda(tmp), false);
});

test('sesi: folder kosong -> false', () => {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wa-'));
    fs.mkdirSync(path.join(tmp, 'session'));
    assert.equal(sesiTersimpanAda(tmp), false);
});

test('sesi: folder session berisi berkas -> true', () => {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wa-'));
    const sesi = path.join(tmp, 'session');
    fs.mkdirSync(sesi);
    fs.writeFileSync(path.join(sesi, 'Default'), 'x');
    assert.equal(sesiTersimpanAda(tmp), true);
});

test('sesi: beberapa sesi, salah satu berisi berkas -> true', () => {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wa-'));
    fs.mkdirSync(path.join(tmp, 'session-default'));
    fs.mkdirSync(path.join(tmp, 'session-abc'));
    fs.writeFileSync(path.join(tmp, 'session-abc', 'creds.json'), '{}');
    assert.equal(sesiTersimpanAda(tmp), true);
});
