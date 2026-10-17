# Creative Solutions — aplikasi toko digital

Aplikasi pengganti `https://digitalhubstore.my.id/` dengan fungsi yang sama
(katalog → keranjang → checkout → pembayaran → pengiriman produk → ulasan)
tetapi dengan perhitungan harga di server, inventaris nyata, panel admin
berpassword, dan pengiriman otomatis.

Stack: **Laravel 13 · Blade · MySQL 8 · Vite + Tailwind v4**.
Tanpa Livewire/Inertia — semua interaksi lewat controller + Blade + satu file
JS kecil di `resources/js/app.js`.

- Analisis situs asal (endpoint, celah keamanan, model data) → `../ANALYSIS.md`
- Scraper & hasil scrape mentah → `../README.md`, `../scrape.py`, `../data/`

---

## 1. Menjalankan

Prasyarat: PHP 8.3+, Composer, Node 20+, Docker (untuk MySQL).

```bash
# 1. Database (MySQL 8.4 di host port 3307)
docker compose up -d

# 2. Konfigurasi
cp .env.example .env
php artisan key:generate

# 3. Dependency
composer install
npm install

# 4. Skema + data contoh + katalog hasil scrape
php artisan migrate --seed
php artisan storage:link

# 5. Aset frontend
npm run build      # produksi
npm run dev        # atau mode watch saat mengembangkan

# 6. Jalankan
php artisan serve
```

Buka <http://localhost:8000>. Panel admin di <http://localhost:8000/admin>.

### Akun bawaan (WAJIB diganti sebelum dipakai publik)

| Role | Email | Password | Akses |
|---|---|---|---|
| Admin | `admin@creativesolutions.test` | `password` | Semua, termasuk pengguna & pengaturan |
| Staff | `staff@creativesolutions.test` | `password` | Katalog, pesanan, ulasan, voucher, log |

Ganti password lewat menu **Pengguna → Ubah** setelah login pertama.
Akun default hanya dibuat oleh seeder — kalau `AdminUserSeeder` tidak dijalankan,
tidak ada akun default sama sekali.

---

## 2. Alur pembelian

```
/produk           katalog + filter kategori/harga/urut
/produk/{slug}    detail produk, pilih varian, kirim ulasan
/keranjang        ubah jumlah / hapus
/checkout         data pembeli + pilih metode bayar + voucher
  ├─ QRIS manual  → /pesanan/{invoice}/bayar (QR statis + kirim bukti ke WA)
  └─ Tripay       → halaman bayar menampilkan QR/VA dari gateway
/cek-transaksi    (POST) popup "Cek Transaksi": invoice → lembar E-Invoice
/lacak            cari pesanan dengan invoice + nomor WhatsApp
/pesanan/{inv}    status pesanan; isi akun muncul setelah diverifikasi
/pesanan/{inv}/buka      (POST) buka isi akun dengan nomor WhatsApp pembeli
/pesanan/{inv}/invoice   invoice siap cetak
```

### Popup "Cek Transaksi" (menu Riwayat Pesanan / Cek Transaksi / Pesanan)

Tiga tombol di storefront — **Cek Transaksi** (trust bar), **Riwayat Pesanan**
(header), dan **Pesanan** (nav bawah) — membuka popup yang sama, meniru
`openHistoryModal()` + `invoice-preview-modal` situs asal: form pencarian, tab
status (Semua / Menunggu-Proses / Selesai), kartu pesanan gaya Shopee, lalu
**Lembar Invoice Pembelian** (E-Invoice) di lapis kedua.

Isi lembar = data pembelian saja: nomor invoice, tanggal, metode, status,
nama pembeli, daftar produk + total, dan catatan garansi. **Isi akun/kredensial
tidak pernah dirender di popup ini**, dan popup ini **tidak membuka akses apa
pun** (invoice hasil cek tidak masuk `my_orders`/`order_unlocked`).

Klik kartu atau tombol **Lihat Invoice** — baik di daftar awal maupun hasil
pencarian — **hanya membuka Lembar Invoice Pembelian di lapis kedua** (satu
lembar per kartu, tanpa pindah halaman), persis perilaku `viewInvoiceFromData()`
situs asal. Anchor tetap menaut ke halaman pesanan/invoice milik sesi ini
sebagai fallback tanpa JS.

