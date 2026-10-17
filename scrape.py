#!/usr/bin/env python3
"""Scraper katalog DigitalHub Store (digitalhubstore.my.id).

Situs target adalah SPA statis (GitHub Pages) yang seluruh datanya dilayani
satu Cloudflare Worker. Scraper ini memakai API internal yang sama dengan
web app-nya, jadi hasilnya persis data yang dilihat pembeli:

  1. aset   - simpan index.html / admin.html / manifest.json apa adanya
  2. katalog- GET /            -> produk + varian + harga + stok + gambar
  3. ulasan - GET /reviews     -> ulasan per produk
  4. export - JSONL + CSV dari SQLite

Contoh:
  python3 scrape.py                 # aset + katalog + ulasan + export
  python3 scrape.py --no-ulasan     # lewati ulasan (lebih cepat)
  python3 scrape.py --export-only   # export ulang dari SQLite
  python3 scrape.py --extract-images
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from dhs import DigitalHubClient, DigitalHubError  # noqa: E402
from dhs.store import Store  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(HERE, "data", "digitalhubstore.sqlite")
OUT_DIR = os.path.join(HERE, "data", "export")
RAW_DIR = os.path.join(HERE, "data", "raw")

SITE_ASSETS = ["/", "/admin", "/manifest.json"]
CONFIG_KEYS = {
    "__BANNER_CONFIG__": "banner",
    "__PROMO0_CONFIG__": "promo0",
}


def log(msg: str) -> None:
    print(f"[{time.strftime('%H:%M:%S')}] {msg}", flush=True)


def save_raw(raw_dir: str, name: str, text: str) -> str:
    os.makedirs(raw_dir, exist_ok=True)
    path = os.path.join(raw_dir, name)
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(text)
    return path


# ----------------------------------------------------------------- fase aset
def fetch_assets(client: DigitalHubClient, raw_dir: str) -> None:
    log(f"menyimpan aset situs dari {client.site_url}…")
    for path in SITE_ASSETS:
        name = "index.html" if path == "/" else path.lstrip("/").replace("/", "_")
        try:
            text = client.get_text(path)
        except DigitalHubError as exc:
            log(f"  {path}: gagal ({exc})")
            continue
        saved = save_raw(raw_dir, name, text)
        log(f"  {path} -> {os.path.basename(saved)} ({len(text)} byte)")


# -------------------------------------------------------------- fase katalog
def is_product(item: dict) -> bool:
    return "name" in item and "variants" in item


def fetch_catalog(client: DigitalHubClient, store: Store, raw_dir: str) -> int:
    log(f"GET {client.api_url} (katalog)…")
    data = client.catalog()
    save_raw(raw_dir, "api-catalog.json", json.dumps(data, ensure_ascii=False))
    store.set_meta("api_url", client.api_url)
    store.set_meta("catalog_fetched_at", time.strftime("%Y-%m-%dT%H:%M:%S"))

    configs = 0
    products = 0
    for item in data:
        item_id = str(item.get("id"))
        key = CONFIG_KEYS.get(item_id)
        if key or item.get("_isBannerConfig") or item.get("_isPromo0Config"):
            store.upsert_config(key or item_id, item)
            configs += 1
            continue
        if not is_product(item):
            store.upsert_config(f"unknown:{item_id}", item)
            configs += 1
            continue
        store.upsert_product(item)
        products += 1

    log(f"  {products} produk, {configs} objek konfigurasi dari {len(data)} entri")
    return products


# ---------------------------------------------------------------- fase ulasan
def fetch_reviews(client: DigitalHubClient, store: Store, raw_dir: str, workers: int) -> int:
    ids = store.product_ids()
    log(f"mengambil ulasan {len(ids)} produk (workers={workers})…")
    total = 0

    def run(pid: str) -> tuple[str, list[dict]]:
        for attempt in range(3):
            try:
                return pid, client.reviews(pid)
            except DigitalHubError:
                time.sleep(1.0 * (attempt + 1))
        return pid, []

    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = [pool.submit(run, pid) for pid in ids]
        for done, future in enumerate(as_completed(futures), 1):
            pid, reviews = future.result()
            if reviews:
                total += store.upsert_reviews(pid, reviews)
                save_raw(raw_dir, f"api-reviews-{pid}.json", json.dumps(reviews, ensure_ascii=False))
            if done % 10 == 0 or done == len(ids):
                log(f"  {done}/{len(ids)} produk | {total} ulasan")

    log(f"ulasan selesai: {total} baris")
    return total


# -------------------------------------------------------------------- probe
def probe_sensitive(client: DigitalHubClient) -> None:
    """Tampilkan HANYA bentuk respons endpoint sensitif, tanpa isinya.

    Endpoint /orders dan /stats terbuka tanpa autentikasi dan berisi data
    pribadi pembeli + IP pengunjung. Fungsi ini sengaja tidak menyimpan
    satu pun nilainya.
    """
    log("probe endpoint sensitif (hanya skema, isi tidak disimpan)…")
    try:
        orders = client.api_get("/orders")
        if isinstance(orders, list) and orders:
            log(f"  /orders: {len(orders)} baris, kolom = {sorted(orders[0].keys())}")
        else:
            log(f"  /orders: {type(orders).__name__}")
    except DigitalHubError as exc:
        log(f"  /orders: gagal ({exc})")
    try:
        log(f"  /stats: {client.stats_schema()}")
    except DigitalHubError as exc:
        log(f"  /stats: gagal ({exc})")


# --------------------------------------------------------------------- main
def main() -> int:
    parser = argparse.ArgumentParser(description="Scraper katalog DigitalHub Store")
    parser.add_argument("--db", default=DB_PATH, help="path SQLite")
    parser.add_argument("--out", default=OUT_DIR, help="direktori export")
    parser.add_argument("--raw", default=RAW_DIR, help="direktori simpan respons mentah")
    parser.add_argument("--api-url", default=None, help="override base URL API")
    parser.add_argument("--workers", type=int, default=4, help="paralel fetch ulasan")
    parser.add_argument("--no-asets", action="store_true", help="lewati simpan aset situs")
    parser.add_argument("--no-ulasan", action="store_true", help="lewati fase ulasan")
    parser.add_argument("--export-only", action="store_true", help="export dari SQLite lalu keluar")
    parser.add_argument("--probe", action="store_true", help="cek skema endpoint sensitif (tidak disimpan)")
    parser.add_argument("--extract-images", action="store_true", help="tulis gambar produk ke data/images/")
    parser.add_argument("--mask-items", action="store_true", help="sensor isi stok yang mirip kredensial akun")
    args = parser.parse_args()

    store = Store(args.db)

    if args.export_only:
        paths = store.export(
            args.out,
            extract_images=args.extract_images,
            mask_items=args.mask_items,
            downloader=DigitalHubClient(api_url=args.api_url).get_bytes,
        )
        log("export selesai:")
        for name, path in paths.items():
            log(f"  {name}: {path}")
        log(f"jumlah: {store.counts()}")
        store.close()
        return 0

    client = DigitalHubClient(api_url=args.api_url)
    log(f"URL API: {client.api_url}")

    try:
        if not args.no_asets:
            fetch_assets(client, args.raw)
        fetch_catalog(client, store, args.raw)
        if not args.no_ulasan:
            fetch_reviews(client, store, args.raw, args.workers)
        if args.probe:
            probe_sensitive(client)
    except KeyboardInterrupt:
        log("dihentikan user")
    except DigitalHubError as exc:
        log(f"ERROR: {exc}")
        store.close()
        return 1
    finally:
        paths = store.export(
            args.out,
            extract_images=args.extract_images,
            mask_items=args.mask_items,
            downloader=client.get_bytes,
        )
        log(f"total: {store.counts()}")
        log(f"output: {os.path.dirname(paths['products_jsonl'])}")
        store.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
