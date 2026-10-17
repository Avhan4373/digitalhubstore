#!/usr/bin/env bash
# Jalankan bot WhatsApp di mesin LOKAL (untuk pengembangan).
#
# Bot berjalan di komputer ini, dan memanggil Laravel yang bisa diarahkan ke:
#   - http://127.0.0.1:8000  (store dev lokal)  -> default
#   - https://store.avhanapp.my.id (store produksi) -> edit .env
#
# Scan QR HANYA sekali. Setelah sesi tersimpan di .wwebjs_auth/, bot login
# otomatis tanpa QR setiap kali dijalankan ulang.
#
# Untuk scan ulang (mis. ganti nomor): ./run-lokal.sh --reset
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

if [ "${1:-}" = "--reset" ]; then
    echo "==> Menghapus sesi tersimpan (.wwebjs_auth)..."
    rm -rf .wwebjs_auth .wwebjs_cache
    echo "   Sesi dihapus. Scan QR akan muncul lagi."
fi

if [ ! -f .env ]; then
    echo "✖ .env belum ada. Salin dulu: cp .env.example .env"
    exit 1
fi

if ! grep -qE '^BOT_API_TOKEN=.+' .env; then
    echo "✖ BOT_API_TOKEN belum diisi di .env"
    exit 1
fi

URL=$(grep -E '^BOT_API_URL=' .env | cut -d= -f2- | tr -d '"' | tr -d "'")

echo "==> Cek Laravel di $URL ..."
if ! curl -fsS -o /dev/null --max-time 5 "$URL/up" 2>/dev/null && ! curl -fsS -o /dev/null --max-time 5 "$URL/" 2>/dev/null; then
    echo "✖ Laravel tidak bisa dihubungi di $URL"
    echo "   Kalau memakai store dev lokal, jalankan: php artisan serve --port=8000"
    exit 1
fi
echo "✔ Laravel reachable"

TOKEN=$(grep -E '^BOT_API_TOKEN=' .env | cut -d= -f2- | tr -d '"' | tr -d "'")
HTTP=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 -H "Authorization: Bearer $TOKEN" "$URL/api/bot/katalog" 2>/dev/null || echo "000")

if [ "$HTTP" != "200" ]; then
    echo "✖ Token ditolak ($HTTP). Pastikan BOT_API_TOKEN sama di bot-wa/.env dan store-app/.env"
    if [ "$HTTP" = "503" ]; then
        echo "   (503 = BOT_ENABLED belum true atau token kosong di Laravel)"
    fi
    exit 1
fi
echo "✔ Token diterima, katalog terbaca"

if [ -d .wwebjs_auth ] && [ -n "$(find .wwebjs_auth -type f 2>/dev/null)" ]; then
    echo "✔ Sesi tersimpan ditemukan -> bot akan login otomatis tanpa QR"
else
    echo "⚠ Belum ada sesi -> QR akan muncul, scan sekali saja"
fi

# Bersihkan SingletonLock sisa Chrome (TIDAK menghapus sesi).
rm -f .wwebjs_auth/session/Singleton* 2>/dev/null || true

echo
echo "==> Bot WhatsApp Creative Solutions (lokal)"
echo "==> Stop: Ctrl+C"
echo

exec node src/index.js