Bedanya dengan situs asal (dan alasannya):

| Situs asal | Di sini |
|---|---|
| Cari riwayat dengan **nomor WA/email** (`GET /orders?wa=`) — siapa pun yang tahu nomor WA orang lain bisa membacanya | Pencarian **hanya nomor invoice**, tanpa pernah menerima nomor WA |
| Seluruh data pesanan (email, WA, **isi akun**) dititipkan base64 di `onclick` kartu | Tidak ada data pesanan di HTML; lembar dirender server dari sesi flash |
| Data pembeli tampil utuh | Email & WhatsApp disamarkan (`mask_email()`, `mask_wa()`) — nomor WA adalah kunci membuka akun |
| Tanpa batas percobaan | 8 percobaan / 5 menit per IP (`RateLimiter`) + `throttle:20,10` |
| Riwayat dari `localStorage` browser | Daftar awal = pesanan yang memang tersimpan di sesi browser ini; kliknya membuka lembar E-Invoice yang sama, bukan pindah halaman |

### Pembatalan pesanan (admin)

`POST /admin/pesanan/{order}/batal` (`OrderService::cancel()`) hanya berlaku
untuk pesanan **belum lunas dan belum terkirim**:

| Kondisi | Perilaku |
|---|---|
| Belum lunas, belum terkirim | Status jadi `cancelled`, stok reservasi dikembalikan, alasan masuk `admin_note` + log aktivitas |
| Sudah lunas | Ditolak (`errors('order')`): admin harus memproses refund dulu — uang pembeli tidak boleh hilang tanpa jejak |
| Sudah terkirim/selesai | Ditolak |
| Sudah dibatalkan | Ditolak (idempoten, tidak menimpa catatan) |

Di sisi pembeli, `Order::bisaDibayar()` menutup semua jalan pembayaran untuk
pesanan batal/kedaluwarsa/gagal: halaman `/pesanan/{invoice}` menampilkan
**Pesanan Dibatalkan** (bukan "Menunggu Pembayaran"), tombol "Selesaikan
Pembayaran" hilang, dan diganti panel penjelasan + tombol "Belanja lagi" /
"Tanya admin". Halaman kasir `/pesanan/{invoice}/bayar` tidak lagi menampilkan
QRIS, hitung mundur, atau polling status — hanya panel status akhir, supaya
pembeli tidak mentransfer ke pesanan yang sudah ditutup.

**Pesanan batal bukan "belum bayar".** `payment_status` sengaja dibiarkan
`pending` (memang tidak ada uang masuk) — supaya tidak ada yang salah hitung,
status batal ditangani di tiga titik:

| Titik | Perilaku |
|---|---|
| `Order::paymentLabel()` / `paymentBadge()` | Mengembalikan **"Dibatalkan"** + badge netral untuk pesanan `cancelled`, jadi tidak ada lagi baris berlabel "Menunggu pembayaran" |
| `Order::scopeUnpaid()` | Menambahkan `order_status != cancelled`, sehingga bell, pill "Belum bayar", ringkasan halaman pesanan, dan statistik dashboard ikut bersih |
| `Order::scopeAwaitingDelivery()` | Tetap `paid` + `new/processing`, jadi pesanan batal tidak pernah masuk "Perlu kirim" |

Filter `?bayar=pending` di daftar pesanan juga mengecualikan pesanan batal
(selama filter status tidak dipilih) agar hasilnya cocok dengan angka yang
diklik. `markPaid()` dan `deliver()` menolak pesanan batal, dan webhook Tripay
melewati event `EXPIRED`/`FAILED` untuk pesanan yang sudah lunas/terkirim/batal
sehingga retry gateway tidak berakhir 500. Di popup "Cek Transaksi" pesanan
batal punya tab sendiri (**Batal**) dan tidak lagi nyangkut di tab
"Menunggu / Proses".

### Batas percobaan (throttle) & halaman 429

