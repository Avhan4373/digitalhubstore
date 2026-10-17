# Creative Solutions — catatan untuk agen

Aplikasi toko digital (pengganti `digitalhubstore.my.id`).
Laravel 13 · Blade · MySQL 8 · Tailwind v4. **Tanpa Laravel Boost** — jangan
`composer require laravel/boost`.

Dokumentasi utama ada di `README.md`. Analisis situs asal ada di
`../ANALYSIS.md`, scraper di `../scrape.py` dan `../dhs/`.

## Sebelum mengubah kode

- Database: MySQL lewat `docker compose up -d` (container `dhs-mysql`, host port
  **3307**). Setting ada di `.env`.
- `composer install && npm install`, lalu `npm run build` setelah mengubah view
  atau `resources/css/app.css` — Tailwind v4 memindai `resources/views`.
- Setelah mengubah `composer.json` autoload (ada `app/Support/helpers.php` di
  `files`): `composer dump-autoload -o`.

## Konvensi proyek

- **Semua angka uang dihitung di server.** Jangan pernah mempercayai harga,
  potongan, atau total dari request. `OrderService` yang berwenang.
- Teks dari pengguna **selalu** dirender lewat `safe_text()` / `{{ }}`.
  Situs asal punya stored XSS yang benar-benar berjalan di produksi.
- Kredensial akun stok: simpan lewat `StockItem::setPayloadPlain()`, baca lewat
  `payloadPlain()`. Jangan pernah menulis `payload` polos atau menampilkannya
  di UI admin.
- Tombol **"Kirim isi akun via WA"** di admin persis untuk itu: draft dari
  `Order::buyerWhatsAppLink()` (`wa.me` ke nomor pembeli + isi akun yang sudah
  terkirim). Jangan dipakai untuk stok yang belum terkirim — method mengembalikan
  `null` sampai ada `order_deliveries`.
- Isi pesanan (kredensial/`deliveredContents()`) **tidak boleh** ikut dirender
  sebelum invoice ada di `order_unlocked` — pakai `order_access()->isUnlocked()`
  (lihat `App\Services\OrderAccess`). Halaman status pesanan hanya boleh
  menampilkan nama/status/total, dan nomor WhatsApp pembeli selalu lewat
  `mask_wa()` karena nomor itu kunci untuk membuka akun.
- `order_unlocked` berwaktu: checkout hanya `remember()` (status), isi akun butuh
  verifikasi WhatsApp (`orders.unlock` / lacak) dan **terkunci ulang** setelah
  `OrderAccess::UNLOCK_TTL` (10 menit). Jangan simpan isi akun di sesi tanpa
  tenggat, dan jangan kembalikan unlock otomatis saat checkout.
- **Realtime sisi pembeli** (`initOrderLive()` di `app.js`, atribut
  `data-order-live` + `data-live-key` di `order.blade.php`): endpoint
  `orders.status` TIDAK BOLEH membawa data pribadi atau isi akun — hanya
  `payment_status`/`order_status`/`is_paid` plus boolean `punya_isi` (item
  `is_fulfilled` ada) dan `terbuka` (`order_unlocked`) yang mengubah susunan
  halaman. Perubahan apa pun = toast + reload otomatis; jangan patch DOM dengan
  isi JSON (bisa melewatkan blok kunci/terbuka).
- **HTTP 429** dirender lewat `resources/views/errors/429.blade.php` (hitung
  mundur dari header `Retry-After`, bahasa Indonesia) — jangan tambahkan
  `abort(429)` tersendiri untuk penahanan biasa; pakai middleware `throttle:`
  supaya halaman + `Retry-After` selalu konsisten. Paket limit: checkout
  `30,10`, lacak `10,10`, unlock `10,10` (+5/10m controller per pesanan), cek
  transaksi `20,10`, login `10,1`.
- **Tidak ada JS inline di Blade.** CSP `script-src 'self'` memblokir semua
  `onsubmit=`/`onclick=` — konfirmasi aksi destruktif admin memakai atribut
  `data-confirm` yang ditangani listener di `app.js` (`initConfirm()`).
