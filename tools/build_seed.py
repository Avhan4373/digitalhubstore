#!/usr/bin/env python3
"""Konversi hasil scrape -> seed JSON siap pakai untuk store-app (Laravel).

Sumber: data/export/{products,variants,reviews}.jsonl + data/images/
Output: data/seed/{categories,products,variants,reviews}.json

Keputusan pembersihan (lihat ANALYSIS.md bagian "Anomali data"):

* `items` varian di situs asal mayoritas BUKAN inventaris, tapi teks instruksi
  klaim berulang ("Silakan chat WhatsApp admin…"). Teks itu dipindahkan ke
  `delivery_instruction` (dedup) dan TIDAK dihitung sebagai stok.
* Entri `items` yang benar-benar berisi kredensial akun pihak ketiga
  (pola `email|password`) DIBUANG, bukan diimpor — itu akun milik penjual lain.
  Jumlah yang dibuang dilaporkan di akhir.
* Angka `sold` dipertahankan sebagai `sold_count` tapi ditandai
  `sold_is_estimate` karena tidak bisa diverifikasi.
* `stock` asal disimpan sebagai `stock_original` untuk referensi; stok nyata
  dihitung dari `stock_items` yang diimpor.
"""

from __future__ import annotations

import json
import os
import re
import sys
from collections import Counter

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
EXPORT_DIR = os.path.join(HERE, "data", "export")
SEED_DIR = os.path.join(HERE, "data", "seed")
IMAGE_DIR = os.path.join(HERE, "data", "images")

CRED_PATTERN = re.compile(
    r"(password|passwd|pwd\s*[:=]|login\s*[:=]|email\s*[:=]|:@|"
    r"^[^\s@|]+@[^\s@|]+\.[a-z]{2,}\s*\|)",
    re.IGNORECASE,
)
URL_PATTERN = re.compile(r"^https?://", re.IGNORECASE)


def slugify(value: str) -> str:
    value = (value or "").strip().lower()
    value = re.sub(r"[^a-z0-9]+", "-", value)
    return re.sub(r"-{2,}", "-", value).strip("-") or "produk"


def read_jsonl(name: str) -> list[dict]:
    path = os.path.join(EXPORT_DIR, name)
    if not os.path.exists(path):
        sys.exit(f"file tidak ada: {path} — jalankan `python3 scrape.py` dulu")
    with open(path, encoding="utf-8") as fh:
        return [json.loads(line) for line in fh if line.strip()]


def classify_items(raw_items: str) -> tuple[list[str], list[str], list[str]]:
    """Pisahkan items jadi (instruksi, tautan_file, kredensial_dibuang)."""
    try:
        items = json.loads(raw_items or "[]")
    except ValueError:
        return [], [], []

    instructions: list[str] = []
    links: list[str] = []
    dropped: list[str] = []

    for entry in items:
        if not isinstance(entry, str) or not entry.strip():
            continue
        text = entry.strip()
        if CRED_PATTERN.search(text):
            dropped.append(text)
        elif URL_PATTERN.match(text):
            links.append(text)
        else:
            instructions.append(text)
    return instructions, links, dropped