Pembatas dilindungi `RateLimiter` per IP supaya aksi yang bisa disalahgunakan
tidak dibombardir: checkout **30×/10 menit**, lacak 10×/10 menit, buka isi
pesanan 10×/10 menit + 5×/10 menit per pesanan di dalam controller, cek
transaksi 20×/5 menit, login 10×/menit. Kalau habis, Laravel melempar 429 dan
`resources/views/errors/429.blade.php` menampilkan halaman berbahasa Indonesia:
penjelasan singkat, **hitung mundur** dari header `Retry-After` (elemen
`data-countdown` yang sama dengan halaman bayar), plus tombol kembali/beranda —
pembeli tidak kebingungan dengan 429 kosong bawaan Laravel.

### Status pesanan pembeli tanpa refresh (realtime sederhana)

Halaman `/pesanan/{invoice}` memantau dirinya sendiri lewat polling ~10 detik
ke `GET /pesanan/{invoice}/status` (`orders.status`, hanya status + boolean —
tanpa data pribadi, tanpa isi akun). Kalau `payment_status`, `order_status`,
kehadiran isi pesanan, atau status unlock berubah (admin mengonfirmasi bayar,
mengirim isi akun, unlock kedaluwarsa), halaman memuat ulang otomatis dengan
toast "Status pesanan diperbarui — memuat ulang halaman…"; pembeli tidak
perlu menekan refresh. Halaman bayar juga memuat ulang otomatis saat pembayaran
diterima **atau** pesanan kedaluwarsa. Saat tab tidak terlihat polling dilewati,
dan pemeriksaan langsung dijalankan begitu tab kembali aktif.

### Akses pesanan: dua tingkat

Pesanan hanya bisa dibuka kalau nomor invoice-nya tersimpan di sesi pembeli
(hasil checkout sendiri atau hasil `/lacak` yang cocok invoice + WhatsApp).
Invoice saja tidak cukup — lihat `OrderController::findAccessible()`.

Tapi sesi browser bisa dipakai bergantian (komputer warnet, HP keluarga), jadi
aksesnya dipisah dua tingkat oleh `App\Services\OrderAccess`:

| Tingkat | Isi session | Yang boleh dilihat |
|---|---|---|
| Status | `my_orders` | nama, status, item, rincian biaya, nomor WA **tersamar** (`+62812••••890`) |
| Isi akun | `order_unlocked` | kredensial/isi pesanan (`Isi Pesanan Anda`) + data wajib pembeli |

Isi akun **tidak pernah dikirim ke browser** sebelum invoice masuk
`order_unlocked`, dan itu hanya terjadi lewat dua jalan: (1) `/lacak` yang cocok
invoice + WhatsApp, atau (2) form di halaman pesanan
(`POST /pesanan/{inv}/buka`) yang meminta nomor WhatsApp pembeli — dibatasi
5 percobaan / 10 menit per IP dan dicatat di activity log
(`order.unlock` / `order.unlock.rejected`).

Dua pengaman tambahan yang menutup skenario **browser dipakai bergantian**:

- Checkout **tidak pernah** membuka isi akun otomatis (sebelumnya invoice langsung
  masuk `order_unlocked` saat dibuat) — cukup `my_orders` untuk halaman status.
- Isi `order_unlocked` berwaktu (`OrderAccess::UNLOCK_TTL` = 10 menit): setelah
  verifikasi WhatsApp, isi akun terbuka sementara lalu **terkunci ulang** dan
  harus diverifikasi lagi. Sesi browser yang ditinggal tidak membocorkan akun
  milik pembeli sebelumnya.

Nomor WhatsApp karena itu **tidak pernah ditampilkan utuh** di halaman pembeli
(helper `mask_wa()`): nomor itu adalah kunci untuk membuka akun. Admin tetap
melihat nomor lengkap di panel admin karena dipakai untuk menghubungi pembeli.
Email pembeli mengikuti aturan yang sama di lembar publik (`mask_email()`),
karena lembar itu bisa dibuka hanya dengan nomor invoice.

### Pengiriman produk

| Tipe varian | Perilaku setelah pembayaran terverifikasi |
|---|---|
| `auto` | Ambil stok `available` → tandai `delivered` → salin isinya ke `order_deliveries` (terenkripsi). Isi yang sama tidak pernah dikirim dua kali. |
| `file` | Kirim daftar tautan unduhan dari varian. |
| `manual` | Pesanan tetap `processing`; admin menandai selesai sambil menempelkan isi akun. |

Kalau stok kurang saat pengiriman, pesanan tetap `processing` dan admin
diberi tahu di dashboard (bukan gagal diam-diam).