- **Navigasi admin mobile**: sidebar desktop dan drawer mobile WAJIB memakai
  partial yang sama (`admin/_nav-links.blade.php`, `_sidebar-head`,
  `_sidebar-user`) supaya menu tidak pernah berbeda antar breakpoint. Jangan
  menambah tautan langsung di salah satu tempat saja. Drawer dikendalikan
  `initAdminNav()` (`data-admin-drawer`, `data-admin-drawer-backdrop`,
  `data-admin-nav-toggle`, `data-admin-bottomnav`); `main` butuh `pb-24` di
  mobile agar tidak tertutup bottom nav.
- **Merek mengikuti `store_name`**: jangan hardcode nama/logo toko di view
  (dulu `DIGITALHUB` & monogram `DH`). Pakai `Setting::logoMonogram()` untuk
  monogram, `Setting::logoWordmark()` untuk wordmark header, dan
  `Setting::hasCustomLogo()` + `logoUrl()` bila admin mengunggah logo. Kunci
  pengaturan baru **wajib** ditambahkan ke `Setting::kunciBawaan()` (bukan hanya
  ke seeder) supaya panel Pengaturan bisa menambal instalasi lama.
- **Pembatalan pesanan** (`OrderService::cancel()`) hanya untuk pesanan belum
  lunas & belum terkirim; pesanan lunas **ditolak** dan harus lewat refund.
  Setiap tampilan pembayaran WAJIB memakai `Order::bisaDibayar()` (bukan
  `! $order->isPaid()`), supaya pesanan `cancelled`/`expired`/`failed` tidak
  menampilkan tombol bayar, QRIS, hitung mundur, atau polling status.
- **Pesanan batal bukan "belum bayar"**: `payment_status` tetap `pending`,
  jadi setiap hitungan/daftar "belum bayar" WAJIB lewat `Order::unpaid()`
  (sudah mengecualikan `order_status = cancelled`), bukan
  `where('payment_status', 'pending')` manual. Label & badge pembayaran pakai
  `$order->paymentLabel()` / `$order->paymentBadge()`, jangan
  `status_badge($order->payment_status)`. Pesanan batal juga ditolak oleh
  `markPaid()`/`deliver()`; webhook gateway wajib melewati event
  `EXPIRED`/`FAILED` untuk pesanan lunas/terkirim/batal supaya retry tidak 500.
- **Notifikasi pesanan admin** (`GET admin.orders.live` + `initAdminLive()` di
  `app.js`) polling ~8 detik: perbarui badge/pill lewat elemen beratribut
  `data-live-*`, toast + Web Audio hanya untuk pesanan yang belum terlihat
  (baseline dari poll pertama), dan daftar panel **harus** dirender lewat
  `textContent` — tidak boleh `innerHTML` karena berisi nama pembeli.
- Popup "Cek Transaksi" (`components/cek-transaksi.blade.php` + `POST
  /cek-transaksi`) hanya boleh menerima **nomor invoice** (jangan tambahkan
  pencarian lewat nomor WA/email seperti situs asal), selalu `mask_wa()` +
  `mask_email()` identitas pembeli, dan tidak boleh memuat isi akun atau
  menambahkan invoice hasil cek ke `my_orders`/`order_unlocked`.
- Di popup tersebut, klik kartu / "Lihat Invoice" **hanya membuka lembar
  E-Invoice lapis kedua** (`.cek-sheet-modal`, satu per kartu di
  `components/cek-transaksi.blade.php`) tanpa pindah halaman — jangan kembalikan
  perilaku menaut langsung ke `/pesanan/{invoice}`. Anchor tetap dibutuhkan
  sebagai fallback tanpa JS.
- Harga & isi order lama tidak boleh berubah saat admin mengedit produk/varian —
  `order_items` menyimpan salinan nama dan harga.
- Query harus jalan di MySQL **dan** SQLite (test memakai SQLite in-memory).
  Hindari fungsi khusus MySQL seperti `FIELD()`; pakai `CASE WHEN`.
- UI berbahasa Indonesia; istilah teknis (invoice, checkout, voucher) tetap
  bahasa Inggris.
