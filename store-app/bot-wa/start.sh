#!/usr/bin/env bash
# Jalankan bot WhatsApp. Kalau proses mati, jalankan ulang skrip ini.
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

# Chrome meninggalkan berkas kunci ini kalau proses sebelumnya mati tidak
# wajar. Kalau tidak dibersihkan, Chrome menolak start dan QR tidak muncul.
rm -f .wwebjs_auth/session/Singleton* 2>/dev/null || true

if [ ! -f .env ]; then
    echo "✖ .env belum ada. Salin dulu: cp .env.example .env" >&2
    exit 1
fi

if ! grep -qE '^BOT_API_TOKEN=.+' .env; then
    echo "✖ BOT_API_TOKEN belum diisi di .env" >&2
    exit 1
fi

echo "==> Bot WhatsApp Creative Solutions"
echo "==> Stop: Ctrl+C"
echo

exec node src/index.js
