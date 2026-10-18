# Catatan pekerjaan bot WhatsApp

Catatan kronologis **mengapa** sesuatu dibuat begitu. Tujuannya: kalau nanti ada
yang mau mengubah perilaku ini, dia tahu apa yang sudah dicoba dan kenapa gagal.

Dokumentasi perilaku (cara pakai, daftar perintah) ada di `README.md`.
Panduan menaruh bot di server ada di `DEPLOY.md`.

Terakhir diperbarui: **19 Okt 2026**.

---

## 1. Bukti transfer: validasi dihapus total

**Gejala.** Log 16:38 dan 16:43 menunjukkan pola yang sama berulang:

```
16:38:22 canva  → 16:38:31 pay → 16:38:53 [gambar] → DITOLAK
16:43:04 netflix → 16:43:08 pay → 16:43:16 [gambar] → DITOLAK
```

Bot membalas "bukti transfer tidak dikenali" padahal pembeli memang sudah bayar.

**Penyebab.** `pay` hanya menampilkan QRIS dan **tidak membuat pesanan**. Saat
itu bukti divalidasi dengan syarat "nomor harus punya pesanan aktif"
(`api.orderHistory`), jadi rantai `pay` → kirim bukti **selalu** ditolak.
Dikonfirmasi ke server: nomor admin hanya punya 1 pesanan, statusnya `cancelled`.

**Kenapa ini berbahaya, bukan sekadar mengganggu.** Bukti yang ditolak **tidak
dikabarkan ke admin**. Jadi pembeli yang benar-benar transfer bisa kehilangan
jejak — uang masuk, tidak ada yang tahu.

**Keputusan.** Validasi dihapus total (bukan dilonggarkan). Semua bukti
diteruskan ke admin, nomor pengirim apa adanya, admin yang menilai.

**Jangan diubah balik.** Kalau suatu saat ingin memvalidasi lagi, sadari dulu:
- `pay` tidak membuat pesanan, jadi syarat "sudah order" akan menolak pembeli
  yang membayar lewat `pay`;
- pembeli lama dengan pesanan `cancelled`/`expired` yang bayar ulang juga kena;
- validasi lewat **state percakapan** tetap terlarang: `buatPesanan()` memanggil
  `clearState()`, jadi pembeli yang sudah order justru tidak punya state,
  sementara membuka detail produk sudah membuat state tanpa pesanan.

Kalau nanti notifikasi terasa terlalu ramai, tambahkan **penanda** di notifikasi
admin (mis. "⚠️ belum ada pesanan"), **bukan** penolakan.

Kode: `buktiDiterima()` di `src/core/engine.js`. Commit `a09c68e`.

---

## 2. Tutup/buka grup gagal walau bot sudah admin

**Gejala.** Admin mengetik `tutup grup`, bot membalas "bot belum menjadi admin
di grup ini" — padahal nomor bot sudah dijadikan admin.

**Penyebab sebenarnya bukan izin.** Error-nya cuma `r`, yaitu error minified
yang menyeberang batas puppeteer. Setelah ditelusuri sampai ke dalam halaman
WhatsApp Web:

1. `client.getChatById()` memanggil `getChatModel()`;
2. `getChatModel()` membaca `chat.lastReceivedKey._serialized`;
3. di WhatsApp Web sekarang objek itu kadang **hanya punya `$1`**, tanpa
   `_serialized`;
4. `Msg.get(undefined)` melempar `DataError: ... No key or key range specified`;
5. error itu membatalkan **seluruh** `getChatById()` → jadi `r`.

Bukti dari sesi nyata: bot memang admin (`isAdmin: true`) dan
`setGroupProperty` **berhasil** mengubah `announce` di server. Yang gagal hanya
pembungkusan model setelahnya. Pesan "belum admin" itu **salah menyebut sebab**.

**Efek samping yang sudah lama ada.** Baris `📂 Grup` pada TRANSAKSI PENDING
selalu berisi ID grup (`120363...@g.us`), bukan nama grup — sebabnya sama.

**Perbaikan.**
- `src/patch-lib.js`: patch kedua untuk `getChatModel()` — pakai
  `_serialized || $1`, dan bungkus pengambilan pesan terakhir dengan
  `try/catch`. Patch media dan patch model dijalankan **terpisah** supaya
  instalasi lama tetap dapat patch baru tanpa `npm install` ulang. Keduanya
  idempoten.
- `aturGrup()` di adapter **tidak** memakai `GroupChat.setMessagesAdminsOnly()`.
  Ia mengambil chat lewat `getChat(..., { getAsModel: false })` dan menangkap
  error **di dalam halaman**, supaya pesan asli WhatsApp (mis. kode 403)
  terbaca, bukan menjadi `r`.
- `namaGrup()` di adapter punya jalur cadangan `formattedTitle`.
- Balasan gagal menampilkan **alasan asli**, tidak lagi menyalahkan "belum
  admin" untuk semua kegagalan.

**Catatan.** `npm install` menimpa `node_modules`, jadi patch diterapkan ulang
setiap bot start. Kalau log memuat "Patch ... TIDAK terpasang", berarti jangkar
kodenya berubah (library diperbarui) dan `patch-lib.js` perlu disesuaikan.

Commit `f8bd405`.

---

## 3. API dipindah ke VPS produksi