### Alur reseller manual (beli di supplier seperti itemku)

Banyak produk digital (top up, voucher, akun) dibeli manual di marketplace
lalu diteruskan ke pembeli. Itu didukung langsung:

1. Di **Produk → Varian**, isi bagian *Supplier — pembelian manual*:
   nama supplier, ID/SKU produk, tautan produk, dan **harga modal**. Margin
   per unit langsung terlihat (`harga jual − modal`).
2. Isi **Data wajib dari pembeli** (satu baris satu label, maks. 5), mis.
   `Player ID` + `Zone ID` untuk top up, atau `Email Canva` untuk produk
   invite. Label ini tampil di halaman produk dan wajib diisi saat checkout.
3. Ada pesanan masuk → buka detail pesanan: ada kotak **Beli ke supplier**
   berisi tautan produk, ID supplier, daftar data pembeli (dengan tombol
   salin), dan margin. Setelah membeli, tempel hasilnya di form
   *Tandai terkirim & catat isinya* seperti biasa.

Modal dan referensi supplier **disalin ke `order_items` saat pesanan dibuat**,
jadi mengubah harga modal di kemudian hari tidak mengubah margin pesanan lama.
Label data pembeli selalu diambil dari server (varian), bukan dari form —
klien hanya boleh mengirim nilainya.

> Catatan: data wajib diisi **satu kali per baris pesanan**, jadi kalau pembeli
> membeli 2 unit dengan Player ID berbeda, mereka perlu memesan dua kali.
> Per-unit input belum dibuat supaya form checkout tidak berubah rumit.

Contoh siap pakai: varian *Canva Invite Edu Lifetime* sudah diisi supplier
`itemku`, modal Rp 6.000 (jual Rp 10.000), dan data wajib `Email Canva`.

---

## 3. Panel admin

| Menu | Isi |
|---|---|
| Dashboard | Pendapatan hari ini/30 hari, grafik 14 hari, produk terlaris, stok kritis, ulasan menunggu |
| Produk & Varian | CRUD produk, varian (harga, tipe pengiriman, instruksi, tautan, **supplier + harga modal + data wajib pembeli**), unggah gambar, SEO, soft delete |
| Stok | Impor massal (satu baris satu akun), status per item, hitungan tersedia/reserved/terkirim |
| Pesanan | Filter pembayaran/status, konfirmasi bayar, kirim otomatis, kirim manual, **kotak beli ke supplier + data pembeli + margin**, catatan, batalkan (hanya pesanan belum lunas), bersihkan kedaluwarsa |
| Ulasan | Moderasi (setujui/tolak/hapus) + peringatan konten tak aman |
| Voucher | Nominal/persen, minimal belanja, batas potongan, kuota, batas per pembeli, periode |
| Pengaturan | Identitas toko, QRIS, kredensial Tripay (tersimpan terenkripsi), mode pembayaran, TTL order |
| Log Aktivitas | Jejak audit aksi admin (tidak ada di situs asal) |

`Pengaturan` dan `Pengguna` hanya untuk role **admin**. Role **staff** tidak
melihat menunya dan akan mendapat `403` kalau URL-nya diakses langsung.

### Tampilan admin di HP (drawer + bottom nav)

Panel admin punya tiga lapis navigasi yang berbagi isi yang sama
(`admin/_nav-links.blade.php`, `_sidebar-head`, `_sidebar-user`):

- **≥1024px**: sidebar tetap 256px di kiri seperti sebelumnya.
- **<1024px**: sidebar diganti **drawer** geser dari kiri (tombol hamburger di
  header + tombol "Lainnya" di bottom nav) — backdrop gelap, klik luar/Escape/
  pilih tautan menutupnya, `body` dikunci saat terbuka, dan `aria-expanded`
  ikut diperbarui. `initAdminNav()` di `app.js`.
- **Bottom nav** 4 pintasan (Dashboard, Produk, Pesanan, Ulasan) + "Lainnya";
  item aktif ditandai chip `brand-50` + teks brand. `<main>` diberi
  `pb-24` di mobile supaya konten tidak tertutup nav, dan nav memakai
  `env(safe-area-inset-bottom)` untuk iPhone.

