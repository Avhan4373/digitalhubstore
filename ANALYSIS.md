# ANALYSIS — DigitalHub Store (digitalhubstore.my.id)

Log analisis permanen. Diperbarui setiap ada temuan baru.

Target: `https://digitalhubstore.my.id`
Tanggal analisis: 2026-10-17
Status: **katalog publik berhasil di-scrape** (29 produk / 59 varian / 40 ulasan)

---

## 1. Arsitektur

```
                     ┌──────────────────────────────────────┐
  Pembeli ───HTTPS──▶│ GitHub Pages  (SPA statis)           │
                     │  index.html  ~189 KB, 1 file         │
                     │  /admin      ~122 KB, 1 file         │
                     │  manifest.json (PWA)                 │
                     │  Tailwind CDN, lucide, tesseract.js, │
                     │  html2pdf.js                          │
                     └───────────────┬──────────────────────┘
                                     │ fetch() JSON, CORS *
                                     ▼
                     ┌──────────────────────────────────────┐
                     │ Cloudflare Worker                    │
                     │ toko-digital-api.rizqinastore97      │
                     │  .workers.dev                        │
                     │  └─ Cloudflare KV (storage)          │
                     └──────────────────────────────────────┘
```

- **Tidak ada backend tradisional.** Tidak ada WordPress/Laravel/Next.js —
  hanya file statis + satu Worker.
- **Tidak ada HTML server-side.** Semua render di browser dari JSON API.
- **`robots.txt` dan `sitemap.xml` tidak ada** (404 dari GitHub Pages).
- **URL API di-obfuscate base64** (`_wEnc` + `atob()`), trik yang sama dipakai
  di `index.html` dan `admin`. Ini bukan pengamanan, cuma menyulitkan grep.
- **Gambar produk mayoritas data-URI base64** yang disimpan di KV — bukan file
  terpisah. Ini sebabnya respons katalog berukuran ~7,9 MB untuk 29 produk.
  Sebagian kecil produk (6 buah) memakai URL eksternal (`i.ibb.co.com`,
  `cdn.lynkid.my.id`, `shop.activeitfirm.com`, `img.susercontent.com`).

## 2. Layer / kontrol yang ada

| Layer | Ada? | Catatan |
|---|---|---|
| Auth API | ❌ | Semua endpoint publik, tanpa token/API key |
| Auth admin | ❌ | `/admin` langsung bisa dipakai; tidak ada gate password |
| Rate limit | ❌ | Tidak terdeteksi |
| Signing request | ❌ | Header cuma `Content-Type` |
| Enkripsi payload | ❌ | JSON polos |
| Obfuscation ringan | ✅ | `_wEnc` base64 pada URL API (bukan proteksi) |
| CORS | Terbuka | `Access-Control-Allow-Origin: *` |

## 3. Endpoint (hasil enumerasi dari bundle `index.html` + `admin`)

| Endpoint | Metode | Fungsi | Sensitif? |
|---|---|---|---|
| `/` | GET | katalog produk + konfigurasi | tidak |
| `/` | POST | **timpa seluruh katalog di KV** | 🔴 kritis |
| `/reviews?productId=` | GET | ulasan produk | tidak |
| `/reviews` | POST | kirim ulasan | sedang (spam) |
| `/check-coupon` | POST | validasi voucher | tidak |
| `/create-tripay-tx` | POST | buat transaksi Tripay | sedang |
| `/check-status` | POST | polling status pembayaran | sedang |
| `/claim-promo` | POST | klaim promo Rp 0 | sedang |
| `/orders` | POST | buat order | sedang |
| `/orders?wa=` | GET | **riwayat order berdasarkan nomor WA** | 🔴 kritis |
| `/orders` | GET | **semua order, tanpa auth** | 🔴 kritis |
| `/orders/confirm-all` | POST | konfirmasi semua order | 🔴 kritis |
| `/orders/update-status` | POST | ubah status order | 🔴 kritis |
| `/orders/delete` | POST | hapus order | 🔴 kritis |
| `/stats` | GET | **IP + hits + history pengunjung** | 🔴 kritis |
| `/track` | POST | telemetri kunjungan | tidak |

### 3.1 Temuan kritis (pengujian terbatas pada GET read-only)

Diverifikasi hanya dengan `GET` — **tidak ada satu pun request tulis yang
dikirim**, dan isi data pribadi tidak disimpan:

1. **`GET /orders` tanpa autentikasi** mengembalikan **150 order lengkap**:
   `reference`, `date`, `productName`, `variantName`, `totalAmount`,
   `buyerName`, `buyerEmail`, `buyerWA`, `status`.
   → kebocoran data pribadi pembeli (nama, email, nomor WhatsApp).
2. **`GET /stats` tanpa autentikasi** mengembalikan analytics per-IP:
   `ips`, `views`, `clicks`, `history` — ratusan entri, termasuk IPv4 & IPv6
   pengunjung beserta timestamp.
3. **`POST /` tanpa autentikasi** menimpa **seluruh katalog** (produk, harga,
   stok, banner, promo) di Cloudflare KV. Siapa pun bisa mengubah harga jadi
   Rp 1 atau menghapus semua produk.
4. **`/orders/delete`, `/orders/update-status`, `/orders/confirm-all`** juga
   terbuka — siapa pun bisa menghapus/mengubah order.
5. **`GET /orders?wa=<nomor>`** mengembalikan riwayat order orang lain hanya
   dengan menebak nomor WhatsApp-nya.
6. **Stored XSS sudah terbukti ada di produksi** — di data ulasan Gemini Pro
   ada entri uji coba milik pihak lain:
   `"ADMIN TAKEOVER — stored XSS + spam proven"` dan `<script>alert(1)</script>`.
   Artinya endpoint `POST /reviews` menyimpan HTML mentah dan admin panel
   me-render-nya tanpa escape.
7. **`POST /reviews` tanpa validasi** → spam ulasan bebas.

> Catatan etika: endpoint di atas **tidak** di-crawl. `scrape.py` hanya
> menyimpan skema (nama kolom), bukan nilainya. Lihat
> `scrape.py:probe_sensitive`.

## 4. Model data katalog (skema hasil reverse)

**31 entri** di `GET /` = 2 objek konfigurasi + 29 produk.

```
config
 ├─ __BANNER_CONFIG__  { active, type, imageUrl, theme,
 │                       showTemplateWeb, showJasaWeb, paymentMode }
 └─ __PROMO0_CONFIG__  { active, title, badge, image, desc, accountData,
                         expiresAt, promoPrice, originalPrice }

product
 ├─ id            int (timestamp ms)
 ├─ name, category, warranty, description, terms
 ├─ paymentType   "default" | lainnya
 ├─ image         data-URI base64 ATAU URL
 ├─ images[]      galeri
 ├─ isBestSeller, isHot, hidden: bool
 ├─ sold          int (counter klaim, bukan angka riil)
 ├─ rating        string "5"
 ├─ customReviews[]  ulasan yang di-hardcode penjual
 └─ variants[]
      ├─ name, desc, terms, paymentType
      ├─ price, originalPrice: int (Rupiah)
      ├─ stock: int
      └─ items[]  slot stok — umumnya teks instruksi, bukan akun
```

### Distribusi katalog saat ini

| Kategori | Produk | Total `sold` |
|---|---|---|
| AI | 10 | 58.211 |
| Stream | 8 | 31.250 |
| Editor | 7 | 67.101 |
| Landing | 2 | 8.000 |
| meeting | 1 | 760 |
| Template Web | 1 | 500 |

- Rentang harga: **Rp 2.000 – Rp 399.000**
- Varian terbanyak: `Vidio Premium` (5), `Gemini Pro` (6), `ChatGPT GO` (3)
- Rata-rata rating ulasan: **4,75** dari 40 ulasan (33 di antaranya di Gemini Pro,
  dan yang rating rendah justru mengeluh akun mati sebelum masa aktif habis)

### Anomali data

- **Stok palsu/bloat**: `Capcut PRO` → varian `1 Bulan Privat` punya 8.400 slot
  stok berisi teks identik `[ 001 ] … [ 400 ] Silakan chat WhatsApp admin…`.
  Total slot di produk itu 33.600. Jadi kolom `stock` tidak mencerminkan
  inventaris nyata.
- **Kredensial asli bocor di stok**: produk `Alight Motion 1 Tahun` berisi
  entri stok berbentuk `email|password` (mis. `katano7@gmail-xsniper.site|`).
  → risiko tinggi kalau KV bocor; export sudah menyediakan `--mask-items`.
- **`sold` tidak konsisten**: `Canva Pro` 30.000 dengan stok 10.800; `Capcut`
  10.001 dengan 33.600. Kemungkinan angka marketing.
- **`rating` selalu string** (`"5"`), bukan number → bug tipe di frontend.
- **`customReviews` vs `/reviews`**: sebagian ulasan di-hardcode di produk,
  sebagian dari API. Harus digabung untuk tampilan yang utuh.

## 5. Alur aplikasi (dari bundle JS)

```
loadProducts()  ──GET /──▶ render kategori, banner, promo, kartu produk
   │
   ├─ klik produk ──▶ modal detail + varian + ulasan (GET /reviews?productId=)
   │
   └─ checkout ──▶ pilih varian/qty
        ├─ voucher  ──POST /check-coupon──▶ diskon
        ├─ mode gateway ─POST /create-tripay-tx──▶ QRIS/VA
        │      └─ polling 5–10 s ─POST /check-status──▶ lunas?
        └─ mode manual ─▶ tampil QRIS statis ─▶ user kirim bukti via WA
                 │
                 ├─ POST /orders           (simpan order)
                 ├─ POST /claim-promo      (promo Rp 0)
                 └─ ambil akun ─▶ tampil `items` varian + generate invoice PDF
                                 (html2pdf.js) + tombol kirim ke WhatsApp
```