- URL berkas disk `public` selalu lewat `storage_url()`, **jangan**
  `Storage::url()`: yang terakhir memakai `config('app.url')` sehingga gambar
  rusak saat aplikasi dibuka dari domain/port selain `APP_URL`.
- Ikon UI memakai paket `lucide` + atribut `data-lucide` (ditukar oleh
  `createIcons()` di `resources/js/app.js`) — bukan komponen Blade, supaya
  markup sama persis dengan situs asal dan CSP tetap `script-src 'self'`
  (tidak ada JS inline).
- Label & urutan menu kategori **bukan** `categories.name`: alias disalin dari
  menu situs asal di `resources/views/components/category-menu.blade.php`
  (`Editor` ditampilkan "App Pro", `meeting` → "Jasa Web", dst.).
- Data wajib pembeli (`variants.required_fields` → `order_items.required_info`)
  selalu dari daftar label di server; nilai dari form hanya dipetakan
  berdasarkan **urutan**. Jangan pernah memakai label kiriman klien.
- Modal supplier (`cost_price`) dan referensi supplier disalin ke `order_items`
  saat checkout — margin pesanan lama tidak boleh berubah saat varian diedit.

## Perintah yang sering dipakai

```bash
php artisan test                       # 76 test, SQLite in-memory
php artisan migrate:fresh --seed       # skema + katalog dari ../data/seed
php artisan route:list --except-vendor
npm run build                          # WAJIB setelah ubah view/CSS
php artisan schedule:work              # penanda order kedaluwarsa
```

## Perangkap: `config:cache` vs test

`php artisan config:cache` membuat `bootstrap/cache/config.php`. Selama file itu
ada, Laravel **tidak membaca `.env` sama sekali** dan `<env>` di `phpunit.xml`
juga diabaikan.

Akibatnya `php artisan test` bisa berjalan di **MySQL pengembangan** dan
`RefreshDatabase` mengosongkan data dev. Hal ini sudah dijaga: `tests/TestCase.php`
gagal keras dengan pesan jelas kalau koneksi bukan SQLite. Kalau muncul error itu:

```bash
php artisan optimize:clear   # lalu ulangi `php artisan test`
```

## Perangkap: `public/build` tidak ikut `git push`

`public/build` ada di `.gitignore`, dan `deploy.sh` di VPS hanya menjalankan
`git reset --hard`. Jadi perubahan `resources/js` / `resources/css` **tidak akan
sampai ke produksi** hanya dengan commit + push + deploy.sh.

Selalu deploy lewat:

```bash
cd store-app && ./deploy-local.sh
```

Skrip itu build → push → tarik kode → rsync `public/build` → reload. Kalau
manual, rsync-nya wajib:

```bash
rsync -az --delete public/build/ -e "ssh -i ~/.ssh/biznetgio" \
    avhanvps@103.127.97.249:/var/www/digitalhubstore/store-app/public/build/
```

Cek cepat: nama `app-*.js` di `public/build/manifest.json` lokal harus sama
dengan yang ada di VPS.

## Perangkap: 301 tersimpan permanen di browser

301 (`Moved Permanently`) di-cache browser **tanpa masa berlaku**. Kalau sebuah
URL pernah di-301 ke tempat lain, memperbaiki konfigurasi server tidak langsung
memulihkan pengunjung yang sudah pernah terkena — browser tidak akan bertanya
lagi ke server.

Karena itu, hindari 301 untuk URL yang mungkin perlu diubah perilakunya.
Halaman pemulihan `/login.html` sengaja disajikan sebagai `200` + `Clear-Site-Data`,
bukan redirect, dan `app.js` memulangkan pengunjung yang terlanjur mendarat di
`/login` karena dilempar aplikasi lama (dikenali dari `document.referrer`).

## Jangan

- Mengaktifkan kembali endpoint tanpa auth (order/stats/katalog publik).
- Memakai `env()` di luar `config/` — pakai `config('catalog.seed_dir')` dst.
- Menambah `@apply` untuk class custom di Tailwind v4 (gagal build); pakai
  selector gabungan atau `@utility`.