Layar sentuh juga dinaikkan target sentuhnya (`min-height: 2.25rem` untuk
`.btn-sm`, pill header `py-2`) lewat `@media (max-width: 1023px)` di
`resources/css/app.css`; `.table-wrap` diberi `-webkit-overflow-scrolling`.

### Notifikasi pesanan masuk (realtime di panel admin)

Bell di kepala panel (di samping judul halaman) memantau pesanan baru lewat
polling ringan `GET /admin/pesanan/live` (~8 detik, JSON dua aggregate + 10
pesanan terakhir, route `admin.orders.live` — tanpa websocket supaya server
PHP biasa tetap sanggup):

- badge di bell + angka pada pill "Belum bayar"/"Perlu kirim" ikut
  menyegarkan otomatis;
- daftar 10 pesanan terbaru bisa dibuka dari bell (tautan langsung ke
  halaman pesanan); dirender lewat `textContent`, bukan `innerHTML`;
- pesanan yang belum pernah terlihat di tab tersebut memunculkan toast di
  atas layar + bunyi pendek (Web Audio, tanpa berkas eksternal; beralih tab
  atau autoplay menolak pun diam-diam dilewati).

Konfirmasi aksi destruktif di admin memakai atribut `data-confirm` yang
ditangani listener di `app.js` — `onsubmit="return confirm(...)"` inline
tidak dipakai lagi karena CSP `script-src 'self'` memblokirnya.

---

## 4. Konfigurasi penting

Semua diatur dari halaman **Pengaturan** (tabel `settings`), bukan di-hardcode
di JavaScript seperti situs asal:

| Key | Fungsi |
|---|---|
| `store_name`, `store_tagline`, `store_whatsapp` | Identitas toko & nomor WA tujuan |
| `payment_mode` | `manual` · `gateway` · `both` |
| `qris_image`, `qris_holder` | QRIS statis untuk pembayaran manual |
| `tripay_mode`, `tripay_merchant_code`, `tripay_api_key`, `tripay_private_key`, `tripay_channel` | Gateway otomatis |
| `order_ttl_minutes` | Batas waktu bayar sebelum order kedaluwarsa |
| `auto_approve_reviews` | Ulasan langsung tayang atau masuk moderasi |
| Ulasan opsional | Rating **tidak pernah diwajibkan**: form ulasan (field `required`) tidak pernah bersarang di dalam form keranjang, jadi tombol "+ Keranjang"/"Beli Sekarang" tidak pernah diblokir validasi rating. `canReview()` hanya menampilkan form untuk pembeli produk **itu sendiri** (ada order berisi produk ini + invoice di session + belum mengulas), bukan untuk siapa pun yang punya pesanan lain di session. |
| `store_logo`, `store_banner` | Logo & banner halaman depan (kosong = monogram dari nama toko / banner gradien) |
| `show_sold_count` | Tampilkan angka "Terjual" hasil scrape di kartu produk |
| `show_social_proof` | Tampilkan notifikasi pembelian terbaru (nama pembeli selalu disamarkan) |

`tripay_private_key` dan `tripay_api_key` disimpan **terenkripsi**
(Laravel `Crypt`) dan tidak pernah ditampilkan kembali di form.

### Logo & wordmark mengikuti nama toko

Semua merek di UI diambil dari `store_name` — tidak ada lagi hardcode
`DIGITALHUB`/`DH` seperti situs asal:

- **`Setting::logoMonogram()`** → 2 huruf dari nama toko (`Creative Solutions`
  → `CS`, `Toko` → `TO`), dipakai sebagai logo di header storefront, sidebar
  panel admin, dan halaman login ketika admin belum mengunggah logo.
- **`Setting::logoWordmark()`** → nama toko dipecah dua; kata terakhir diberi
  warna brand (`Creative **Solutions**`).
- **`Setting::hasCustomLogo()`** + `logoUrl()` → kalau admin mengunggah
  `store_logo`, gambar itu yang dipakai di semua tempat (header, sidebar admin,
  login, invoice, favicon); monogram tidak dirender.
- Kunci pengaturan bawaan dipusatkan di `Setting::kunciBawaan()`, dipakai
  `SettingSeeder` **dan** `SettingController::edit()` — jadi instalasi lama yang
  belum punya baris `store_logo`/`store_banner` otomatis ditambal saat panel
  Pengaturan dibuka (tidak perlu `migrate:fresh --seed`).

