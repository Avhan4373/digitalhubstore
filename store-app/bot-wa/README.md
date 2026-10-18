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

## Alur pembeli

```
1. Ketik list  -> katalog tampil (papan menu, nama layanan huruf besar)
2. Balas nama layanan (mis. "netflix")  -> daftar paket
3. Balas nama paket (mis. "1 bulan famplan") atau nomornya
   -> pesanan dibuat + QRIS dikirim
4. Bayar, lalu kirim bukti transfer ke chat ini  -> bot DIAM (lihat bawah)
5. Admin cek pembayaran, lalu ketik `proses ...` / `done ...` di chat itu
6. Bot otomatis kirim isi akun
```

Bot **tidak** menanyakan nama atau konfirmasi: begitu paket dipilih, pesanan
langsung dibuat dan QRIS langsung terkirim. Nama pembeli diambil dari nama
tampilan WhatsApp. Balasan pertama bot dikirim sebagai **reply/quote** ke pesan
pembeli (terutama terlihat di grup), sisanya pesan biasa.

**Bukti transfer tidak dibalas bot.** Gambar (atau teks "sudah transfer", "bukti",
"tf") hanya dicatat di log — tidak ada balasan apa pun ke pembeli, termasuk kalau
pesanannya tidak ditemukan. Sebagai gantinya, bot mengirim **notifikasi ke chat
pribadi tiap nomor di `BOT_ADMIN_NUMBERS`**. Kalau pembeli mengirim **gambar**,
gambarnya ikut diteruskan dan keterangan di bawah jadi caption-nya (satu pesan):

```
📸 *Bukti transfer masuk*

👤 Mustaqim / Afik
📱 6282395124106
💬 Grup: DIGITALHUB OFFICIAL
🧾 INV261018ABCDE — GPT PLUS SHARE 1 BULAN

Cek pembayaran di panel, lalu balas *proses* / *done* di chat itu.
```

Gambar hanya diteruskan untuk tipe gambar (`image/*`, termasuk yang dikirim
sebagai dokumen); video/audio dan gambar > ±6 MB tidak diunduh — admin tetap
dapat keterangannya saja. Kalau unduhan gambar gagal, notifikasi teks tetap
dikirim, jadi admin tidak pernah kehilangan informasi bukti masuk.

> Catatan teknis: unduhan **tidak** memakai `msg.downloadMedia()` bawaan
> whatsapp-web.js v1.34.7 — di versi itu pemanggilan internal
> `WAWebDownloadManager.downloadAndMaybeDecrypt` tidak menyertakan `mimetype`
> dan ditolak WhatsApp Web (`Unexpected mimetype application/octet-stream for
> media type image`). Bot memakai `unduhMediaPesan()` di adapter yang mengirim
> `mimetype` (terbukti berhasil; lihat `uji-unduh.mjs`).

Verifikasi pembayaran memang manual: admin mengecek di panel, lalu mengabari
pembeli lewat perintah `proses`/`done` (lihat bawah).

Hanya varian yang benar-benar butuh data (mis. "Email Canva") yang masih
ditanyakan sebelum pesanan dibuat.

## Perintah lain

| Ketik | Hasil |
|---|---|
| `list` (juga `katalog`, `produk`, `menu`, `daftar`, `harga`) | katalog gaya papan menu + tanggal/jam WIB |
| nama produk (`canva`, `netflix`) | cari produk |
| `pay` (juga `payment`, `pembayaran`, `bayar`) | info pembayaran resmi: **satu pesan** = gambar QRIS + teks "PAYMENT RESMI" sebagai caption (nomor admin/owner sah + aturan wajib QRIS) |
| gambar / "sudah transfer" / "bukti" | **tidak dibalas** — admin mengecek manual lalu mengabari lewat `proses`/`done` |
| `status` lalu nomor invoice | status + isi akun (**kalau lunas**) |
| `riwayat` | daftar pesanan dari nomor ini |
| `bantuan` / `cs` | panduan + tautan admin |
| `batal` | batalkan proses berjalan |

