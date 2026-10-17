# DigitalHub Store Scraper

Scraper katalog untuk **https://digitalhubstore.my.id** — toko akun/produk digital
(ChatGPT, Gemini, Canva, Netflix, CapCut, dll).

Hasil scrape: **29 produk**, **59 varian**, **40 ulasan**, **29 gambar** produk.

> Hasil scrape dipakai untuk mengisi aplikasi pengganti di **`store-app/`**
> (Laravel 13 + Blade + MySQL 8). Cara menjalankan, akun admin, dan alur
> checkout→pengiriman ada di `store-app/README.md`. Temuan keamanan situs asal
> dan rencana perbaikannya ada di `ANALYSIS.md`.
>
> ```bash
> python3 tools/build_seed.py                 # export → data/seed/*.json
> cd store-app && docker compose up -d
> php artisan migrate --seed && npm run build && php artisan serve
> ```

## Cara kerja

Situsnya **SPA statis di GitHub Pages** — `index.html` berisi seluruh template +
logika, tanpa data produk. Data katalog diambil saat runtime dari satu
**Cloudflare Worker**:

| Bagian | Detail |
|---|---|
| Halaman | `https://digitalhubstore.my.id` (SPA, Tailwind CDN) |
| Admin | `https://digitalhubstore.my.id/admin` (juga statis) |
| API | `https://toko-digital-api.rizqinastore97.workers.dev` |

URL API tidak ditulis polos di HTML, tapi di-obfuscate base64 pada variabel
`_wEnc` lalu di-decode `atob()`:

```js
const _wEnc = "aHR0cHM6Ly90b2tlLWRpZ2l0YWwtYXBpLnJpenFpbmFzdG9yZTk3LndvcmtlcnMuZGV2";
const API_URL = atob(_wEnc);
```

Scraper meniru langkah itu (`dhs/client.py:decode_api_url`) sehingga tetap
jalan walau domain worker-nya diganti — kalau pola `_wEnc` berubah, ada
fallback konstanta.

Endpoint read-only yang dipakai:

| Endpoint | Metode | Fungsi |
|---|---|---|
| `GET /` | GET | katalog: produk + varian + harga + stok + gambar |
| `GET /reviews?productId=` | GET | ulasan per produk |
| halaman `/`, `/admin`, `/manifest.json` | GET | aset statis situs |

## Penggunaan

```bash
pip install -r requirements.txt

python3 scrape.py                      # aset + katalog + ulasan + export
python3 scrape.py --no-asets           # lewati simpan aset situs
python3 scrape.py --no-ulasan          # lewati ulasan (paling cepat)
python3 scrape.py --export-only        # export ulang dari SQLite
python3 scrape.py --extract-images     # tulis gambar ke data/images/
python3 scrape.py --mask-items         # sensor isi stok yang mirip kredensial
python3 scrape.py --probe              # cek skema endpoint sensitif (tidak disimpan)
```

Opsi: `--db`, `--out`, `--raw`, `--api-url`, `--workers`.

## Output

- `data/digitalhubstore.sqlite` — tabel `products`, `variants`, `reviews`,
  `config`, `meta` (idempoten, bisa dijalankan berulang)
- `data/raw/` — respons mentah: `api-catalog.json`, `api-reviews-*.json`,
  `index.html`, `admin`, `manifest.json`
- `data/export/` — `products.jsonl|.csv`, `variants.jsonl|.csv`,
  `reviews.jsonl|.csv`, `categories.csv`, `config.jsonl`
- `data/images/` — gambar produk (data-URI di-decode, URL diunduh)

Kolom penting produk: `id`, `name`, `category`, `warranty`, `description`,
`terms`, `sold`, `rating`, `hidden`, `variant_count`, `min_price`, `max_price`,
`total_stock`.

Kolom penting varian: `name`, `price`, `original_price`, `stock`,
`payment_type`, `item_count`, `items`.

## Catatan

- Harga, stok, dan jumlah "terjual" adalah angka yang ditampilkan situs apa
  adanya — tidak diverifikasi.
- Daftar `items` pada varian umumnya bukan kredensial, tapi instruksi/label
  slot stok (contoh: `[ 001 ] Silakan chat WhatsApp admin…`). Di CapCut PRO
  ada 8.400 slot; export otomatis meringkasnya jadi daftar unik + `item_count`.
- Ada produk yang stoknya benar-benar berisi kredensial akun. Pakai
  `--mask-items` kalau hasil export akan dibagikan.
- Temuan keamanan serius di API-nya: lihat `ANALYSIS.md`.
