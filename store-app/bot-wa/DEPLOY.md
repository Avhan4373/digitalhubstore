# Deploy bot WhatsApp

Bot adalah **proses terpisah** dari Laravel. Laravel tidak perlu diubah saat bot
dipindah server — cukup arahkan `BOT_API_URL` ke toko dan pakai token yang sama.

```
┌─────────────────────┐        HTTPS + bearer token        ┌──────────────────┐
│  VPS bot            │ ─────────────────────────────────► │  VPS toko        │
│  Node + Chrome 146  │   GET /api/bot/katalog             │  Laravel + MySQL │
│  whatsapp-web.js    │   POST /api/bot/pesanan            │                  │
└─────────────────────┘                                    └──────────────────┘
```

## 1. Siapkan sisi Laravel (VPS toko)

Tambahkan ke `store-app/.env`:

```env
BOT_ENABLED=true
BOT_API_TOKEN=<token-acak>
BOT_ADMIN_NUMBERS=6282395124106
```

Buat token:

```bash
php -r "echo bin2hex(random_bytes(24));"
```

Lalu:

```bash
php artisan optimize:clear
```

Uji dari mesin mana pun:

```bash
curl -s -H "Authorization: Bearer $BOT_API_TOKEN" \
  https://store.avhanapp.my.id/api/bot/katalog | head -c 200
```

Harus `200` dengan JSON katalog. Kalau `503` → `BOT_ENABLED` belum `true` atau
token kosong. Kalau `401` → token tidak cocok.

> **Tanpa `BOT_ENABLED=true` + token, semua endpoint bot tertutup.** Ini
> disengaja: salah konfigurasi tidak boleh membuka katalog dan pembuatan
> pesanan ke publik.

## 2. Siapkan VPS bot

Kebutuhan minimum: **2 vCPU / 2 GB RAM** (Chrome ± 500 MB, Node ± 100 MB).
VPS toko yang sekarang (1 vCPU / 957 MB) **tidak cukup** untuk menjalankan
Chrome tanpa mengorbankan MySQL/PHP-FPM.

```bash
sudo apt update
sudo apt install -y nodejs npm chromium-browser || true

# Node 18+ (NodeSource)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs

# Salin folder bot-wa ke server
rsync -az --exclude node_modules --exclude .wwebjs_auth \
  bot-wa/ user@vps-bot:/opt/bot-wa/
```

Di server:

```bash
cd /opt/bot-wa
npm install
cp .env.example .env
nano .env       # isi BOT_API_URL + BOT_API_TOKEN

# Chrome 146 (WAJIB versi ini, bukan Chrome sistem terbaru)
npx @puppeteer/browsers install chrome@146.0.7680.153
```

## 3. Jalankan & scan QR

```bash
cd /opt/bot-wa
./start.sh
```

QR muncul di terminal. Scan lewat **WhatsApp → Perangkat Tertaut**.

Setelah `WhatsApp siap`, uji dari nomor lain:

```
list
```

## 4. Jalankan permanen (systemd)

```ini
# /etc/systemd/system/bot-wa.service
[Unit]
Description=Bot WhatsApp Creative Solutions
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=botwa
WorkingDirectory=/opt/bot-wa
ExecStartPre=/bin/rm -f /opt/bot-wa/.wwebjs_auth/session/Singleton*
ExecStart=/usr/bin/node src/index.js
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

# Chrome butuh memori; batasi supaya tidak menghabiskan VPS
MemoryMax=1200M

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now bot-wa
sudo journalctl -u bot-wa -f
```

## 5. Daftar periksa setelah deploy

- [ ] `curl` katalog dari VPS bot membalas `200`
- [ ] QR ter-scan, log menampilkan `WhatsApp siap`
- [ ] `list` dari nomor lain membalas katalog
- [ ] Pesanan uji muncul di **panel admin** dengan `user_agent = whatsapp-bot`
- [ ] Notifikasi pesanan baru masuk ke nomor `BOT_ADMIN_NUMBERS`
- [ ] Nomor lain tidak bisa membuka invoice orang lain (`403`)
- [ ] Isi akun hanya muncul setelah pesanan lunas
- [ ] Bot tidak membalas pesan grup/siaran

## 6. Kalau bermasalah

| Gejala | Sebab & solusi |
|---|---|
| `Chrome tidak ditemukan` | Jalankan `npx @puppeteer/browsers install chrome@146.0.7680.153`, atau set `BOT_CHROME_PATH` |
| QR tidak muncul | `rm -f .wwebjs_auth/session/Singleton*` lalu jalankan ulang (sudah otomatis di `start.sh`) |
| `Execution context was destroyed` | Chrome terlalu baru. Pakai Chrome 146, jangan Flatpak |
| `401 Token bot tidak valid` | `BOT_API_TOKEN` di bot ≠ di Laravel |
| `503 bot_disabled` | `BOT_ENABLED=true` belum di-set di Laravel, atau `optimize:clear` belum dijalankan |
| Bot membalas pesan grup | Filter `core/wa-filter.js` rusak — ada test regresinya, jalankan `npm test` |
| Bot mati tiap beberapa jam | Cek `journalctl -u bot-wa`; biasanya OOM. Naikkan `MemoryMax` atau RAM VPS |
| Sesi minta scan ulang terus | `LocalAuth` butuh folder `.wwebjs_auth` yang persisten; jangan di dalam tmpfs |