**Kondisi awal.** VPS `store.avhanapp.my.id` tertinggal **27 commit** dan
`routes/api.php` belum ada sama sekali — `/api/bot/katalog` membalas **404**,
bukan 401. Jadi API-nya belum pernah ter-deploy.

**Yang dilakukan.**
1. Backup dulu: `mysqldump` (158K, 21 tabel) + 2 salinan `.env` →
   `/tmp/dhs-backup/` **di VPS**.
2. Deploy: `c377d3e` → `f8bd405`. Migrasi baru hanya
   `add_bot_label_to_variants_table` (menambah kolom, aman).
3. Aset frontend di-rsync (`deploy.sh` tidak melakukannya — `public/build`
   di-ignore git). Bundel lokal dan VPS harus sama.
4. Aktifkan bot di `.env` VPS: `BOT_ENABLED=true`, `BOT_API_TOKEN` baru
   (48 hex), `BOT_ADMIN_NUMBERS`.
5. Bot lokal diarahkan ke VPS: `BOT_API_URL=https://store.avhanapp.my.id`.

**Keputusan lokasi.** Bot **tetap jalan di mesin lokal**, hanya API-nya ke VPS.
Alasannya RAM VPS: 957 MB total, ~476 MB tersedia. Chrome butuh jauh lebih
besar. Kalau nanti mau dipindah, lihat `DEPLOY.md` bagian systemd.

**Penting: token lokal dan VPS BERBEDA.** `BOT_API_TOKEN` di `bot-wa/.env`
harus sama dengan token di `.env` Laravel **pada server yang dituju**. Salah
pasang = 401.

Commit `f01548b`.

---

## 4. Hal yang perlu diketahui / masih terbuka

- **DB lokal dan VPS terpisah.** Pesanan yang dibuat bot sekarang masuk ke DB
  **produksi**. Pantau panel VPS, bukan panel lokal.
- **`owner_numbers` kosong di VPS.** Perintah `pay` menampilkan daftar nomor
  owner; di VPS pengaturan ini belum diisi, jadi bagian itu kosong (QRIS tetap
  tampil). Isi di *Panel Admin → Pengaturan → Nomor admin/owner resmi*.
- **Backup ada di `/tmp/dhs-backup/` (di VPS).** `/tmp` bisa dibersihkan saat
  reboot. Pindahkan ke tempat permanen kalau ingin disimpan lama.
- **Perintah grup hanya jalan di grup yang terdaftar di `BOT_GROUP_ALLOWLIST`.**
  Grup lain diabaikan bot sepenuhnya, termasuk perintah admin.
- **Bot harus admin grup** untuk `tutup grup` / `buka grup`.
- **`transaksiTerakhir` dikunci per `chatId`.** Di grup, state transaksi bisa
  terbawa antar pembeli. Belum ditangani.

---

## 5. Perbaikan lain di sesi yang sama

- **`pay` dikembalikan ke perilaku sederhana**: selalu langsung kirim QRIS,
  tanpa syarat "sudah pilih produk". Validasi sempat ditambahkan lalu dicabut
  karena menghalangi pemakaian wajar. Commit `f4c1074`.
- **Crash `Runtime.callFunctionOn timed out`**: `protocolTimeout` dinaikkan
  180s → 420s (`BOT_PROTOCOL_TIMEOUT`) dan `process.exit(1)` diganti retry
  bertingkat (15s → 30s → 60s → maks 120s, counter direset setelah berhasil).
  Commit `22bb34d`.
- **`proses`/`done` tanpa catatan** tetap mengirim TRANSAKSI PENDING; bot tidak
  meminta catatan. Commit `fb7435b`.

---

## 6. Status verifikasi

| Pemeriksaan | Hasil |
|---|---|
| `cd bot-wa && npm test` | **106 lulus**, 0 gagal |
| `php artisan test` | **109 lulus**, 514 assertion |
| API VPS tanpa token / token salah | 401 |
| API VPS token benar | 200, ~0.30s, 29 produk, 6 kategori |
| Kebocoran `cost_price`/`supplier` | tidak ada |
| Buat pesanan dari bot | 201, total dihitung server |
| Invoice + nomor lain | 403 ditolak |
| `tutup grup` → `buka grup` (uji nyata) | berhasil, `announce` berubah |
| Bukti tanpa pesanan | diteruskan ke admin, bot diam |

---

## 7. Commit sesi ini

```
a09c68e  bot: hapus validasi bukti transfer — semua bukti diteruskan ke admin
f01548b  bot: arahkan API ke VPS produksi (store.avhanapp.my.id)
f8bd405  bot: perbaiki tutup/buka grup gagal walau bot sudah admin
5d7a2ce  bot: fitur admin tutup/buka grup (announcement WhatsApp)
f4c1074  bot: kembalikan pay jadi langsung tampilkan QRIS tanpa validasi
7b4471f  bot: balasan pay sesuai posisi percakapan, bukan selalu 'ketik list'
22bb34d  bot: perbaiki crash 'Runtime.callFunctionOn timed out'
99b68d2  bot: validasi pay/bukti pakai pesanan nyata di server
ec42be0  bot: validasi stric pay + bukti transfer hanya untuk order pending
fb7435b  bot: proses/done tanpa catatan tetap kirim TRANSAKSI
```
