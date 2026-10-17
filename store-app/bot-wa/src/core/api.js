/**
 * Klien API internal Laravel.
 *
 * Bot TIDAK menghitung harga, stok, atau diskon sendiri — semuanya dibaca dari
 * endpoint ini. Kalau API mati, bot berhenti melayani dengan pesan jelas
 * daripada mengarang data.
 */
import { config } from '../config.js';
import { log } from './logger.js';

export class ApiError extends Error {
    constructor(message, status, payload) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.payload = payload;
    }
}

const TIMEOUT_MS = 15_000;

async function request(method, path, body) {
    const url = `${config.apiUrl}${path}`;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);

    let res;

    try {
        res = await fetch(url, {
            method,
            headers: {
                Authorization: `Bearer ${config.apiToken}`,
                Accept: 'application/json',
                ...(body ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
            signal: controller.signal,
        });
    } catch (e) {
        clearTimeout(timer);
        const reason = e.name === 'AbortError' ? 'timeout' : e.message;
        throw new ApiError(`Gagal menghubungi server toko (${reason}).`, 0, null);
    }

    clearTimeout(timer);

    let payload = null;
    const text = await res.text();

    if (text) {
        try {
            payload = JSON.parse(text);
        } catch {
            payload = { raw: text.slice(0, 200) };
        }
    }

    if (!res.ok) {
        const message = payload?.message || `Server membalas ${res.status}.`;
        log.warn(`API ${method} ${path} -> ${res.status}: ${message}`);
        throw new ApiError(message, res.status, payload);
    }

    return payload;
}

export const api = {
    catalog: () => request('GET', '/api/bot/katalog'),

    product: (slug) => request('GET', `/api/bot/produk/${encodeURIComponent(slug)}`),

    createOrder: (data) => request('POST', '/api/bot/pesanan', data),

    orderStatus: (invoice, whatsapp) =>
        request('POST', '/api/bot/pesanan/status', { invoice, whatsapp }),

    orderHistory: (whatsapp) => request('POST', '/api/bot/pesanan/riwayat', { whatsapp }),
};