URL webhook untuk dashboard Tripay:

```
POST {APP_URL}/webhook/tripay
```

Diverifikasi dengan HMAC SHA-256 atas body mentah memakai private key.
Permintaan tanpa tanda tangan valid langsung dijawab `403`.

---

## 5. Tampilan storefront (meniru situs asal)

Tampilan storefront sengaja dibuat **sama persis** dengan `digitalhubstore.my.id`.
Acuan desain diambil dari **DOM hasil render** situs asal (bukan dari bundle JS-nya),
lewat browser sungguhan (`browser-harness` / CDP), lalu disimpan di
`/tmp/opencode/dom/` sebagai rujukan sementara.

Token desain yang dipakai apa adanya:

| Aspek | Nilai |
|---|---|
| Font | `Outfit` (Google Fonts) untuk seluruh UI |
| Warna brand | `#f5125e`, gradient `#f5125e → #ff2b56`, hover `#d40c4e` |
| Body | `bg-slate-50 text-slate-800 min-h-screen pb-20 md:pb-10 antialiased` |
| Header | `sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-rose-100/80` |
| Grid katalog | `grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6` |
| Menu kategori | `grid-cols-4 md:grid-cols-7`, kotak ikon `rounded-2xl` 48px |
| Ikon | paket `lucide` + atribut `data-lucide`, ditukar `createIcons()` |

Halaman depan meniru SPA asal: trust bar 3 kolom → menu kategori berikon →
"Produk Terlaris & Paling Diminati" → grid katalog + pilihan urutan → FAQ →
notifikasi pembelian terbaru + tombol bantuan melayang.

Detail yang ikut disalin apa adanya:

- **Banner** hanya tampil kalau `store_banner` diisi admin — situs asal juga
  tidak menampilkan banner apa pun saat belum ada gambar (tidak ada banner
  gradien tiruan).
- **Menu kategori** memakai urutan, ikon, warna, dan **label** yang sama dengan
  situs asal. Label menu asli memang beda dari nama kategori di data produk
  (`Editor` → "App Pro", `AI` → "Akun AI", `Landing` → "Landing Page",
  `Stream` → "Streaming", `meeting` → "Jasa Web"; lihat `$menu` +
  `$order` di `resources/views/components/category-menu.blade.php`).
- **Pilihan urutan**: Paling Sesuai · 🔥 Paling Terlaris · 💰 Harga Terendah ·
  💎 Harga Tertinggi · ⭐ Rating Tertinggi (5 opsi, sama seperti situs asal).
  "Paling Sesuai" = urutan katalog apa adanya (`products.sort_order`).
  Nilai `urut=terbaru` masih diterima lewat URL walau tidak ditampilkan di
  dropdown, karena situs asal juga tidak punya opsi itu.
- **Tombol kamera** di dalam kolom pencarian disalin tampilannya; karena
  pencarian visual belum ada di sisi server, kliknya mengarahkan pembeli
  mengirim foto produk ke Chat CS (bukan tombol mati).
- **Tombol bantuan melayang** (kanan bawah) + bubble "Butuh bantuan? Chat kami"
  dengan animasi muncul-hilang berkala, sama seperti situs asal.
- **Popup "Cek Transaksi"** menyalin `history-modal` + `invoice-preview-modal`
  situs asal (dua lapis) termasuk inline style lembar E-Invoice-nya — lihat §2
  untuk bedanya di sisi pencarian & data yang ditampilkan.

### Perbedaan yang disengaja

Arsitektur asli adalah satu halaman SPA dengan modal/drawer; aplikasi ini
multi-halaman. Yang disamakan adalah **bahasa visualnya**, bukan mekanisme
modalnya:

| Situs asal | Aplikasi ini |
|---|---|
| Klik produk → modal detail (bottom sheet) | Halaman `/produk/{slug}` bergaya kartu modal yang sama |
| Keranjang = drawer samping | Halaman `/keranjang` bergaya isi drawer |
| Checkout = modal "Kasir DigitalHub" | Halaman `/checkout` bergaya modal kasir |
| "Cek Transaksi"/"Riwayat Pesanan" = modal dua lapis (kartu + lembar E-Invoice) | Popup yang sama disalin apa adanya, dua lapis juga (`resources/views/components/cek-transaksi.blade.php`) |
| Invoice lengkap = bagian dari modal | Halaman `/pesanan/{invoice}/invoice` untuk cetak lengkap (butuh sesi pemilik) |
| Item bottom-nav "App" (install PWA) | "Katalog" — belum ada service worker |
| Tombol "Install Aplikasi" di header | Belum ada (mengikuti PWA di atas) |
| Artikel ulasan tidak ada | Ada (server-side, hanya pembeli produk bisa mengirim) |
| Angka "Terjual" & rating marketing | Dipertahankan, tapi rating asli dari ulasan ditampilkan di halaman produk |
| Pencarian gambar (kamera) | Belum ada; tombolnya diarahkan ke Chat CS |
| Notifikasi pembelian = data palsu | Pesanan nyata, nama pembeli disamarkan (`Budi S.`) |

Semua aksi tetap berjalan tanpa JavaScript (form HTML biasa); JS hanya
memperhalus (ikon, stepper, slider, sortir, filter ulasan, hitung mundur).
Tidak ada HTML inline di view — CSP produksi tetap `script-src 'self'`.

> **Catatan URL aset.** Semua URL berkas disk `public` dibuat lewat helper
> `storage_url()` (`asset('storage/...')`), **bukan** `Storage::url()`, karena
> `Storage::url()` selalu memakai `config('app.url')` sehingga gambar rusak
> kalau aplikasi diakses dari domain/port yang berbeda dari `APP_URL`.

---

## 6. Keamanan yang diperbaiki

| Celah di situs asal | Penanganan di sini |
|---|---|
| `GET /orders` publik berisi nama/email/WA pembeli | Data pesanan hanya terbuka lewat sesi pemiliknya |
| `GET /stats` publik berisi IP pengunjung | Tidak ada endpoint statistik publik |
| `POST /` menimpa seluruh katalog tanpa auth | Katalog hanya bisa diubah lewat panel berpassword |
| `/orders/delete`, `/orders/update-status` tanpa auth | Semua aksi admin di belakang `auth` + `admin` |
| `/admin` tanpa password | Login + rate limit 5 percobaan/menit, akun bisa dinonaktifkan |
| Harga dihitung di browser | Harga, potongan, dan stok selalu dihitung ulang di server |
| Stored XSS di ulasan (`<script>` benar-benar berjalan) | Semua teks pengguna dirender lewat `safe_text()` |
| Kredensial akun tersimpan polos + bocor di JS | `Crypt` at-rest; stok tidak pernah ditampilkan di UI |
| Riwayat pesanan (`localStorage`) menampilkan akun orang lain di browser yang sama | Akses dua tingkat: status boleh dibuka, isi akun hanya setelah nomor WhatsApp diverifikasi (5 percobaan/10 menit) dan nomor WA selalu tersamar |
| Riwayat bisa dicari dengan **nomor WhatsApp/email** (`GET /orders?wa=`) dan seluruh data pesanan (termasuk isi akun) ditanam base64 di HTML | Popup "Cek Transaksi" hanya menerima **nomor invoice** (8 percobaan/5 menit per IP), tidak menaruh data pesanan di HTML, menyamarkan email/WA, dan tidak pernah memuat isi akun |

Detail temuan lengkap ada di `../ANALYSIS.md`.

---

## 7. Seeding katalog

`CatalogSeeder` membaca `../data/seed/*.json` (hasil `tools/build_seed.py` dari
scrape). Sumber bisa diubah lewat `CATALOG_SEED_DIR`. Seeder idempoten —
kuncinya `external_id` produk/varian/ulasan, jadi bisa dijalankan berulang:

```bash
php artisan migrate:fresh --seed    # skema baru + katalog
php artisan db:seed --class=CatalogSeeder   # hanya katalog
```

Catatan tentang data hasil scrape:

- Angka "terjual" dari situs asal tidak bisa diverifikasi → disimpan sebagai
  `sold_count` dengan tanda `sold_is_estimate = true`.
- Varian hanya bertipe `manual` atau `file`; kredensial akun yang bocor di
  situs asal **tidak** diimpor (148 entri dibuang oleh `tools/build_seed.py`),
  jadi `stock_items` sengaja kosong dan admin mengisi stok sendiri.