Pesanan yang dibuat bot **masuk ke database Laravel**, jadi muncul di panel
admin, memotong stok, dan mengikuti aturan yang sama dengan pesanan web.

## Perintah `pay` (info pembayaran resmi)

Balasan `pay` memakai dua data dari panel admin (**Pengaturan**):

| Pengaturan | Dipakai untuk |
|---|---|
| `owner_numbers` (Nomor admin/owner resmi, satu per baris) | daftar nomor `* 08…` di balasan `pay`; kosong = pakai nomor WhatsApp toko |
| `qris_image` + `qris_holder` | cadangan gambar QRIS kalau berkas lokal bot tidak ada |

Nomor boleh ditulis `08…` maupun `62…` — bot selalu menampilkannya dalam bentuk
`08…`.

Gambarnya diambil dari **berkas lokal bot** `qris.jpeg` (bisa diubah lewat
`BOT_QRIS_FILE` di `.env`) dan dikirim sebagai **satu pesan**: gambar dengan
teks "PAYMENT RESMI" sebagai caption — tidak ada pesan teks terpisah, jadi
kalimat "QRIS diatas" menunjuk gambar di pesan itu sendiri. Kalau berkas lokal
tidak ada, bot memakai `qris_url` dari pengaturan toko; kalau dua-duanya kosong,
teksnya tetap dikirim sebagai pesan biasa dengan peringatan bahwa QRIS belum
diatur.

## Perintah admin `proses` / `done`

Admin (nomor di `BOT_ADMIN_NUMBERS`) mengetik di chat:

| Ketik | Balasan bot |
|---|---|
| `proses` (juga `pending`, `diproses`) | `*_TRANSAKSI PENDING ✅ 」_*` |
| `done` (juga `selesai`, `berhasil`) | `*_TRANSAKSI BERHASIL「 ✅ 」_*` |

Balasannya memuat jam & tanggal WIB saat perintah ditulis, **nama grup WhatsApp**
tempat perintah ditulis (di chat pribadi dipakai nama toko), dan catatan pesanan.
Pesan hanya berisi ringkasan ini — cukup sampai catatan, tidak ada kalimat penutup:

```
*_TRANSAKSI PENDING ✅ 」_*

⏰ Jam      : 11:51:20 WIB
📅 Tanggal  : 18 Oktober 2026
📂 Grup     : DIGITALHUB OFFICIAL
📝 Catatan  : GPT PLUS SHARE 1 BULAN
```

- Catatan boleh ditulis sendiri: `proses LANDING PAGE 1 TAHUN`. Kalau tidak
  ditulis, dipakai nama produk + paket dari **pesanan terakhir** yang dibuat bot
  di chat itu; kalau belum ada pesanan di chat itu, bot meminta catatannya.
- Karena bukti transfer **tidak** dibalas bot, pengumuman `proses`/`done` inilah
  kabar pertama yang diterima pembeli — kirim `proses` setelah pembayaran dicek.
- **Hanya nomor admin.** Pembeli yang mengetik `proses`/`done` tidak dilayani —
  teksnya diperlakukan sebagai pencarian produk biasa.
- Bot **tidak** mengubah status pesanan; verifikasi tetap dilakukan admin di
  panel. Pesan ini hanya pengumuman ke pembeli.
- Percakapan pembeli yang sedang berjalan di chat itu **tidak** dihapus.

## Notifikasi pesanan selesai

Bot memeriksa pesanan yang dibuatnya tiap 60 detik. Begitu admin memverifikasi
pembayaran (pesanan jadi terkirim), bot otomatis mengirim isi akun ke pembeli —
tanpa pembeli perlu bertanya.

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
| `BOT_ADMIN_NUMBERS` | nomor admin (boleh `08…` atau `62…`), dipisah koma: penerima notifikasi pesanan baru **dan** bukti transfer, sekaligus satu-satunya nomor yang boleh mengetik `proses`/`done` |
| `BOT_CHROME_PATH` | path Chrome; kosong = cari otomatis |
| `BOT_ALLOWLIST` | kalau diisi, hanya nomor ini yang dilayani (uji produksi) |
| `BOT_GROUP_ALLOWLIST` | ID grup (`@g.us`) yang boleh dilayani; kosong = semua grup diabaikan |
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