- Pembayaran: **Tripay** (gateway) **atau** QRIS manual + verifikasi admin.
- Nomor WA toko di-hardcode: `6282243890517` / `082243890517`.
- Riwayat order pembeli disimpan **di `localStorage` browser** (`my_digital_orders`)
  — hilang kalau cache dibersihkan; itulah kenapa ada pencarian riwayat via WA.
- OCR (`tesseract.js`) dipakai frontend untuk membaca bukti transfer.
- `trackActivity()` → `POST /track` pada setiap kunjungan.

## 6. Aplikasi pengganti (fase 2) — SUDAH DIBANGUN

Tujuan: fungsionalitas sama, tapi benar secara arsitektur dan lebih baik.
**Status: terimplementasi** di `store-app/` (Laravel 13 + Blade + MySQL 8 +
Tailwind v4). Dokumentasi cara jalan & panel admin ada di `store-app/README.md`.

### Masalah utama yang wajib diperbaiki

| # | Masalah di situs asal | Perbaikan |
|---|---|---|
| 1 | Semua endpoint terbuka | Auth: JWT/session + role (admin, kasir, pembeli) |
| 2 | `GET /orders` bocor PII | Endpoint per-user; admin pakai token + audit log |
| 3 | `POST /` timpa katalog | Endpoint per-resource (`PATCH /products/:id`), validasi schema |
| 4 | XSS di ulasan | Escape/sanitasi + CSP, moderasi ulasan |
| 5 | Katalog 7,9 MB (base64 di JSON) | Gambar ke object storage (R2/S3) + CDN; API pakai URL |
| 6 | Riwayat order di `localStorage` | Riwayat server-side dengan login/nomor invoice |
| 7 | Stok = counter palsu | Inventaris nyata: stok diambil otomatis dari kredensial terenkripsi |
| 8 | Kredensial disimpan mentah di KV | Enkripsi at-rest + audit siapa mengambil apa |
| 9 | Klaim akun lewat chat WA manual | Pengiriman otomatis setelah pembayaran terverifikasi webhook |
| 10 | Tidak ada `robots.txt`/sitemap/SEO | Metadata produk, sitemap, structured data |
| 11 | Tidak ada rate limit | Rate limit + captcha pada review/order |
| 12 | Tanpa backup/versioning | Snapshot produk + soft delete |

### Fitur tambahan yang "lebih baik"

- Manajemen pesanan dengan status jelas (pending → dibayar → terkirim → garansi)
- Garansi terstruktur: klaim ganti akun otomatis, SLA + notifikasi
- Webhook Tripay/Midtrans asli (bukan polling) → pengiriman instan
- Dashboard admin: penjualan, produk terlaris, konversi, stok kritis
- Multi-varian dengan harga bertingkat & kupon bertanggal
- Notifikasi WhatsApp/email otomatis (invoice + akun + reminder garansi)
- Katalog terpisah dari data sensitif: endpoint publik hanya metadata
- Alur reseller manual: referensi produk supplier + harga modal per varian,
  data wajib pembeli, dan margin terlihat di detail pesanan admin

### Keputusan yang diambil

| Pertanyaan | Keputusan |
|---|---|
| Stack | **Laravel 13 + Blade (tanpa Livewire/Inertia) + MySQL 8 + Vite/Tailwind v4** |
| Hosting | VPS dengan MySQL 8; `docker-compose.yml` untuk dev. Cloudflare/D1 ditolak karena butuh transaksi & row lock |
| Data | Hasil scrape dipakai untuk seed produk/varian/ulasan asli lewat `tools/build_seed.py` → `data/seed/` |
| Legal | Kredensial akun pihak ketiga **tidak** diimpor (148 entri dibuang); katalog dipertahankan |

### Status perbaikan (lihat tabel masalah di atas)

| # | Perbaikan | Wujud di kode |
|---|---|---|
| 1 | Auth + role | `Auth\LoginController`, middleware `auth`+`admin`, role admin/staff, gate `manage-users` & `manage-settings` |
| 2 | Pesanan tidak bocor | `OrderController::findAccessible()` — order hanya terbuka lewat sesi pemilik; tidak ada endpoint daftar order publik |
| 3 | Katalog tidak bisa ditimpa | CRUD per-resource di `/admin` + `ProductController` dengan validasi |
| 4 | XSS | `safe_text()` di semua render teks pengguna + `SecurityHeaders` (CSP); ulasan masuk moderasi |
| 5 | Gambar bukan base64 | Gambar di-ekstrak saat seed ke `storage/app/public/products` dan disajikan sebagai URL |
| 6 | Riwayat order | Disimpan di server; pembeli menemukannya lewat `/lacak` (invoice + WhatsApp) |
| 7 | Inventaris nyata | Tabel `stock_items`; stok `available` → `reserved` → `delivered` |
| 8 | Kredensial terenkripsi | `Crypt` (AES-256, APP_KEY); `StockItem::setPayloadPlain()` / `payloadPlain()` |
| 9 | Pengiriman otomatis | `DeliveryService::attemptFulfill()` dipicu `markPaid()` |
| 10 | SEO | `meta_title`/`meta_description` per produk, `robots.txt` |
| 11 | Rate limit | `throttle` di checkout, lacak, ulasan, login; honeypot di checkout |
| 12 | Soft delete | `SoftDeletes` di `products` & `orders`; isi order lama disalin ke `order_items` |

Tambahan yang ikut dibangun: webhook Tripay dengan verifikasi HMAC (bukan
polling), dashboard penjualan/grafik/produk terlaris, log aktivitas admin,
voucher dengan kuota & batas per pembeli, penjadwalan order kedaluwarsa,
alur reseller manual (referensi supplier + harga modal + data wajib pembeli),
dan 44 feature test (`php artisan test`).

Yang **belum** dikerjakan: notifikasi WhatsApp/email otomatis dan klaim garansi
terstruktur — keduanya butuh kredensial pihak ketiga yang belum diberikan.

## 7. Integrasi pihak ketiga: itemku

Pertanyaan "bisa dihubungkan dengan itemku?" dijawab dengan uji langsung.
**Itemku hanya bisa dipakai sebagai kanal jual (arah itemku → aplikasi), bukan
sebagai sumber katalog.**

| Arah | Status | Bukti |
|---|---|---|
| Aplikasi sebagai seller di itemku (itemku kirim pesanan, aplikasi kirim barang) | **Didukung** — Tokoku Open API | `POST /api/order/list`, `/order/action`, `/product/list`, `/price/update`, `/stock/update`; auth `X-Api-Key` + JWT HS256 dengan `Nonce`; callback POST ke URL seller |
| Itemku sebagai supplier katalog untuk toko sendiri | **Tidak didukung** | `POST /api/product/list` (resmi) hanya mengembalikan produk milik akun sendiri; `POST https://api-gateway.itemku.com/v1/product` → `403` Cloudflare (`Just a moment...`); tidak ada endpoint "beli" |
| Otomatisasi pembelian di itemku | Melanggar ToS + rapuh | butuh otomatisasi alur pembeli (login → checkout → bayar); Cloudflare aktif; risiko ban akun & saldo hangus |

Dokumentasi resmi: `https://files.itemku.com/document/Tokoku+API+Docs.pdf`
(ditautkan tim itemku di bug tracker `FIVEJACK/itemku-seller-open-api`).
Syarat operasional: akun seller + verifikasi, IP statis di-whitelist, callback
HTTPS publik dengan record DNS TXT `v-api-itemku-{SHOPNAME}=1`, retry 3×
(5s/30s/2min) sehingga idempotensi per `order_id` wajib, nonce harus sinkron
dengan jam server, dan produk auto-delivery tidak bisa diubah stoknya via API.

**Keputusan:** tidak membangun integrasi API itemku. Yang dibangun adalah alur
**reseller manual** — referensi produk supplier (nama/SKU/tautan), harga modal
per varian, dan daftar data wajib pembeli (mis. `Player ID`, `Zone ID`,
`Email Canva`) yang tampil di detail pesanan admin bersama margin dan tombol
salin. Untuk pengiriman otomatis yang resmi, jalurnya adalah supplier H2H
(DigiFlazz/TokoVoucher/H2H.id) yang memang punya API berdokumen.

## 8. Changelog