def main() -> int:
    os.makedirs(SEED_DIR, exist_ok=True)
    products = read_jsonl("products.jsonl")
    variants = read_jsonl("variants.jsonl")
    reviews = read_jsonl("reviews.jsonl")

    variants_by_product: dict[str, list[dict]] = {}
    for v in variants:
        variants_by_product.setdefault(v["product_id"], []).append(v)

    # ------------------------------------------------------------------ kategori
    cats = sorted({(p.get("category") or "Lainnya").strip() for p in products})
    categories = [
        {"name": c, "slug": slugify(c), "sort_order": i}
        for i, c in enumerate(cats)
    ]
    cat_slug = {c["name"]: c["slug"] for c in categories}

    # ------------------------------------------------------------------- produk
    dropped_credentials = 0
    seed_products, seed_variants = [], []
    images_missing = 0

    for order, p in enumerate(sorted(products, key=lambda x: -(x.get("sold") or 0))):
        pid = str(p["id"])
        image_file = None
        for ext in ("webp", "jpg", "png", "jpeg", "gif", "svg"):
            candidate = os.path.join(IMAGE_DIR, f"{pid}.{ext}")
            if os.path.exists(candidate):
                image_file = f"products/{pid}.{ext}"
                break
        if image_file is None:
            images_missing += 1

        category = (p.get("category") or "Lainnya").strip()
        # Nama duplikat ("ChatGPT GO" vs lain-lain) -> slug unik pakai id.
        slug = f"{slugify(p.get('name'))}-{pid[-5:]}"

        seed_products.append(
            {
                "external_id": pid,
                "name": p.get("name") or "Tanpa nama",
                "slug": slug,
                "category_slug": cat_slug[category],
                "description": p.get("description") or "",
                "terms": p.get("terms") or "",
                "warranty": p.get("warranty") or "",
                "payment_type": p.get("payment_type") or "default",
                "is_best_seller": bool(p.get("is_best_seller")),
                "is_hot": bool(p.get("is_hot")),
                "is_active": not bool(p.get("hidden")),
                "sold_count": int(p.get("sold") or 0),
                "sold_is_estimate": True,
                "rating_source": str(p.get("rating") or ""),
                "cover_image": image_file,
                "sort_order": order,
            }
        )

        for idx, v in enumerate(sorted(variants_by_product.get(pid, []), key=lambda x: x["idx"])):
            instructions, links, dropped = classify_items(v.get("items"))
            dropped_credentials += len(dropped)

            # Instruksi unik; buang prefix penomoran "[ 001 ] " supaya tidak
            # menyimpan 400 baris identik.
            cleaned: list[str] = []
            for text in dict.fromkeys(instructions):
                cleaned.append(re.sub(r"^\[\s*\d+\s*\]\s*", "", text))
            instruction = "\n".join(dict.fromkeys(cleaned))

            seed_variants.append(
                {
                    "external_id": f"{pid}:{v['idx']}",
                    "product_external_id": pid,
                    "name": v.get("name") or "Default",
                    "price": int(v.get("price") or 0),
                    "original_price": int(v.get("original_price") or 0),
                    "stock_original": int(v.get("stock") or 0),
                    "stock_items_imported": 0,
                    "delivery_instruction": instruction,
                    "delivery_links": links,
                    "description": v.get("desc") or "",
                    "terms": v.get("terms") or "",
                    "payment_type": v.get("payment_type") or "default",
                    "is_active": True,
                    "sort_order": idx,
                }
            )

    # ------------------------------------------------------------------ ulasan
    rating_counts = Counter(r["rating"] for r in reviews if r.get("rating"))
    seed_reviews = [
        {
            "external_id": r["id"],
            "product_external_id": str(r.get("product_id") or ""),
            "user_name": (r.get("user") or "Pembeli").strip()[:60],
            "rating": int(r.get("rating") or 0),
            "comment": r.get("comment") or "",
            "variant_name": r.get("variant") or "",
            "created_at": r.get("createdAt") or r.get("date") or "",
            # Ulasan situs asal sudah publik & disetujui -> langsung approved,
            # tapi semua HTML di-escape saat render.
            "status": "approved",
        }
        for r in reviews
        if r.get("comment")
    ]

    write(os.path.join(SEED_DIR, "categories.json"), categories)
    write(os.path.join(SEED_DIR, "products.json"), seed_products)
    write(os.path.join(SEED_DIR, "variants.json"), seed_variants)
    write(os.path.join(SEED_DIR, "reviews.json"), seed_reviews)

    print(f"kategori          : {len(categories)}")
    print(f"produk            : {len(seed_products)}")
    print(f"varian            : {len(seed_variants)}")
    print(f"ulasan            : {len(seed_reviews)} (rating {dict(sorted(rating_counts.items()))})")
    print(f"gambar hilang     : {images_missing}")
    print(f"kredensial dibuang: {dropped_credentials} entri (akun pihak ketiga, tidak diimpor)")
    print(f"output            : {SEED_DIR}")
    return 0


def write(path: str, data: object) -> None:
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(data, fh, ensure_ascii=False, indent=1)


if __name__ == "__main__":
    raise SystemExit(main())
