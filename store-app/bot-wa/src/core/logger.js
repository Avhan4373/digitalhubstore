/** Logger sederhana dengan level, tanpa dependency. */
import fs from 'node:fs';

import { config } from '../config.js';

const LEVELS = { debug: 10, info: 20, warn: 30, error: 40 };
const current = LEVELS[config.logLevel] ?? LEVELS.info;

/** Berkas log opsional (BOT_LOG_FILE) supaya riwayat bisa dibaca ulang. */
let logFile = null;

if (config.logFile) {
    try {
        fs.mkdirSync(config.logFile.replace(/\/[^/]+$/, ''), { recursive: true });
        logFile = config.logFile;
    } catch {
        console.error(`[logger] Tidak bisa membuka BOT_LOG_FILE: ${config.logFile}`);
    }
}

function stamp() {
    return new Date().toISOString().slice(11, 19);
}

function pesanMentah(args) {
    return args
        .map((a) => {
            if (a instanceof Error) return a.message;
            if (typeof a === 'object') return JSON.stringify(a);
            return String(a);
        })
        .join(' ');
}

function write(level, args) {
    if ((LEVELS[level] ?? 20) < current) return;

    const prefix = {
        debug: '·',
        info: 'ℹ',
        warn: '⚠',
        error: '✖',
    }[level];

    const baris = `[${stamp()}] ${prefix} ${pesanMentah(args)}`;

    if (level === 'error' || level === 'warn') {
        console.error(baris);
    } else {
        console.log(baris);
    }

    if (logFile) {
        try {
            fs.appendFileSync(logFile, `${baris}\n`);
        } catch {
            // log file gagal ditulis: jangan sampai mematikan bot
        }
    }
}

export const log = {
    debug: (...a) => write('debug', a),
    info: (...a) => write('info', a),
    warn: (...a) => write('warn', a),
    error: (...a) => write('error', a),
};

/**
 * Sembunyikan nomor WA di log.
 * 6282395124106 -> 6282****4106
 */
export function maskNumber(number) {
    const digits = String(number || '').replace(/\D/g, '');
    if (digits.length < 8) return '***';
    return `${digits.slice(0, 4)}****${digits.slice(-4)}`;
}

/** Nomor WA untuk ditampilkan penuh di log (hanya untuk nomor bot sendiri). */
export function nomorPenuh(number) {
    return String(number || '').replace(/\D/g, '');
}
