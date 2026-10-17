/** Logger sederhana dengan level, tanpa dependency. */
import { config } from '../config.js';

const LEVELS = { debug: 10, info: 20, warn: 30, error: 40 };
const current = LEVELS[config.logLevel] ?? LEVELS.info;

function stamp() {
    return new Date().toISOString().slice(11, 19);
}

function write(level, args) {
    if ((LEVELS[level] ?? 20) < current) return;

    const prefix = {
        debug: '·',
        info: 'ℹ',
        warn: '⚠',
        error: '✖',
    }[level];

    const out = level === 'error' || level === 'warn' ? console.error : console.log;
    out(`[${stamp()}] ${prefix}`, ...args);
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
