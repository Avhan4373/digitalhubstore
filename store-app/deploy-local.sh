#!/usr/bin/env bash
# ===========================================================================
# Deploy store.avhanapp.my.id dari mesin lokal.
#
#   ./deploy-local.sh
#
# Kenapa skrip ini ada: `public/build` di-ignore git (lihat .gitignore), jadi
# `deploy.sh` di VPS — yang hanya `git reset --hard` — TIDAK PERNAH membawa
# aset frontend. Kalau build lokal lupa di-rsync, VPS tetap menyajikan bundel
# lama sementara kode PHP-nya baru: perubahan `resources/js` dan `app.css`
# tampak "tidak berpengaruh" padahal sudah di-commit dan di-push.
#
# Skrip ini memaksa urutan yang benar:
#   1. build aset di lokal (VPS RAM-nya 1 GB, jangan build di sana)
#   2. push commit ke GitHub
#   3. tarik kode di VPS
#   4. kirim public/build (setelah reset, supaya tidak tertimpa)
#   5. reload layanan
#
# Prasyarat: SSH key ~/.ssh/biznetgio bisa masuk sebagai avhanvps.
# ===========================================================================
set -euo pipefail

VPS=${VPS:-avhanvps@103.127.97.249}
KEY=${KEY:-$HOME/.ssh/biznetgio}
APP=/var/www/digitalhubstore/store-app
BRANCH=${1:-main}

cd "$(dirname "$0")"

echo "==> 1/5 Build aset frontend (lokal)"
npm run build

echo "==> 2/5 Push kode ke GitHub"
git add -A
if git diff --cached --quiet; then
    echo "    tidak ada perubahan untuk di-commit"
else
    git commit -m "${COMMIT_MSG:-Deploy: perbarui aplikasi}"
fi
git push origin "$BRANCH"

echo "==> 3/5 Tarik kode di VPS"
ssh -i "$KEY" -o BatchMode=yes "$VPS" "bash /var/www/digitalhubstore/deploy.sh $BRANCH" | tail -12

echo "==> 4/5 Kirim aset frontend (public/build)"
# WAJIB setelah deploy.sh: `git reset --hard` tidak menyentuh berkas
# ter-ignore, jadi build lama akan tetap tersaji kalau langkah ini dilewati.
rsync -az --delete public/build/ -e "ssh -i $KEY -o BatchMode=yes" \
    "$VPS:$APP/public/build/"

echo "==> 5/5 Muat ulang layanan"
ssh -i "$KEY" -o BatchMode=yes "$VPS" 'sudo systemctl reload php8.3-fpm && sudo systemctl reload nginx'

echo
echo "==> Selesai. Bundel aktif di VPS:"
ssh -i "$KEY" -o BatchMode=yes "$VPS" "grep -oE 'app-[A-Za-z0-9_-]+\\.js' $APP/public/build/manifest.json | head -1"
echo "    (bandingkan dengan lokal: $(grep -oE 'app-[A-Za-z0-9_-]+\.js' public/build/manifest.json | head -1))"