| Tanggal | Perubahan |
|---|---|
| 2026-10-17 | Recon: identifikasi platform (GitHub Pages + Cloudflare Worker/KV), decode `_wEnc`, enumerasi 16 endpoint, temuan 3 isu kritis (PII order, IP stats, timpa katalog), stored XSS di produksi. Scraper `scrape.py` + `dhs/` dibuat; hasil 29 produk / 59 varian / 40 ulasan / 29 gambar. |
| 2026-10-17 | `tools/build_seed.py`: export → `data/seed/{categories,products,variants,reviews}.json` (6/29/59/40, 0 gambar hilang, 148 kredensial dibuang). |
| 2026-10-17 | Aplikasi pengganti dibangun di `store-app/`: Laravel 13 + Blade + MySQL 8 + Tailwind v4. 13 migrasi, 13 model, 5 service, 17 controller, 18 view, 58 route. |
| 2026-10-17 | Perbaikan keamanan terealisasi: order per-sesi (bukan publik), harga/voucher/stok dihitung ulang di server, kredensial stok terenkripsi `Crypt`, ulasan di-escape + moderasi, webhook Tripay dengan verifikasi HMAC, panel admin berpassword dengan role admin/staff, rate limit + honeypot, log aktivitas. |
| 2026-10-17 | Verifikasi: `php artisan migrate:fresh --seed` bersih (6/29/59/40), `npm run build` OK, 38 feature test lulus (157 asersi), uji HTTP nyata untuk katalog → checkout → bayar → konfirmasi admin → pengiriman otomatis/manual → ulasan, plus uji XSS live (`<script>` tampil sebagai teks). |
| 2026-10-17 | Perbaikan data: `variants.external_id` ditambahkan sebagai kunci idempoten seeder. Sebelumnya kunci `(produk, nama)` membuat dua varian asli ber-nama sama (Vidio Premium "1 Bulan Share Platinum Mobile Only" Rp 28.000 vs Rp 18.000) saling menimpa — varian sekarang lengkap 59. |
| 2026-10-17 | Perbaikan proses: `tests/TestCase.php` menolak jalan kalau koneksi DB bukan SQLite. `php artisan config:cache` sempat membuat test memakai MySQL dan `RefreshDatabase` mengosongkan data pengembangan — sekarang gagal dengan pesan yang jelas. |
| 2026-10-17 | Riset integrasi itemku (uji endpoint + dokumen resmi): API resmi hanya untuk arah seller; API katalog front-end diblok Cloudflare (`403`); tidak ada API pembelian. Dicatat di bagian 7, keputusan tidak membangun integrasi API. |
| 2026-10-17 | Alur reseller manual dibangun: migrasi `add_supplier_and_required_fields` (varian: `supplier_name`/`supplier_sku`/`supplier_url`/`cost_price`/`required_fields`; order item: salinan + `required_info`). Checkout mengumpulkan data wajib pembeli dengan label dari server, admin melihat kotak "Beli ke supplier" + data pembeli (tombol salin) + modal/margin per item dan per pesanan. 6 test baru → **44 test, 187 asersi lulus**; verifikasi HTTP nyata untuk halaman produk, checkout, detail pesanan admin, halaman pembeli, dan form varian admin. |
| 2026-10-17 | **Frontend disamakan dengan situs asal.** DOM hasil render situs asal diambil pakai browser sungguhan (CDP) dan disimpan sebagai acuan di `/tmp/opencode/dom/`. Token desain dipakai apa adanya: font `Outfit`, brand `#f5125e`, gradient `#f5125e → #ff2b56`, body `bg-slate-50 text-slate-800 antialiased pb-20 md:pb-10`, header `sticky bg-white/95 backdrop-blur-md border-b border-rose-100/80`, grid katalog `grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6`, menu kategori `grid-cols-4 md:grid-cols-7`. Ikon memakai paket `lucide` + atribut `data-lucide` (mekanisme yang sama dengan situs asal) sehingga `createIcons()` menukarnya jadi SVG inline tanpa HTML inline (CSP `script-src 'self'` tetap). Halaman depan kini meniru SPA asal: trust bar 3 kolom, banner gradien, menu kategori berikon, blok "Produk Terlaris & Paling Diminati", grid katalog + pilihan urut, FAQ accordion, dan notifikasi pembelian terbaru (dari pesanan nyata, nama disamarkan). Halaman detail produk mengikuti modal detail asal (slider + chip varian + bar aksi sticky), keranjang mengikuti cart drawer, checkout mengikuti modal "Kasir DigitalHub", halaman bayar/pesanan mengikuti kartu hasil + kotak akses produk. Logo asli dibundel di `store-app/public/images/logo.png` (setting `store_logo`/`store_banner` baru untuk override dari admin). 44 test / 187 asersi tetap lulus. |
| 2026-10-17 | **Penyetaraan rinci + audit kelas CSS.** Setiap komponen dibandingkan otomatis dengan markup asli (skrip pembanding kelas per node): kartu popular, kartu grid katalog, footer, dan FAB **identik 0 beda**; header, trust bar, menu kategori, dan bottom nav sama kecuali tag (`<a>` vs `<div onclick>`) dan ikon (`<i data-lucide>` vs `<svg>` hasil render). Perbaikan yang menyusul: (1) banner gradien tiruan dihapus — banner hanya tampil kalau `store_banner` diisi, sama seperti situs asal; (2) label & urutan menu kategori disamakan (`AI`→"Akun AI", `Editor`→"App Pro", `Landing`→"Landing Page", `Stream`→"Streaming", `meeting`→"Jasa Web" dengan ikon `monitor` ungu) — di situs asal `data-cat="Jasa Web"` bahkan tidak cocok dengan data (`kategori=meeting`), jadi label itu dipetakan ke kategori yang benar supaya tombolnya tidak mati; (3) pilihan urutan jadi 5 opsi seperti asal (`sesuai` = urutan katalog apa adanya, subquery harga dihindari) dan `terbaru` tetap diterima lewat URL; (4) tombol bantuan melayang + bubble "Butuh bantuan? Chat kami" ditambahkan; (5) tombol kamera di kolom pencarian ditambahkan (diarahkan ke Chat CS, bukan tombol mati). **Bug diperbaiki:** semua URL berkas disk `public` memakai `Storage::url()` yang selalu memakai `config('app.url')` → gambar produk rusak total saat aplikasi dibuka dari port/host selain `APP_URL`; diganti helper `storage_url()` (`asset('storage/...')`) di model Product/ProductImage/Review/Setting + form pengaturan admin. 44 test / 187 asersi lulus; halaman depan, detail produk, keranjang, checkout, bayar, pesanan, invoice, dan lacak diverifikasi visual di browser (desktop 1440px + mobile 390px). |
| 2026-10-17 | **Isi pesanan dikunci (temuan pengguna).** Halaman `/pesanan/{invoice}` sebelumnya langsung menampilkan kredensial akun ("Isi Pesanan Anda: Testing \| Password") kepada siapa pun yang memakai browser itu — cukup berbahaya di komputer warnet/HP keluarga yang dipakai bergantian, apalagi nomor WhatsApp pembeli ikut tampil utuh sehingga bisa dipakai untuk membuka pesanan lain. Akses kini dipisah dua tingkat oleh `App\Services\OrderAccess`: `my_orders` (boleh lihat status) dan `order_unlocked` (boleh lihat isi akun). Isi akun **tidak pernah dikirim ke browser** sebelum invoice ada di `order_unlocked`, yang hanya bisa didapat dari (1) checkout di browser itu sendiri, (2) `/lacak` yang cocok invoice+WhatsApp, atau (3) `POST /pesanan/{inv}/buka` dengan nomor WhatsApp pembeli — dibatasi 5 percobaan/10 menit per IP dan dicatat di activity log (`order.unlock`, `order.unlock.rejected`). Nomor WhatsApp pembeli selalu disamarkan lewat helper baru `mask_wa()` (`+62812••••890`) di halaman pesanan dan invoice; admin tetap melihat nomor lengkap karena dipakai menghubungi pembeli. 6 test baru (kredensial tidak ada di HTML sebelum diverifikasi, nomor WA tersamar, unlock nomor salah/benar, lacak sekaligus unlock, pembatasan percobaan, unlock tanpa invoice di session) → **50 test, 215 asersi lulus**; alur terkunci → nomor salah → nomor benar diverifikasi di browser sungguhan. |
| 2026-10-17 | **Popup "Cek Transaksi" disamakan + celah baru divalidasi (permintaan pengguna).** Markup popup situs asal dipanen ulang: `openHistoryModal()` (trust bar "Cek Transaksi", header "Riwayat Pesanan", bottom nav "Pesanan" — ketiganya memanggil modal yang sama), `history-modal` (form cari + tab Semua/Menunggu-Proses/Selesai + kartu pesanan gaya Shopee) dan `invoice-preview-modal` ("Lembar Invoice Pembelian" berisi header DIGITALHUB merah, No. Invoice, tanggal, metode, status `SUKSES / LUNAS`, kartu Data Pembeli, tabel Produk/Total, catatan garansi) — disimpan di `/tmp/opencode/dom/{history_modal,history_list_result,invoice_preview_modal}.html`. **Temuan saat panen:** kartu hasil pencarian membawa seluruh data pesanan sebagai base64 di `onclick="viewInvoiceFromData('...')"` (termasuk `buyerEmail`, `buyerWA`, dan `account` berisi isi akun), dan pencarian itu sendiri hanya butuh nomor WhatsApp — jadi siapa pun yang tahu nomor WA orang lain dapat nomor invoice + akunnya. Aplikasi pengganti membangun popup yang sama tampilannya (`components/cek-transaksi.blade.php` + `components/cek-einvoice.blade.php` + `components/order-history-card.blade.php`, dua lapis seperti asal) tetapi: (1) `POST /cek-transaksi` **hanya** menerima nomor invoice, dibatasi `RateLimiter` 8 percobaan/5 menit per IP + `throttle:20,10`; (2) tidak ada data pesanan yang ditanam di HTML — lembar dirender server dari sesi flash; (3) email & nomor WhatsApp pembeli disamarkan (helper baru `mask_email()`, `mask_wa()` sudah ada) karena nomor WA adalah kunci membuka akun; (4) **isi akun tidak pernah dirender** di popup dan hasil cek TIDAK menambahkan invoice ke `my_orders`/`order_unlocked` (tetap lewat checkout / `lacak` invoice+WA / `POST /pesanan/{inv}/buka`); (5) tab status menyaring kartu di sisi klien, daftar awal = pesanan yang memang tersimpan di sesi browser (kartunya menaut ke halaman pesanan/invoice, bukan lembar publik); (6) tombol "Unduh PDF" mencetak hanya lembarnya lewat `body[data-print-scope="sheet"]` di `app.css`, bukan seluruh halaman. 9 test baru (lembar tampil, tidak ada isi akun, tidak membuka akses, kontak tersamar, invoice tak dikenal, validasi, pembatasan percobaan, popup tersedia di storefront, daftar pesanan perangkat) → **59 test, 255 asersi lulus**; alur popup → cari `INV261017ZO15W` → lembar `SUKSES / LUNAS` → filter tab → invoice salah ditolak → popup versi tamu (kosong) diverifikasi di browser sungguhan (desktop 1440px + mobile 390px). |
| 2026-10-17 | **Pembelian terhalang form ulasan (laporan pengguna).** Di halaman detail produk, `<form>` keranjang membungkus seluruh kartu termasuk **form ulasan yang bersarang di dalamnya** — HTML tidak memperkenankan form di dalam form: parser browser mengabaikan tag `<form>` ulasan, tetapi penutup `</form>`-nya menutup form keranjang lebih awal, sehingga field wajib ulasan (nama, **rating**, invoice, komentar) ikut menjadi bagian form pembelian dan stepper jumlah + tombol "+ Keranjang"/"Beli Sekarang" terlempar keluar form; setiap tombol beli langsung dituntut mengisi rating dulu ("pelanggan belum pesan tapi disuruh rating"). Perbaikan: (1) form keranjang (`id="form-beli"`) ditutup sebelum blok ulasan sehingga keduanya sibling; kontrol bar aksi tetap terhubung lewat atribut `form="form-beli"` (input qty + dua tombol submit) yang valid HTML5 dan tetap bekerja tanpa JS; (2) `initVariantPicker()` memakai root `[data-picker-root]` (kartu) untuk mencari output harga/stepper di luar `<form>` — sekaligus memperbaiki bug laten: harga di blok identitas tidak pernah berubah saat varian dipilih karena `data-price-output` di luar blok pencarian lama; (3) `canReview()` diperketat — form ulasan hanya tampil untuk pembeli produk itu sendiri (ada `order_items` berisi produk ini, invoice di session, dan order belum mengulas), bukan untuk siapa pun dengan pesanan lain di session. 2 test baru (bukan-sarang form + bar aksi terhubung `form="form-beli"`; form ulasan hanya tampil bagi pembeli produk tsb, tamu tidak) → **61 test, 270 asersi lulus**; diverifikasi di browser: klik "Beli Sekarang" langsung ke `/checkout` (cart "YouTube Premium × 1"), form ulasan muncul hanya di halaman Canva sebagai pembeli produk tsb, harga header ikut variasi varian (Rp 5.000 → Rp 8.000). |
| 2026-10-17 | **Klik kartu popup "Cek Transaksi" tidak lagi memindahkan halaman (laporan pengguna).** Di daftar wastafel (pesanan tersimpan di sesi), kartu dan tombol "Lihat Invoice" menaut langsung ke `/pesanan/{invoice}` — padahal situs asal membuka "Lembar Invoice Pembelian" di lapis kedua (`viewInvoiceFromData()`). Perbaikan: setiap kartu kini punya lembar E-Invoice miliknya sendiri (`invoice-preview-modal-{n}`, dirender server dari sesi/flash — tanpa data base64 di HTML, tanpa isi akun, kontak tetap `mask_email()`/`mask_wa()`), dan klik kartu/"Lihat Invoice" membuka lembar itu tanpa mengubah halaman; anchor tetap menaut ke `/pesanan/`/`/pesanan/{inv}/invoice` sebagai fallback tanpa JS. Komponen `order-history-card` diberi prop `sheetId` (prop `sheet` dihapus), CSS print scope (`body[data-print-scope="sheet"]`) beralih dari id ke class `.cek-sheet-modal`/`.cek-sheet-content` supaya "Unduh PDF" tiap lembar tetap hanya mencetak lembarnya, dan pesanan hasil pencarian tetap auto-open lembar via `data-auto-open`. Verifikasi browser: klik "Lihat Invoice" → lembar `INV261017ZC5MU` terbuka (URL tetap `/`), kredensial `RahasiaTerlindung123` tidak ada, tutup lembar kembali ke popup, cari invoice → popup + lembar auto-open. Test `test_popup_menampilkan_pesanan_di_perangkat_ini` diubah ke perilaku baru (+2 asersi kredensial tidak bocor) → **61 test, 272 asersi lulus**. |
| 2026-10-17 | **Kirim isi akun langsung ke WhatsApp pembeli (pilihan pengguna).** Sebelumnya admin mengcopy isi akun dari panel lalu memasangkannya sendiri ke chat pembeli. Sekarang halaman pesanan admin punya tombol **"Kirim isi akun via WA"** (`Order::buyerWhatsAppLink()`): membuka `wa.me/{wa pembeli}` (dinormalisasi `wa_normalize()`) dengan draft siap kirim — sapaan + nama pembeli, No. Invoice, daftar `qty × produk (varian)`, blok **"Isi Pesanan Anda:"** berisi kredensial terenkripsi yang didekripsi `OrderItem::deliveredContents()`, dan ucapan terima kasih dengan nama toko. Tombol hanya muncul (`link` bukan `null`) kalau nomor WA ada **dan** sudah ada `order_deliveries`; pesanan belum terkirim tetap meminimalkan langkah salah kirim. Draft di-build di server (angka & isi dari DB, bukan kiriman klien). 1 test baru (tombol tidak muncul sebelum terkirim + draft benar sesudahnya, dan tautan sama ada di halaman admin) → **62 test, 284 asersi lulus**; diverifikasi di browser panel admin untuk pesanan `INV261017ZC5MU`: tombol terlihat, `href` berisi `wa.me/62…` dengan draft lengkap (blok "Isi Pesanan Anda:" memuat kredensial) — screenshot `/tmp/opencode/shot/app-admin-kirim-wa.png`. |
| 2026-10-17 | **Isi akun bocor di browser yang dipakai bergantian (laporan pengguna).** Tamu murni (`/pesanan/INV2610176S12V` tanpa session) diblokir benar (404), tetapi skenario browser warnet/bergantian bocor: `CheckoutController::store()` melempar `$this->access->unlock($order->invoice_no)` — checkout langsung memasukkan invoice ke `order_unlocked` **tanpa batas waktu**, jadi siapa pun yang memakai browser itu setelah pembeli cukup membuka `/pesanan/{inv}` untuk melihat kredensial yang admin terkirim, tanpa verifikasi WhatsApp apa pun (pelanggaran langsung terhadap larangan "isi akun tidak boleh tampil sebelum verifikasi nomor WA"). Perbaikan dua lapis di `App\Services\OrderAccess` + `CheckoutController`: (1) checkout hanya `remember()` (halaman status), isi akun butuh verifikasi WhatsApp di halaman pesanan/lacak; (2) `order_unlocked` kini disimpan sebagai peta `invoice => timestamp verifikasi` dengan TTL `OrderAccess::UNLOCK_TTL` = 10 menit — `isUnlocked()` menolak setenggang, jadi sesi yang ditinggal terkunci ulang otomatis; entri format lama (array string) dianggap terkunci (wajib verifikasi ulang). Pesan sukses unlock + kotak "Isi Pesanan Terkunci" menyebut durasinya. 2 test baru: checkout tidak sekalian membuka isi (`order_unlocked` kosong) dan isi terkunci ulang setelah 10 menit (`travel(11).minutes()` → kredensial hilang dari HTML) → **64 test, 292 asersi lulus**; diverifikasi di browser dengan checkout nyata (`INV261017C3YLS` → admin kirim isi akun `SangatRahasia999`): halaman pesanan di session pembeli menampilkan "Isi Pesanan Terkunci" tanpa kredensial, verifikasi WA benar baru membukanya (flash "10 menit"); tamu murni tetap 404 untuk `INV2610176S12V` maupun `INV261017ZC5MU`. Screenshot `/tmp/opencode/shot/fix-warnet-{terkunci,terbuka}.png`. |
| **Panel admin direstyling + deteksi pesanan realtime (permintaan pengguna).** Admin mendeteksi pesanan masuk mendekati realtime tanpa infra websocket: endpoint ringan `GET /admin/pesanan/live` (`AdminOrderController::live()`, `admin.orders.live`, JSON `belum_bayar`/`perlu_kirim`/`terbaru`-10) dipolling ±8 detik oleh `initAdminLive()` di `app.js` — hanya aktif bila halaman terlihat (`document.hidden` dilewati). Bell di header admin menampilkan jumlah pesanan menunggu ditindak dan membuka panel 10 pesanan terbaru (ikon, invoice mono, pembeli+ waktu, total → taut ke detail; dirender murni `textContent`, bukan `innerHTML`); pill "Belum bayar"/"Perlu kirim" di header juga menyegarkan angkanya; pesanan yang belum pernah terlihat di tab memunculkan toast "Pesanan masuk: {invoice} — {rupiah} ({pembeli})" + bunyi pendek Web Audio (tanpa berkas eksternal; `AudioContext` suspended ditangkap diam). Baseline = hasil poll pertama, jadi pesanan lama tidak meledak toast saat membuka panel. **Bug CSP terkait ikut dibeneri:** semua `onsubmit="return confirm(...)"` di 7 view admin (9 tempat) tidak pernah jalan karena CSP `script-src 'self'` memblokir JS inline — dikonversi ke atribut `data-confirm` + listener `submit` ter-delegasi (`initConfirm()`). Tampilan admin ikut dirapikan: sidebar bergrup (Katalog/Penjualan/Sistem) dengan ikon `lucide` + avatar inisial, menu aktif, badge "Pesanan"/"Ulasan"; header admin: judul + subjudul, pill "Belum bayar"/"Perlu kirim" berikon dengan angka live, bell + panel; halaman pesanan: baris belum-bayar disorot amber; stat card dashboard diberi chip ikon berwarna. Test baru (endpoint `admin.orders.live` admin → 200 + struktur JSON; tamu → redirect login) → **65 test, 313 asersi lulus**; diverifikasi di browser: dashboard panel baru, panel bell terbuka + daftar pesanan, dua pesanan dibuat dari sesi curl berbeda → toast "Pesanan masuk" muncul < 8 detik, badge naik 0→1→2, baris belum-bayar amber, dan 0 `onsubmit` tersisa di DOM. Screenshot `/tmp/opencode/shot/admin-live-{panel,toast}.png`, `/tmp/opencode/shot/admin-pesanan-baru.png`. |
| **Realtime juga untuk pembeli — halaman pesanan tidak perlu refresh manual (permintaan pengguna).** Sisi klien sebelumnya hanya polling di halaman pembayaran (`pay.blade.php`, reload saat `is_paid`), sedangkan `/pesanan/{invoice}` dari pembeli diam menunggu admin konfirmasi/kirim tanpa tahu harus refresh. Sekarang `App\Http\Controllers\Storefront\OrderController::status()` diperluas dengan boolean yang mengubah susunan halaman — `punya_isi` (ada item `is_fulfilled`) dan `terbuka` (`order_unlocked`) — tetap **tanpa data pribadi dan tanpa isi akun**; `initOrderLive()` di `app.js` polling `orders.status` ±10 dtk di halaman pesanan (atribut `data-order-live` + `data-live-key` = `payment|order|punya_isi|terbuka` server-rendered), `document.hidden` dilewati + pemeriksaan langsung saat tab kembali aktif; perubahan status APA PUN memunculkan toast "Status pesanan diperbarui — memuat ulang halaman…" lalu reload 1,2 dtk — memilih reload penuh daripada patch DOM supaya blok "Isi Pesanan Terkunci"/kredensial selalu dirender berdasarkan state server terkini. Halaman bayar juga ditingkatkan: reload otomatis saat pembayaran diterima ATAU pesanan kedaluwarsa, dengan toast penjelas. Keamanan tetap: isi akun baru tampil setelah verifikasi WhatsApp dan tetap terkunci ulang 10 menit (TTL `order_unlocked` tidak tersentuh polling). 2 test baru (JSON status membawa `punya_isi`/`terbuka` dan bereaksi terhadap unlock; halaman pesanan membawa `data-order-live` + `data-live-key`) → **67 test, 324 asersi lulus**; diverifikasi di browser: checkout nyata `INV261017DRPKH` → buka halaman pesanan (menunggu bayar) → admin "tandai lunas + terkirim + buat order_delivery" via tinker → halaman otomatis menyesuaikan tanpa refresh manual ("Pesanan Dibayar", badge LUNAS/TERKIRIM, kartu isi pesanan terkunci) → verifikasi WA → kredensial demo tampil + flash "Isi pesanan dibuka selama 10 menit", `data-live-key` menunjukkan `paid|delivered|1|1`. Screenshot `/tmp/opencode/shot/klien-live-reload.png`, `/tmp/opencode/shot/klien-isi-terbuka.png`. Catatan demo: blokir `unlock` demo sebelumnya membuat `throttle:10,10` route `orders.unlock` masih jalan dengan kunci cache ter-hash — dibersihkan dari tabel `cache`. |
| **429 "Too Many Requests" ketemu saat checkout (laporan pengguna).** Penyebab: route `POST /checkout` dibatasi `throttle:12,10` (12×/10 menit **per IP**) dan seluruh sesi uji — checkout manual pengguna, verifikasi curl realtime, dan pembuatan pesanan demo — memakai IP yang sama (127.0.0.1), jadi bucket cepat penuh dan Laravel luar halaman 429 generik bahasa Inggris tanpa penjelasan. Dua perbaikan: (1) limit checkout dilonggarkan jadi **30×/10 menit** — anti-spam masih ada untuk satu IP, tapi pembeli asli di belakang IP bersama (warnet/NAT) dan sesi uji manual tidak tersandung; (2) halaman error 429 kini dirender `resources/views/errors/429.blade.php` bergaya storefront: penjelasan bahasa Indonesia ("Terlalu sering mencoba"), **hitung mundur** langsung dari header `Retry-After` middleware (elemen `data-countdown` yang sama dengan halaman bayar), tombol Kembali ke beranda / Lacak pesanan, catatan kode 429 + perlindungan — pembeli tidak lagi memantulkan halaman kosong tanpa tahu harus tunggu berapa lama. 1 test baru (`Page429Test`: 11 POST /lacak → ke-11 memunculkan 429 + judul "Terlalu sering mencoba" + `data-countdown`) → **68 test, 338 asersi lulus**; diverifikasi di browser sungguhan: 12 POST `/lacak` dari tab → halaman 429 tampil dengan hitung mundur berjalan (09:54). Bucket limiter dev di tabel `cache` disapu bersih setelah uji supaya pengguna bebas testing. Screenshot `/tmp/opencode/shot/page-429.png`. |
| **Panel admin kini punya tampilan mobile modern (permintaan pengguna).** Sebelumnya di bawah 1024px sidebar `hidden lg:flex` membuat seluruh menu admin hilang — hanya tersisa pill + badge yang berdesakan. Sekarang tiga lapis navigasi berbagi sumber yang sama lewat partial `admin/_nav-links.blade.php` + `_sidebar-head` + `_sidebar-user` (desktop 256px tetap, jadi tidak ada menu yang berbeda antar breakpoint): (1) **drawer** geser dari kiri (`data-admin-drawer`, backdrop `bg-slate-900/50 backdrop-blur-sm`, lebar 288px/`max-w-[86vw]`, scroll internal) yang dikendalikan `initAdminNav()` di `app.js` — tombol hamburger di header dan tombol "Lainnya" di bottom nav membukanya, klik backdrop/Escape/pilih tautan menutup, `body` dikunci (`overflow-hidden`) saat terbuka, `aria-expanded` disinkronkan; (2) **bottom nav** 4 pintasan (Dashboard/Produk/Pesanan/Ulasan) + "Lainnya" dengan chip `brand-50` + teks brand untuk item aktif (`routeIs` per grup), `env(safe-area-inset-bottom)` untuk iPhone, dan `<main>` `pb-24` di mobile agar konten tidak tertutup; (3) header dirapatkan: judul `truncate` + subjudul, hamburger kiri, bell realtime kanan, pill "Belum bayar/Perlu kirim" tetap bisa digeser horizontal (`no-scrollbar`) dengan tautan "Lihat toko" menggantikan tombol keluar yang dulu menumpuk. `viewport-fit=cover` ditambahkan. Ergonomi sentuh: `@media (max-width:1023px)` menaikkan `.btn-sm` `min-height: 2.25rem` (dari 28px → 36px) dan pill header `py-2` (31px → 35px), `.table-wrap` diberi `-webkit-overflow-scrolling: touch`; ikon `Menu` ditambahkan ke peta lucide. 1 test baru (`AdminAccessTest::test_layout_admin_punya_navigasi_mobile_lengkap`: drawer/backdrop/bottomnav/hamburger/viewport-fit ada, setiap route nav muncul ≥2× karena dipakai desktop+drawer, tepat 2 `data-admin-nav-toggle`) → **69 test, 357 asersi lulus**. Diverifikasi di browser CDP: viewport 390×844 → drawer x=0 lebar 288, backdrop tampil, body terkunci, 8 `.nav-link`, tutup lewat backdrop/Escape/tautan; bottom nav item aktif "Pesanan" berchip merah; tidak ada overflow horizontal di dashboard/pesanan/produk/pengaturan/ulasan/detail pesanan/form produk; tablet 768px → drawer di x=-288 (tersembunyi); desktop 1366px → sidebar 256px tampil, bottom nav & hamburger `none`. Screenshot `/tmp/opencode/shot/admin-mobile-{dashboard,drawer,pesanan,detail,form}.png`, `admin-tablet-dash.png`, `admin-desktop-final.png`. |
| **Logo & merek toko kini mengikuti nama toko (permintaan pengguna).** Dua sisa hardcode merek situs asal ditemukan: wordmark header storefront masih `DIGITAL<span>HUB</span>` dan monogram `DH` di sidebar admin + halaman login (juga label "Kasir DigitalHub" di checkout/pay) — jadi mengganti `store_name` ke "Creative Solutions" tidak mengubah apa pun secara visual. Ditambahkan `Setting::logoMonogram()` (2 huruf dari nama toko: "Creative Solutions" → `CS`, satu kata → dua huruf pertama) dan `Setting::logoWordmark()` (kata terakhir diberi warna brand: "Creative **Solutions**"), lalu dipakai di header storefront (wordmark + tagline, monogram sebagai pengganti `<img>` bila belum ada unggahan), `admin/_sidebar-head.blade.php`, dan `auth/login.blade.php`; label kasir memakai `Setting::storeName()`. **Bug terkait ditemukan sekalian**: kunci `store_logo`/`store_banner` tidak ada di DB dev karena seeder lama, sehingga **input unggah logo tidak pernah muncul** di panel Pengaturan — daftar kunci dipusatkan ke `Setting::kunciBawaan()` yang dipakai `SettingSeeder` dan `SettingController::edit()` (menambal kunci yang belum ada, jadi instalasi lama tidak perlu `migrate:fresh --seed`). Ditambah `Setting::hasCustomLogo()` supaya ketiga view memakai satu predikat; bila logo diunggah, gambar dipakai di semua tempat (header, sidebar, login, invoice, favicon) dan monogram tidak dirender. 4 test baru (`StoreBrandingTest`: monogram/wordmark, header memakai nama toko & tidak ada sisa `DIGITAL`/`>DH<`, logo kustom menggantikan monogram, panel Pengaturan menambal kunci lama) → **73 test, 374 asersi lulus**; diverifikasi di browser: wordmark "Creative Solutions" + monogram `CS` di desktop & mobile, unggah logo uji via panel Pengaturan berhasil (input file baru muncul setelah fix), gambar tampil di storefront/admin/login, lalu logo uji dihapus dan kembali ke monogram. Screenshot `/tmp/opencode/shot/logo-{storefront,admin,login,desktop,mobile}.png`. |
| **Pembatalan pesanan oleh admin diperbaiki (permintaan pengguna).** Alur lama hanya mengubah `order_status` jadi `cancelled` + mengembalikan stok, sementara seluruh tampilan pembeli memakai `! $order->isPaid()` sebagai syarat "boleh bayar" — akibatnya pesanan yang sudah dibatalkan **tetap** menampilkan "Menunggu Pembayaran", tombol "Selesaikan Pembayaran", dan di `/pesanan/{invoice}/bayar` masih memampang **QRIS + hitung mundur + polling status**, sehingga pembeli bisa mentransfer ke pesanan yang sudah ditutup admin. Ditambahkan `Order::isCancelled()` dan `Order::bisaDibayar()` (belum lunas, belum batal, masih `pending`, belum lewat `expires_at`) sebagai satu-satunya predikat pembayaran; `order.blade.php` kini punya judul/ikon/warna kepala berstatus (Dibayar/Dibatalkan/Kedaluwarsa/Gagal/Menunggu) plus panel penjelasan + tombol "Belanja lagi"/"Tanya admin" untuk status akhir, dan `pay.blade.php` mendapat cabang `! $order->bisaDibayar()` sebelum cabang gateway/QRIS yang menyembunyikan QRIS, countdown, dan `data-poll-status`, serta badge kepala ikut menampilkan Dibatalkan/Kedaluwarsa. Sisi admin diperketat: `OrderService::cancel()` menolak pesanan **lunas** ("Kembalikan dana lewat menu refund") dan pesanan yang sudah batal, form batal hanya dirender untuk pesanan belum lunas & belum batal (sebelumnya muncul untuk semua yang belum terkirim), ditambah label "Alasan pembatalan", nilai `old('reason')`, dan blok `@error` yang sebelumnya pesan validasi tidak pernah tampil, plus kartu penjelasan saat pesanan sudah dibatalkan. 2 test baru di `OrderFulfillmentTest` (pembatalan mengembalikan stok + halaman pesanan/kasir tidak lagi menawarkan pembayaran; pesanan lunas ditolak & form batal tidak dirender) → **75 test, 395 asersi lulus**; diverifikasi di browser: pesanan uji `INV2610179QEX6` dibatalkan → halaman pesanan "Pesanan Dibatalkan" tanpa tombol bayar, kasir tanpa QRIS/countdown/polling; setelah dibuat lunas, form batal hilang dan POST langsung ditolak dengan pesan "Pesanan sudah lunas…"; skenario `expired` juga menampilkan "Pesanan Kedaluwarsa" di kedua halaman. Order uji dihapus setelah verifikasi. Screenshot `/tmp/opencode/shot/fix-batal-{pesanan,kasir}.png`, `/tmp/opencode/shot/batal-{pembeli,bayar}.png`. |
| **Pesanan batal masih muncul sebagai "Menunggu pembayaran" + dihitung di notifikasi (laporan pengguna).** Setelah pembatalan sebelumnya benar, laporan lanjutan: status sudah "Dibatalkan" tetapi baris pesanan tetap berlabel **"Menunggu pembayaran"** dan bell/pill admin masih menghitungnya sebagai **"Belum bayar"** (dan total bell ikut menyala). Akar masalah: `payment_status` sengaja tetap `pending` (memang tak ada uang masuk), tetapi (a) `Order::paymentLabel()` hanya membaca `payment_status` sehingga mengembalikan "Menunggu pembayaran", dan (b) `Order::scopeUnpaid()` hanya menyaring `payment_status = pending` tanpa mengecualikan `order_status = cancelled`, sehingga bell `belum_bayar`, pill "Belum bayar", ringkasan halaman pesanan, `orders_pending` di dashboard, dan `live()` semuanya tetap menghitung pesanan batal; filter `?bayar=pending` juga menampilkan pesanan batal sehingga angka pill tidak cocok dengan daftar yang dibuka. Perbaikan: `paymentLabel()` mengembalikan **"Dibatalkan"** untuk pesanan `cancelled` (berlaku otomatis di 15 titik render: tabel pesanan, dashboard, detail admin, halaman pesanan/kasir pembeli, invoice, lacak, kartu riwayat, e-invoice), ditambah `paymentBadge()` agar warna badge ikut netral; `scopeUnpaid()` menambahkan `where('order_status','!=','cancelled')`; filter `bayar=pending` di `AdminOrderController::index()` mengecualikan pesanan batal bila filter status tidak dipilih; baris amber di tabel pesanan kini memakai `bisaDibayar()` (bukan `payment_status === pending`) sehingga pesanan batal tidak lagi disorot sebagai butuh tindakan; badge "Menunggu pembayaran" tidak lagi dirender ganda bersama badge "Dibatalkan" (kolom bayar di tabel menampilkan "—" untuk pesanan batal, detail pesanan hanya menampilkan badge status); badge per-item "Belum terkirim" berubah jadi "Dibatalkan" untuk pesanan batal; `markPaid()` dan `OrderController::deliver()` kini menolak pesanan batal (stoknya sudah dilepas, jangan dihidupkan lagi), tombol "Konfirmasi pembayaran" disembunyikan; webhook Tripay melewati event `EXPIRED`/`FAILED` untuk pesanan lunas/terkirim/batal agar retry gateway tidak menghasilkan 500; popup "Cek Transaksi" mendapat tab keempat **Batal** (grid 3→4 kolom, tanpa overflow di 390px) karena pesanan batal sebelumnya nyangkut di tab "Menunggu / Proses". 1 test baru (`test_pesanan_batal_tidak_dihitung_belum_bayar_dan_tidak_bisa_dilunasi` memeriksa `unpaid()`=0, `awaitingDelivery()`=0, label "Dibatalkan", subheading "Belum bayar: 0", JSON `live()` bersih, dan penolakan `markPaid`) → **76 test, 407 asersi lulus**. Diverifikasi di browser dengan pesanan batal nyata `INV261017P7350`: admin index "Belum bayar: 0" + kolom bayar "—" + badge "Dibatalkan", bell badge 1 (hanya dari "Perlu kirim" yang sah) dengan pill "Belum bayar 0"/"Perlu kirim 1", detail pesanan tanpa "Menunggu pembayaran"/tanpa tombol konfirmasi lunas, dashboard 0 kemunculan "Menunggu pembayaran", halaman pembeli + invoice + lacak berjudul "Pesanan Dibatalkan" tanpa tombol bayar, dan tab Batal di popup cek transaksi berfungsi. Screenshot `/tmp/opencode/shot/batal-admin-{index,show,dash}.png`, `/tmp/opencode/shot/batal-pembeli{,-track}.png`, `/tmp/opencode/shot/batal-cek-{transaksi,mobile}.png`. |
| **Bot WA diam — "Nomor WhatsApp tidak valid" untuk chat @lid (laporan pengguna, tanggal 2026-10-18).** Pembeli merasa bot tidak merespon: chat pribadi dari WhatsApp kini datang sebagai `@lid` (Linked Identity), dan nomor yang diekstrak dari alamat itu BUKAN nomor HP. Dua bug berantai di `identitasPembeli()` (`bot-wa/src/adapters/whatsapp.js`): (1) `client.getNumberId()` whatsapp-web.js menambahkan `@c.us` ke argumennya, sehingga angka LID diperlakukan sebagai nomor HP dan query ke server WhatsApp gagal return; (2) `kontak.number` dari `getContactById()` SELALU kosong di whatsapp-web.js v1.34 — padahal `kontak.id` sudah berisi JID nomor HP hasil terjemahan LID (diverifikasi langsung via CDP di sesi jalan: `getContactById('135691220558028@lid')` → `id=6289520594405@c.us`, `number=undefined`). Akibatnya nomor tidak pernah terbaca, bot jatuh ke LID, API Laravel menolak dengan "Nomor WhatsApp tidak valid", dan pesanan tidak bisa dibuat sama sekali. Perbaikan: logika pindah ke `nomorDariKontak()` di `core/wa-filter.js` (murni, ada test) yang membaca nomor dari `kontak.id._serialized` (menolak JID `@lid`), `kontak.number` jadi cadangan; balasan tetap dikirim ke chat asal (`@lid`) supaya masuk percakapan yang sama — bukan membuat chat baru. Sekalian (pilihan pengguna): pesan uji datang dari GRUP "Tes Bot", jadi grup kini bisa diizinkan eksplisit lewat `BOT_GROUP_ALLOWLIST` (ID `@g.us`, dipisah koma; kosong = semua grup tetap diabaikan seperti sebelumnya, aturan produksi tidak berubah); di grup, identitas pembeli diambil dari `msg.author` (pengirim), bukan ID grup. Verifikasi: `npm test` bot 75 test lulus (4 test regresi baru untuk `nomorDariKontak` + 1 untuk allowlist grup, termasuk asersi JID `@lid` tidak pernah dipakai sebagai nomor); keysingan akar masalah diverifikasi langsung via CDP di sesi berjalan (`getContactById` LID → `id` berisi nomor HP, `number` undefined) dan bot ditestart sampai `WhatsApp siap` tanpa QR (`bot-wa/bot.log`, log memakai `BOT_LOG_FILE`). Uji chat langsung (kirim `list` di grup tes / DM) belum dilakukan sesi ini — bot siap dan allowlist grup sudah termuat di config, tinggal kirim `list` untuk memastikan bulan-bulan berjalan. README/`.env.example`/AGENTS.md diperbarui (cara pasang, peringatan `kontak.number` kosong, aturan `msg.author` di grup). |
| **Katalog `list` bot diganti format "papan menu" (permintaan pengguna, tanggal 2026-10-18).** Pembeli di contoh toko asal membalas NAMA layanan, bukan angka. `katalog()` (`bot-wa/src/core/replies.js`) dirender ulang: header `「 𝗟𝗜𝗦𝗧 𝗠𝗘𝗡𝗨 𝗦𝗧𝗢𝗥𝗘 」` + sapaan waktu otomatis (pagi/siang/sore/malam, zona `Asia/Makassar`, fungsi baru `sapaanWaktu()`/`tanggalWaktuWIB()`), tanggal & jam berlabel WIB, lalu satu nama layanan per baris berpapor `𐚁๋࣭⭑` tanpa nomor urut/tanpa harga, ditutup blok rules dengan nama toko dari database (tidak lagi hardcode merek). Jumlah produk per list dinaikkan `BOT_MAX_PRODUCTS` default 40 → 50 (katalog saat ini 29 produk, hasil render ±1.1 ribu karakter = satu pesan). Seleksi produk lewat alur pencarian yang sudah ada: pembeli mengetik nama ("netflix") → kecocokan persis langsung membuka detail; angka tetap dipakai di daftar hasil pencarian dan daftar paket, dan `pilihProdukNomor()`/`list N` tetap jalan sebagai kompatibilitas. Test nomor-urut lama diganti uji konsistensi NAMA (urutan baris di pesan harus sama dengan `daftarProdukDatar()`) + uji balas-nama-membuka-produk → **76 test lulus**; hasil render diverifikasi dengan katalog nyata dari API (29 nama, rules "CREATIVE SOLUTIONS") dan bot ditestart sampai `WhatsApp siap`. |
| **Detail produk bot diganti gaya menu (permintaan pengguna, tanggal 2026-10-18).** Balasan bot saat pembeli menyebut nama produk kini: judul produk huruf besar (`*YOUTUBE PREMIUM*`), lalu tiap paket dalam satu baris `"Nama Paket : *harga*"` — harga bold TANPA "Rp" (`angka()` saja, stok/garansi/deskripsi tidak lagi tampil, varian habis tetap ditandai `❌`), diikuti catatan `required_fields` varian digayakan huruf miring matematika (`* 𝐼𝑛𝑣𝑖𝑡𝑒 𝐸𝑚𝑎𝑖𝑙`) lewat helper baru `hurufMiring()` di `core/format.js`. Karena nomor urut tidak ditampilkan, pembeli memilih paket lewat NAMANYA: helper murni baru `cariVarian()` (`core/engine.js`, kecocokan persis menang lalu "berisi kata") dipakai di blok `cari` generik saat state `DETAIL` — pentingnya terletak di URUTAN: pencarian generik jalan SEBELUM switch per-state, jadi pencocokan nama paket harus di dalam blok itu, bukan di `case STATE.DETAIL` (balasan dibiarkan jatuh ke pencarian produk kalau ambigu/tidak cocok, dan angka lama tetap diterima sebagai kompatibilitas). Teks `sambutan`/`bantuan` + README disesuaikan ("Balas nama paket atau nomornya"); harga tetap dari API Laravel tanpa dihitung di bot. Render diverifikasi dengan data nyata YouTube Premium (2 paket), dan **78 test bot lulus** — test detail lama (harga "Rp", stok, deskripsi) diganti asersi format baru ditambah test balas-nama-paket-langsung-pesan dan test catatan miring. Bot di-restart sampai `WhatsApp siap`. |
| **Tampilan detail bot disamakan persis dengan template (permintaan pengguna, tanggal 2026-10-18).** Contoh template menampilkan nama paket berbeda dari web ("1 Bulan Famhead" di bot vs "Youtube Head 1 Bulan" di web) dan catatan paket (`𝑖𝑛𝑣𝑖𝑡𝑒 𝑒𝑚𝑎𝑖𝑙` / `𝐴𝑐𝑐 𝑏𝑢𝑦𝑒𝑟`). Namanya dibedakan per-kanal lewat kolom baru `variants.bot_label` (migrasi `001500`, admin punya input "Label khusus bot WA" opsional di form varian — kosong = pakai nama varian; web tidak tersentuh); `BotController::ringkasVarian()` memuat `bot_label`, bot merender `v.bot_label || v.name` dan `cariVarian()` mencocokkan nama yang tertampil. Catatan paket = `required_fields` varian yang diisi datanya untuk YouTube Premium (`1 Bulan Famplan` → "invite email", varian kedua → "Acc buyer") — efek samping disadari: web checkout juga ikut menanyakan data itu; kosongkan label di panel jika tidak diinginkan. Harga DIBIARKAN 5.000/8.000 mengikuti DB (pilihan pengguna), jadi angka di bot ≠ template. Render akhir terverifikasi identik template (`*YOUTUBE PREMIUM*` + 2 paket + 2 catatan miring), **108 test Laravel + 78 test bot lulus**; Lastly dua kali restart berturut-turut hang > 2 menit setelah "Autentikasi berhasil" tanpa event `ready` (Chrome/WhatsApp lambat; dibiarkan jangan diprobing CDP) — restart ketiga langsung `WhatsApp siap`, pola sama dengan insiden sebelumnya. |
| **Balasan bot jadi reply/quote ke pesan pembeli (permintaan pengguna, tanggal 2026-10-18).** ID pesan pemicu (`msg.id._serialized`) dibawa dari `on('message')` di `adapters/whatsapp.js` sebagai `pesanId` ke `tanganiPesan()` (`index.js`); potongan PERTAMA hasil engine dikirim lewat `adapter.kirim(sendTo, teks, { balasId: pesanId })` → `kirimTeks()` memakai `client.sendMessage(chatId, teks, { quotedMessageId })` dari whatsapp-web.js, potongan lanjutan & QRIS tetap pesan biasa. Kalau kirim dengan quote gagal (mis. chat @lid diubah tujuannya ke JID nomor HP sehingga ID pesan beda chat), percobaan berikutnya otomatis LEPAS quote — balasan tidak pernah terpotong oleh fitur ini. Mesin (`core/*`) tidak tersentuh; **78 test bot lulus**, render detil tidak berubah. Bot di-restart sampai `WhatsApp siap`. |
| **Quote/reply tidak tampil (laporan pengguna, tanggal 2026-10-18).** Akar: di chat DM `@lid`, adapter mengonversi tujuan kirim ke JID nomor HP (`@c.us`), sedangkan ID pesan yang diquote hidup di chat `@lid` — `window.WWebJS.sendMessage` mencari pesan itu di store chat TUJUAN, gagal, dan whatsapp-web.js membuang quote **diam-diam** karena `ignoreQuoteErrors` default `true` (diverifikasi di `node_modules/whatsapp-web.js/src/util/Injected/Utils.js:171`). Perbaikan: `adapter.kirim(chatId, teks, opsi)` kembali menerima CHAT ASAL (peta `sendToPerChat` menyelesaikan tujuan di dalam `kirimTeks`), dan saat `opsi.balasId` ada dengan tujuan ≠ chat asal, quote dikirim dulu **ke chat asal** (`client.sendMessage(chatAsal, teks, { quotedMessageId })`); kalau result falsy/throw → lanjut kirim biasa ke tujuan. Untuk chat `@c.us`/grup (tujuan = chat asal) quote dikirim langsung di jalur utama. Versi debug log memakai `BOT_LOG_LEVEL=debug` agar baris `Terkirim (quote)`/`Quote gagal` terlihat saat pengujian. 78 test bot lulus. |
| **ID pesan v1.34 ternyata ada di kunci `$1`, bukan `_serialized` (tanggal 2026-10-18).** Log runtime (`BOT_LOG_LEVEL=debug`) membuktikan bentuk `msg.id` di whatsapp-web.js v1.34: `{"fromMe":false,"remote":"1203…@g.us","id":"3EB0…","participant":"5453…@lid","$1":"false_1203…@g.us_3EB0…_5453…@lid"}` — **tanpa `_serialized`**, dan pesan grup menyertakan `participant` di kunci store. `idPesanPenuh()` di `adapters/whatsapp.js` kini memakai `id.$1` lebih dulu (kunci yang sama dipakai `WAWebCollections.Msg.get()`), lalu `_serialized`, lalu susunan `<fromMe>_<remote>_<id>[_<participant>]`. Ditambah diagnostik sementara (level debug): sebelum mengirim dengan quote, bot mengevaluasi di halaman WhatsApp sendiri `Msg.get(id)` → `ada|tidak-ada-di-store`, `canReply=…` (lewat `WAWebMsgReply.canReplyMsg`), dan setelah kirim mencatat `quote=menempel|TIDAK menempel` (dibaca dari `_data.quotedStanzaId`). Hasil uji pertama: ID sudah benar bentuknya tetapi quote **belum menempel** — penyebab pasti menyusul setelah uji berikutnya membaca baris `cek-quote`. |
| **Perintah `pay`: nomor admin/owner resmi + QRIS resmi (permintaan pengguna, tanggal 2026-10-18).** Pembeli yang mengetik `pay` (alias `payment`, `pembayaran`, `bayar`; alias baru di `core/parser.js`) kini menerima balasan "PAYMENT RESMI": daftar nomor admin/owner (`* 082243890517`, `* 08214369690`), peringatan bahwa nomor lain 100% penipu, garis pemisah `▓▓`, dan aturan wajib lewat QRIS resmi. Datanya TIDAK di-hardcode di bot: kunci pengaturan baru `payment.owner_numbers` (tipe `text`, satu nomor per baris — muncul otomatis di panel Pengaturan karena `Setting::kunciBawaan()` juga menambal instalasi lama) dibaca `BotController::ownerNumbers()` (koma/titik-koma/baris baru diterima, dinormalkan `wa_normalize()`, disaring hanya 62 + panjang wajar) dan dikirim bersama `qris_url` + `qris_holder` di payload `store` `/api/bot/katalog`; bot menampilkan `nomorLokal()` (`62…` → `08…`) dan jatuh ke `store.whatsapp` bila daftar kosong. **Gambar QRIS dikirim LEBIH DULU** dari teksnya (`hasil.qrisDulu` + `hasil.qrisCaption` baru, dikirim di awal `kirimHasil()`) supaya kalimat "QRIS diatas" menunjuk gambar itu; jalur QRIS lama (cek status pesanan belum bayar) tetap di akhir dan tidak dobel karena dijaga `!hasil.qrisDulu`. Kalau admin belum mengunggah QRIS, balasan menambah peringatan "Gambar QRIS belum diatur admin". 2 test baru (parser `pay` + engine memeriksa isi teks/nomor lokal/`qrisDulu`, 1 test API Laravel memeriksa `store.owner_numbers`/`qris_url`/`qris_holder`) → **109 test Laravel (514 asersi) + 80 test bot lulus**; render diverifikasi dengan API & QRIS nyata (nomor `CREATIVE SOLUTIONS` muncul `08…`, gambar `settings/8ha0m…jpg`). Bot di-restart sampai `WhatsApp siap` (satu kali hang > 2 menit setelah "Autentikasi berhasil" seperti biasa, restart berikutnya langsung siap). |
| **Balasan `pay` jadi SATU pesan (permintaan pengguna, tanggal 2026-10-18).** Sebelumnya gambar QRIS dikirim dulu lalu teks `PAYMENT RESMI` menyusul sebagai pesan kedua. Sekarang keduanya menyatu: teks panjang itu menjadi **caption** gambar QRIS (317 karakter, jauh di bawah batas caption WhatsApp 1.024), jadi riwayat chat lebih rapi dan "QRIS diatas" menunjuk gambar di pesan yang sama. Gambarnya memakai **berkas lokal bot** `bot-wa/qris.jpeg` (QRIS resmi "Creative Solutions", NMID ID1025413764586) yang bisa diganti lewat `BOT_QRIS_FILE` di `.env` (default `./qris.jpeg`) — `config.qrisLokal` menyimpan path absolut dan `null` kalau berkasnya tidak ada. Adapter (`adapters/whatsapp.js`) sekarang mengenal media dari dua sumber lewat `mediaDari()`: `mediaDariUrl()` (http/https, tetap dipakai jalur QRIS pesanan) dan `mediaDariBerkas()` (berkas lokal, mime ditebak dari ekstensi; berkas < 100 byte ditolak karena pernah membuat Chrome crash). Urutan cadangan: berkas lokal → `store.qris_url` pengaturan → teks saja + peringatan "Gambar QRIS belum diatur admin" (parameter `adaQris` baru di `R.pembayaranResmi()`). Test bot 81 lulus (test `pay` diubah: `replies` kosong + caption berisi seluruh teks + `qris` menunjuk berkas, ditambah test jatuh-ke-teks tanpa gambar). |
| **QRIS gagal terkirim + balasan belum meng-quote (laporan pengguna, tanggal 2026-10-18).** Dua akar terpisah, dua-duanya terbukti dari log+uji nyata. (1) **Media:** log bot menampilkan `⚠ Gambar gagal dikirim (Data passed to getter must include an id property (it's how we memoize) but got undefined)` — ini bug whatsapp-web.js v1.34.7 yang dilaporkan luas (isu upstream #201921/#201922, diperbaiki PR #201923): `processMediaData()` mengembalikan model MediaData yang punya properti internal enumerable `__x_id`, dan `window.WWebJS.sendMessage()` menyebarkan seluruh model itu ke objek pesan keluar (`...mediaOptions`) sehingga `__x_id` menimpa ID pesan baru → WhatsApp Web gagal menginisialisasi model pesan dan SEMUA kiriman media (gambar/video/audio) gagal, sementara teks normal (penyebab bot "balas teks saja"). Perbaikan: `src/patch-lib.js` baru menyisipkan `delete message.__x_id;` tepat setelah objek pesan di dalam `window.WWebJS.sendMessage` (anchor dicari dari `window.WWebJS.sendMessage = async`, idempoten lewat penanda, `npm install` yang menimpa `node_modules` langsung ditambal lagi saat start berikutnya) — dipanggil dari `index.js` sebelum `client.initialize()` dan melaporkan statusnya ke log. **Verifikasi nyata:** skrip baru `uji-media.mjs` (login pakai sesi yang sama, kirim `qris.jpeg` ke satu chat, keluar) dijalankan ke nomor admin → `hasil kirim media: TERKIRIM`, gambar sampai di HP. (2) **Quote:** laporan "bot tidak mereply" ternyata ada dua sebab — `pay` memang TIDAK pernah meng-quote karena `replies` kosong (gambar dikirim lewat jalur media yang belum mendukung quote), dan verifikasi quote bot salah baca field: model pesan library memakai `data.quotedMsg` (`Message.hasQuotedMsg`), bukan `quotedStanzaId`, sehingga log mencetak `TIDAK menempel` walau quote sebenarnya bisa menempel. Perbaikan: `kirimTeks`/`kirimGambar` kini memakai satu jalur bersama `kirimKe()` (quote ke chat asal bila tujuan ≠ chat asal, 3x ulang untuk konteks WA hilang, lalu jatuh ke kirim biasa) dengan `logKirim()` yang membaca `hasQuotedMsg || _data.quotedMsg`; `kirimMedia(chatId, sumber, caption, opsi)` menerima `balasId` sehingga pesan QRIS `pay` (gambar+caption) juga meng-quote pesan pembeli; `kirimHasil()` memakai satu penghitung `pesanPertama` (media dulu, baru potongan teks) supaya hanya SATU pesan pertama yang meng-quote; diagnostik `cek-quote` diperluas (selain `Msg.get`/`canReplyMsg`, sekarang mencatat kunci `msgContextInfo()`). 81 test bot lulus (Laravel tidak tersentuh). Bot di-restart sampai `WhatsApp siap` (satu kali hang > 2 menit setelah "Autentikasi berhasil", restart berikutnya langsung siap). |
| **Perintah admin `proses`/`done` → pengumuman transaksi ke pembeli (permintaan pengguna, tanggal 2026-10-18).** Admin cukup mengetik `proses <catatan>` atau `done <catatan>` di chat tempat order terjadi dan bot membalas template asli toko: judul `*_TRANSAKSI PENDING ✅ 」_*` / `*_TRANSAKSI BERHASIL「 ✅ 」_*`, baris `⏰ Jam` (HH:mm:ss WIB, zona `Asia/Makassar` lewat `tanggalWaktuWIB()`), `📅 Tanggal` ("18 Oktober 2026"), `📂 Grup` (**nama grup WhatsApp tempat perintah ditulis** — dibaca `msg.getChat().name` di adapter; di chat pribadi jatuh ke nama toko dari database, bukan hardcode), `📝 Catatan` = teks setelah perintah, kalau tidak ada dipakai produk+paket dari **pesanan terakhir yang dibuat bot di chat itu** (peta baru `transaksiTerakhir` di `src/index.js`, diisi `catatanPesanan()` dari `hasil.order.items`), ditutup `@Nama Pembeli _Terima kasih sudah order!_`. Parser (`core/parser.js`) mengenali kata pertama `proses/pending/diproses` dan `done/selesai/berhasil` **beserta catatannya** — dipisah dari `ALIAS` yang exact-match supaya catatan ikut terbawa, dan catatan diambil dari teks ASLI agar huruf besar/kecil tidak berubah. Batas akses: hanya nomor di `BOT_ADMIN_NUMBERS` (`ctx.admin` di `handlePesan()`); pembeli yang mengetik kata yang sama diperlakukan sebagai **pencarian produk biasa**, bukan pengumuman. Bot sengaja **tidak** mengubah status pesanan (verifikasi tetap milik admin di panel) dan **tidak** menghapus sesi percakapan pembeli yang sedang jalan. Kalau admin mengetik `proses` tanpa catatan dan belum ada pesanan di chat itu, bot meminta catatannya (`transaksiTanpaCatatan()`) daripada mengirim baris kosong. `scripts/simulate.js` ikut mendukung (`GRUP="…" npm run simulate`) sehingga bisa diuji tanpa WhatsApp. 6 test baru (parser 1, engine 5: pending, berhasil, catatan menang atas pesanan terakhir, fallback nama toko, tanpa catatan, bukan admin) → **88 test bot lulus**; render nyata diverifikasi lewat simulator dengan `GRUP="DIGITALHUB OFFICIAL"` (keluaran identik template, termasuk `📂 Grup      : DIGITALHUB OFFICIAL`). |