### Uji dari grup milik sendiri

Kalau lebih nyaman memakai grup (mis. butuh HP kedua):

```env
BOT_GROUP_ALLOWLIST=120363429581560330@g.us
```

Bot hanya melayani grup yang didaftarkan. **Semua grup lain tetap diabaikan**,
jadi grup pelanggan tidak akan pernah dibalas bot. Pesan dari diri sendiri di
dalam grup tetap diabaikan, jadi balasan tidak berputar (loop).

Identitas pembeli diambil dari pengirim grup, bukan ID grup — pesanan tetap
memakai nomor WhatsApp pengirim yang sebenarnya.


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
- **Bot mengabaikan grup, status, dan broadcast.** Pengecualian hanya untuk
  grup yang didaftarkan di `BOT_GROUP_ALLOWLIST` (grup uji coba sendiri).
  Tanpa filter ini bot akan membalas setiap pesan siaran.
- **Kredensial tidak pernah dikirim sebelum lunas.** Endpoint status
  memverifikasi nomor WhatsApp pembeli, dan hanya mengembalikan isi akun kalau
  pesanan sudah dibayar.
- **Sesi LocalAuth berisi kunci akun WhatsApp.** Jangan commit `.wwebjs_auth/`
  (sudah ada di `.gitignore`).
- **Kiriman media butuh patch `whatsapp-web.js`.** Di v1.34.7 (versi terakhir di
  npm) `sendMessage()` untuk gambar/video/audio gagal dengan
  `Data passed to getter must include an id property (it's how we memoize)`
  karena properti internal `MediaData.__x_id` menimpa ID pesan keluar
  (isu upstream #201921/#201922, diperbaiki di PR #201923). `src/patch-lib.js`
  menambahkan `delete message.__x_id;` ke berkas injeksi library dan dijalankan
  otomatis setiap bot start (idempoten, jadi `npm install` yang menimpa
  `node_modules` tidak masalah). Gejalanya tanpa patch: QRIS gagal terkirim dan
  hanya teks penggantinya yang sampai ke pembeli.
- **Uji kirim media tanpa pembeli:** hentikan bot, lalu
  `node uji-media.mjs 628xxxxxxxxxx@c.us "caption uji"`. Skrip ini login dengan
  sesi yang sama, mengirim `qris.jpeg` ke chat tujuan, lalu keluar — berguna
  untuk memastikan gambar benar-benar sampai setelah upgrade/patch.
- **Uji quote/reply:** `node uji-quote.mjs 1203xxxxxxxxx@g.us "teks uji"` (bot
  berhenti dulu). Perlu diketahui WhatsApp menolak quote **pesan sendiri**
  (`canReplyMsg() = false`), jadi skrip ini paling berguna untuk memastikan
  API-nya tidak melempar error; bukti quote untuk pesan pembeli muncul di log
  bot sebagai `cek-kutipan -> menempel (quotedStanzaID,…)`.
- **`sendMessage()` v1.34.7 kadang mengembalikan `undefined`** walau pesan
  terkirim (`newMsgKey._serialized` kosong untuk JID `@lid`). Karena itu bot
  TIDAK memakai nilai balik itu sebagai penentu berhasil/gagal — kalau dipakai,
  pesan bisa terkirim dua kali. Status quote dibaca ulang dari store WhatsApp
  (`cek-kutipan`).
- **ID pesan untuk quote/reply.** Di v1.34.7 `msg.id` tidak punya
  `_serialized`; kunci store aslinya ada di `msg.id.$1`
  (`false_<chat>_<id>` dan menyertakan `participant` di grup) dan itulah yang
  dipakai bot. Untuk DM `@lid`, quote dikirim ke chat asal karena ID pesan
  hanya dikenal di chat itu.

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