- `stock_original` menyimpan angka stok marketing situs asal (sering ribuan
  slot placeholder) supaya perbandingannya masih bisa dilihat di halaman stok.

---

## 8. Test

```bash
php artisan test        # 76 test, 407 asersi
```

Cakupan: integritas checkout (harga/voucher/stok dihitung server, honeypot,
data wajib pembeli), akses pesanan per-sesi (termasuk kunci isi akun), popup
"cek transaksi" (hanya nomor invoice; tanpa isi akun; kontak tersamar), ulasan,
akses admin & role staff, pengiriman otomatis/manual, webhook Tripay (termasuk
signature salah), dan alur reseller manual (referensi supplier, modal → margin,
snapshot order lama).

Test memakai SQLite in-memory (`phpunit.xml`) dan meng-`migrate:fresh` sendiri,
jadi tidak menyentuh database MySQL pengembangan.

> **Penting:** kalau pernah menjalankan `php artisan config:cache`, jalankan
> `php artisan optimize:clear` dulu. Selama `bootstrap/cache/config.php` ada,
> Laravel tidak membaca `.env` sehingga test bisa memakai MySQL dan
> `RefreshDatabase` mengosongkan data pengembangan. `tests/TestCase.php`
> sudah menolak berjalan kalau koneksinya bukan SQLite, jadi kegagalannya
> kelihatan (bukan data hilang diam-diam).

Yang diuji antara lain: total tidak bisa dimanipulasi klien, voucher dihitung
ulang dan dibatasi, stok tidak bisa diambil dobel, reservasi stok dikembalikan
saat order dibatalkan/kedaluwarsa, pesanan tertutup bagi orang lain, isi akun
tidak ikut terkirim sebelum nomor WhatsApp diverifikasi (dan nomor itu tersamar),
XSS di-escape saat dirender, webhook menolak tanda tangan palsu, aksi admin
idempoten, dan pemisahan hak akses admin vs staff.

---

## 9. Struktur direktori singkat

```
app/
  Http/Controllers/{Storefront,Admin,Auth,Webhook}/
  Http/Middleware/{EnsureUserIsAdmin,SecurityHeaders}.php
  Models/            Category Product ProductImage Variant StockItem Coupon
                     Order OrderItem OrderDelivery Review Setting ActivityLog User
  Services/          Cart CouponService OrderService DeliveryService TripayService
  Support/helpers.php   rupiah() wa_normalize() safe_text() status_badge()
database/
  migrations/ seeders/     CatalogSeeder membaca ../data/seed
resources/
  css/app.css        tema Tailwind v4 (font Outfit, warna brand #f5125e, komponen btn/card/table)
  js/app.js          ikon lucide (createIcons), toast, salin, pemilih varian, slider, filter ulasan,
                     stepper jumlah, hitung mundur pembayaran, polling status
  views/             layouts/ components/ storefront/ admin/ auth/
public/images/logo.png  logo toko bawaan (sama dengan situs asal)
routes/web.php       storefront + keranjang + checkout + webhook + /admin
docker-compose.yml   MySQL 8.4 (dhs-mysql, host port 3307)
```

---

## 10. Catatan operasional

- **Jangan hapus `APP_KEY`.** Isi stok, catatan admin, dan kredensial gateway
  terenkripsi dengan kunci ini; kalau hilang, data lama tidak bisa dibuka.
- Order yang lewat batas bayar ditandai kedaluwarsa lewat tombol
  "Bersihkan order kedaluwarsa" di halaman Pesanan, dan stoknya dikembalikan.
  Ini juga sudah dijadwalkan otomatis tiap 10 menit di `routes/console.php`
  (`expire-stale-orders`), jadi cukup jalankan penjadwal Laravel:
  ```bash
  php artisan schedule:work        # pengembangan
  # produksi: cron tiap menit
  # * * * * * cd /path/to/store-app && php artisan schedule:run >> /dev/null 2>&1
  ```
- Untuk produksi: `APP_DEBUG=false`, `php artisan optimize`, dan pastikan
  `APP_URL` memakai HTTPS (skema dipaksa `https` saat `APP_ENV=production`).
