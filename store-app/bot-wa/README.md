# Bot WhatsApp — Creative Solutions

Bot WhatsApp untuk toko **Creative Solutions**. Pembeli mengetik `list` dan
bot mengirim katalog dari database Laravel (produk, varian, harga, stok asli) —
bukan katalog terpisah, jadi perubahan di panel admin langsung ikut.

```
WhatsApp (pembeli)
      ↓ pesan
bot-wa (Node + whatsapp-web.js + Chrome)
      ↓ HTTP + bearer token
Laravel  /api/bot/*        ← harga & stok dihitung di sini
      ↓
MySQL (produk, varian, pesanan)
```

## Kenapa harga tidak dihitung bot

Bot hanya menyusun teks. Semua angka berasal dari `OrderService` yang sama
dengan checkout web, sehingga tidak mungkin ada selisih harga antara web dan
WhatsApp. Kalau API mati, bot berhenti melayani dengan pesan jelas — bukan
mengarang data.

## Perintah pembeli

| Ketik | Hasil |
|---|---|
| `list` (juga `katalog`, `produk`, `menu`, `daftar`, `harga`) | katalog per kategori + harga |
| `1`, `2`, … | buka detail produk ke-N |
| `1`, `2`, … (di layar detail) | pilih paket/varian |
| `order` / `beli` | pesan paket pertama |
| nama produk (`canva`, `netflix`) | cari produk |
| `status` lalu nomor invoice | status pesanan + isi akun (kalau lunas) |
| `riwayat` | daftar pesanan dari nomor ini |
| `bantuan` / `cs` | panduan + tautan admin |
| `batal` | batalkan proses berjalan |

## Alur pesanan

```
list → pilih produk → pilih paket → nama → data wajib (kalau ada)
     → konfirmasi "ya" → pesanan dibuat di database + QRIS dikirim
     → pembeli bayar → admin verifikasi di panel → isi akun terkirim
```

Pesanan yang dibuat bot **masuk ke database Laravel**, jadi muncul di panel
admin, memotong stok, dan mengikuti aturan yang sama dengan pesanan web.

## Instalasi

```bash
cd bot-wa
npm install
cp .env.example .env        # lalu isi BOT_API_TOKEN
npx @puppeteer/browsers install chrome@146.0.7680.153
npm start
```

Saat pertama jalan, QR muncul di terminal. Scan lewat
**WhatsApp → Perangkat Tertaut**. Sesi disimpan di `.wwebjs_auth/`, jadi tidak
perlu scan ulang setiap restart.

### Token

`BOT_API_TOKEN` di `bot-wa/.env` harus **sama** dengan `BOT_API_TOKEN` di
`store-app/.env`. Buat yang acak:

```bash
php -r "echo bin2hex(random_bytes(24));"
```

Tanpa token yang cocok, seluruh endpoint `/api/bot/*` menolak semua permintaan.

## Konfigurasi

| Variabel | Arti |
|---|---|
| `BOT_API_URL` | URL Laravel, mis. `https://store.avhanapp.my.id` |
| `BOT_API_TOKEN` | **wajib**, sama dengan token di Laravel |
| `BOT_ADMIN_NUMBERS` | nomor admin (62xxx), dipisah koma, untuk notifikasi pesanan baru |
| `BOT_CHROME_PATH` | path Chrome; kosong = cari otomatis |
| `BOT_ALLOWLIST` | kalau diisi, hanya nomor ini yang dilayani (uji produksi) |
| `BOT_RATE_LIMIT` | batas pesan per nomor per menit (default 20) |
| `BOT_HEADLESS` | `false` untuk melihat jendela Chrome (debug) |
| `BOT_WEB_VERSION` | pin versi WA Web, mis. `2.3000.1012355863` |
| `BOT_LOG_LEVEL` | `debug` \| `info` \| `warn` \| `error` |

## Uji dari nomor yang sama dengan bot (tanpa HP kedua)

Kalau Anda hanya punya satu nomor (nomor toko), aktifkan uji coba sendiri:

```env
BOT_ALLOW_SELF=true
```

Lalu kirim `list` dari chat dengan diri sendiri. Nonaktifkan lagi (`false`)
setelah selesai — di produksi harus `false` supaya bot tidak membalas pesan
sendiri.

## Uji tanpa WhatsApp

Seluruh alur bisa diuji tanpa Chrome dan tanpa scan QR:

```bash
npm test          # 52 test: parser, format, filter WA, katalog, alur percakapan
npm run simulate  # coba percakapan langsung di terminal
npm run cek       # periksa token + katalog ke Laravel (tanpa kirim pesan)
```

`npm test` memakai `fetch` tiruan, jadi tidak menyentuh jaringan atau database.

Sebelum menyalakan bot di server baru, jalankan `npm run cek` — skrip itu
memastikan token diterima dan katalog terbaca, tanpa mengirim pesan WhatsApp
apa pun.

## Menjalankan 24/7

```bash
./start.sh
```

`start.sh` membersihkan `SingletonLock` sisa Chrome yang crash (kalau tidak,
QR tidak pernah muncul), lalu menjalankan bot. Kalau proses mati, jalankan
ulang — atau bungkus dengan systemd:

```ini
[Unit]
Description=Bot WhatsApp Creative Solutions
After=network-online.target

[Service]
Type=simple
User=bot
WorkingDirectory=/opt/bot-wa
ExecStart=/usr/bin/node src/index.js
Restart=always
RestartSec=10
# Chrome butuh memori; batasi supaya VPS kecil tidak OOM
MemoryMax=700M

[Install]
WantedBy=multi-user.target
```

## Catatan operasional

- **Chrome harus versi 146.** Chrome yang lebih baru (termasuk Flatpak)
  membuat `whatsapp-web.js` gagal inject dengan
  `Execution context was destroyed`. Pasang lewat `@puppeteer/browsers`.
- **Bot mengabaikan grup, status, dan broadcast.** Tanpa filter ini bot akan
  membalas setiap pesan siaran.
- **Kredensial tidak pernah dikirim sebelum lunas.** Endpoint status
  memverifikasi nomor WhatsApp pembeli, dan hanya mengembalikan isi akun kalau
  pesanan sudah dibayar.
- **Sesi LocalAuth berisi kunci akun WhatsApp.** Jangan commit `.wwebjs_auth/`
  (sudah ada di `.gitignore`).

## Sesi WhatsApp (scan QR sekali saja)

Bot memakai `LocalAuth` dengan folder `.wwebjs_auth/`. Setelah scan QR
pertama kali, sesi disimpan di folder itu dan bot akan **login otomatis
tanpa QR** setiap kali dijalankan ulang.

- `run-lokal.sh` hanya menghapus `SingletonLock` (berkas kunci Chrome yang
  macet), **bukan** sesi. Sesi tidak akan hilang.
- Kalau bot minta scan ulang terus, kemungkinan sesi kedaluwarsa atau rusak:
  jalankan `./run-lokal.sh --reset` untuk menghapus sesi dan scan ulang.
- Sesi disimpan di disk biasa (bukan tmpfs), jadi tetap ada setelah reboot.

Saat sesi valid, bot mencetak `Autentikasi berhasil. Sesi tersimpan untuk
login otomatis berikutnya.` dan `WhatsApp siap` — tidak ada QR.
