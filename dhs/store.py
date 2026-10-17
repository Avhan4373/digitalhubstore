"""Penyimpanan SQLite + export JSONL/CSV untuk katalog DigitalHub Store."""

from __future__ import annotations

import csv
import json
import os
import re
import sqlite3
import time
from typing import Any, Callable, Iterable

SCHEMA = """
CREATE TABLE IF NOT EXISTS products (
    id              TEXT PRIMARY KEY,
    name            TEXT,
    category        TEXT,
    warranty        TEXT,
    payment_type    TEXT,
    description     TEXT,
    terms           TEXT,
    is_best_seller  INTEGER DEFAULT 0,
    is_hot          INTEGER DEFAULT 0,
    sold            INTEGER DEFAULT 0,
    rating          TEXT,
    hidden          INTEGER DEFAULT 0,
    image           TEXT,
    image_kind      TEXT,
    images          TEXT,
    variant_count   INTEGER DEFAULT 0,
    min_price       INTEGER DEFAULT 0,
    max_price       INTEGER DEFAULT 0,
    total_stock     INTEGER DEFAULT 0,
    custom_reviews  INTEGER DEFAULT 0,
    detail_fetched  INTEGER DEFAULT 1,
    raw_json        TEXT,
    updated_at      TEXT
);

CREATE TABLE IF NOT EXISTS variants (
    row_id          INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id      TEXT NOT NULL,
    idx             INTEGER,
    name            TEXT,
    price           INTEGER DEFAULT 0,
    original_price  INTEGER DEFAULT 0,
    stock           INTEGER DEFAULT 0,
    desc            TEXT,
    terms           TEXT,
    payment_type    TEXT,
    items           TEXT,
    item_count      INTEGER DEFAULT 0,
    raw_json        TEXT,
    UNIQUE (product_id, idx)
);

CREATE TABLE IF NOT EXISTS reviews (
    id              TEXT PRIMARY KEY,
    product_id      TEXT,
    user            TEXT,
    rating          INTEGER,
    comment         TEXT,
    image           TEXT,
    variant         TEXT,
    created_at      TEXT,
    date            TEXT,
    avatar_color    TEXT,
    raw_json        TEXT
);

CREATE TABLE IF NOT EXISTS config (
    key             TEXT PRIMARY KEY,
    value_json      TEXT
);

CREATE TABLE IF NOT EXISTS meta (
    key             TEXT PRIMARY KEY,
    value           TEXT
);

CREATE INDEX IF NOT EXISTS idx_variants_product ON variants (product_id);
CREATE INDEX IF NOT EXISTS idx_reviews_product ON reviews (product_id);
"""


class Store:
    def __init__(self, db_path: str) -> None:
        self.db_path = db_path
        os.makedirs(os.path.dirname(os.path.abspath(db_path)), exist_ok=True)
        self.conn = sqlite3.connect(db_path)
        self.conn.row_factory = sqlite3.Row
        self.conn.executescript(SCHEMA)
        self.conn.commit()

    def close(self) -> None:
        self.conn.commit()
        self.conn.close()

    # ------------------------------------------------------------------ meta
    def set_meta(self, key: str, value: Any) -> None:
        self.conn.execute(
            "INSERT INTO meta (key, value) VALUES (?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            (key, json.dumps(value, ensure_ascii=False) if not isinstance(value, str) else value),
        )
        self.conn.commit()

    def counts(self) -> dict[str, int]:
        out: dict[str, int] = {}
        for table in ("products", "variants", "reviews"):
            out[table] = self.conn.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0]
        return out

    # --------------------------------------------------------------- products
    def upsert_config(self, key: str, value: Any) -> None:
        self.conn.execute(
            "INSERT INTO config (key, value_json) VALUES (?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value_json = excluded.value_json",
            (key, json.dumps(value, ensure_ascii=False)),
        )
        self.conn.commit()

    def upsert_product(self, item: dict, detail_fetched: int = 1) -> None:
        pid = str(item.get("id"))
        variants = item.get("variants") or []
        prices = [int(v.get("price") or 0) for v in variants if v.get("price")]
        stock = sum(int(v.get("stock") or 0) for v in variants)
        custom_reviews = item.get("customReviews") or []
        image = item.get("image") or ""

        self.conn.execute(
            """
            INSERT INTO products (
                id, name, category, warranty, payment_type, description, terms,
                is_best_seller, is_hot, sold, rating, hidden, image, image_kind,
                images, variant_count, min_price, max_price, total_stock,
                custom_reviews, detail_fetched, raw_json, updated_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT(id) DO UPDATE SET
                name=excluded.name, category=excluded.category,
                warranty=excluded.warranty, payment_type=excluded.payment_type,
                description=excluded.description, terms=excluded.terms,
                is_best_seller=excluded.is_best_seller, is_hot=excluded.is_hot,
                sold=excluded.sold, rating=excluded.rating, hidden=excluded.hidden,
                image=excluded.image, image_kind=excluded.image_kind,
                images=excluded.images, variant_count=excluded.variant_count,
                min_price=excluded.min_price, max_price=excluded.max_price,
                total_stock=excluded.total_stock,
                custom_reviews=excluded.custom_reviews,
                detail_fetched=excluded.detail_fetched,
                raw_json=excluded.raw_json, updated_at=excluded.updated_at
            """,
            (
                pid,
                item.get("name"),
                item.get("category"),
                item.get("warranty"),
                item.get("paymentType"),
                item.get("description"),
                item.get("terms"),
                int(bool(item.get("isBestSeller"))),
                int(bool(item.get("isHot"))),
                int(item.get("sold") or 0),
                str(item.get("rating") or ""),
                int(bool(item.get("hidden"))),
                image,
                _image_kind(image),
                json.dumps(item.get("images") or [], ensure_ascii=False),
                len(variants),
                min(prices) if prices else 0,
                max(prices) if prices else 0,
                stock,
                len(custom_reviews),
                detail_fetched,
                json.dumps(_strip_binary(item), ensure_ascii=False),
                time.strftime("%Y-%m-%dT%H:%M:%S"),
            ),
        )

        for idx, variant in enumerate(variants):
            items = variant.get("items") or []
            self.conn.execute(
                """
                INSERT INTO variants (
                    product_id, idx, name, price, original_price, stock, desc,
                    terms, payment_type, items, item_count, raw_json
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(product_id, idx) DO UPDATE SET
                    name=excluded.name, price=excluded.price,
                    original_price=excluded.original_price, stock=excluded.stock,
                    desc=excluded.desc, terms=excluded.terms,
                    payment_type=excluded.payment_type, items=excluded.items,
                    item_count=excluded.item_count, raw_json=excluded.raw_json
                """,
                (
                    pid,
                    idx,
                    variant.get("name"),
                    int(variant.get("price") or 0),
                    int(variant.get("originalPrice") or 0),
                    int(variant.get("stock") or 0),
                    variant.get("desc"),
                    variant.get("terms"),
                    variant.get("paymentType"),
                    json.dumps(items, ensure_ascii=False),
                    len(items),
                    json.dumps(_strip_variant(variant), ensure_ascii=False),
                ),
            )
        self.conn.commit()

    def product_ids(self) -> list[str]:
        return [r[0] for r in self.conn.execute("SELECT id FROM products ORDER BY id")]

    # ---------------------------------------------------------------- reviews
    def upsert_reviews(self, product_id: str, reviews: Iterable[dict]) -> int:
        n = 0
        for rev in reviews:
            rid = str(rev.get("id") or f"{product_id}:{rev.get('createdAt')}:{rev.get('user')}")
            self.conn.execute(
                """
                INSERT INTO reviews (
                    id, product_id, user, rating, comment, image, variant,
                    created_at, date, avatar_color, raw_json
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(id) DO UPDATE SET
                    product_id=excluded.product_id, user=excluded.user,
                    rating=excluded.rating, comment=excluded.comment,
                    image=excluded.image, variant=excluded.variant,
                    created_at=excluded.created_at, date=excluded.date,
                    avatar_color=excluded.avatar_color, raw_json=excluded.raw_json
                """,
                (
                    rid,
                    product_id,
                    rev.get("user"),
                    int(rev.get("rating") or 0),
                    rev.get("comment"),
                    rev.get("image"),
                    rev.get("variant"),
                    rev.get("createdAt"),
                    rev.get("date"),
                    rev.get("avatarColor"),
                    json.dumps(rev, ensure_ascii=False),
                ),
            )
            n += 1
        self.conn.commit()
        return n

    # --------------------------------------------------------------- exports
    def export(
        self,
        out_dir: str,
        extract_images: bool = False,
        mask_items: bool = False,
        downloader: "Callable[[str], bytes] | None" = None,
    ) -> dict[str, str]:
        os.makedirs(out_dir, exist_ok=True)
        paths: dict[str, str] = {}

        products = [dict(r) for r in self.conn.execute("SELECT * FROM products ORDER BY sold DESC")]
        variants = [dict(r) for r in self.conn.execute("SELECT * FROM variants ORDER BY product_id, idx")]
        reviews = [dict(r) for r in self.conn.execute("SELECT * FROM reviews ORDER BY created_at DESC")]

        # Produk: image base64 dipisah supaya CSV/JSONL tetap ringan.
        slim_products = []
        for row in products:
            row = dict(row)
            image = row.pop("image", "") or ""
            images_json = row.pop("images", "[]") or "[]"
            row["image_kind"] = _image_kind(image)
            row["image_ref"] = image if image.startswith("http") else ""
            row["image_chars"] = len(image)
            try:
                row["image_count"] = len(json.loads(images_json))
            except ValueError:
                row["image_count"] = 0
            slim_products.append(row)

        for row in variants:
            try:
                items = json.loads(row.get("items") or "[]")
            except ValueError:
                items = []
            row["item_count"] = len(items)
            if mask_items:
                items = [_mask_item(x) for x in items]
            # Stok sering diisi ribuan entri identik; simpan versi unik saja.
            unique = list(dict.fromkeys(json.dumps(x, sort_keys=True) for x in items))
            row["items_unique"] = len(unique)
            row["items"] = json.dumps(
                [json.loads(x) if len(x) < 200 else x[:200] for x in unique], ensure_ascii=False
            )

        paths["products_jsonl"] = _write_jsonl(os.path.join(out_dir, "products.jsonl"), slim_products)
        paths["products_csv"] = _write_csv(os.path.join(out_dir, "products.csv"), slim_products)
        paths["variants_jsonl"] = _write_jsonl(os.path.join(out_dir, "variants.jsonl"), variants)
        paths["variants_csv"] = _write_csv(os.path.join(out_dir, "variants.csv"), variants)
        paths["reviews_jsonl"] = _write_jsonl(os.path.join(out_dir, "reviews.jsonl"), reviews)
        paths["reviews_csv"] = _write_csv(os.path.join(out_dir, "reviews.csv"), reviews)

        cat_rows = [
            {"category": cat, "products": n, "sold": sold}
            for cat, n, sold in self.conn.execute(
                "SELECT COALESCE(category,''), COUNT(*), SUM(sold) FROM products "
                "WHERE hidden = 0 GROUP BY 1 ORDER BY 2 DESC"
            )
        ]
        paths["categories_csv"] = _write_csv(os.path.join(out_dir, "categories.csv"), cat_rows)

        config_rows = [
            {"key": key, "value_json": value}
            for key, value in self.conn.execute("SELECT key, value_json FROM config ORDER BY key")
        ]
        paths["config_jsonl"] = _write_jsonl(os.path.join(out_dir, "config.jsonl"), config_rows)

        if extract_images:
            paths["images_dir"] = self._extract_images(
                os.path.join(out_dir, "..", "images"), downloader=downloader
            )

        return paths

    def _extract_images(self, img_dir: str, downloader: "Callable[[str], bytes] | None" = None) -> str:
        """Tulis gambar produk ke file: data-URI di-decode, URL diunduh."""
        os.makedirs(img_dir, exist_ok=True)
        saved = 0
        failed = 0
        for pid, image in self.conn.execute("SELECT id, image FROM products"):
            if not image:
                continue
            try:
                if image.startswith("data:"):
                    ext, blob = _split_data_uri(image)
                    if not blob:
                        failed += 1
                        continue
                elif image.startswith("http") and downloader is not None:
                    blob = downloader(image)
                    ext = _ext_from_url(image)
                else:
                    continue
                with open(os.path.join(img_dir, f"{pid}.{ext}"), "wb") as fh:
                    fh.write(blob)
                saved += 1
            except Exception:  # noqa: BLE001
                failed += 1
                continue
        return f"{img_dir} ({saved} file, {failed} gagal)"


# ------------------------------------------------------------------- helpers
CRED_PATTERN = re.compile(
    r"(password|passwd|pwd\s*[:=]|login\s*[:=]|email\s*[:=]|:@|"
    r"^[^\s@|]+@[^\s@|]+\.[a-z]{2,}\s*\|)",
    re.IGNORECASE,
)


def _mask_item(value: Any) -> Any:
    """Sensor entri stok yang mirip kredensial akun (email|password, dsb.)."""
    if not isinstance(value, str):
        return value
    if CRED_PATTERN.search(value):
        return f"<REDACTED: {len(value)} chars, terdeteksi kredensial>"
    return value


def _ext_from_url(url: str) -> str:
    tail = url.split("?")[0].rsplit("/", 1)[-1]
    ext = tail.rsplit(".", 1)[-1].lower() if "." in tail else "jpg"
    return ext if 1 <= len(ext) <= 5 and ext.isalnum() else "jpg"


def _image_kind(image: str) -> str:
    if image.startswith("data:"):
        return "data-uri"
    if image.startswith("http"):
        return "url"
    return "empty" if not image else "other"


def _split_data_uri(uri: str) -> tuple[str, bytes]:
    import base64

    head, _, payload = uri.partition(",")
    ext = "png"
    if "image/" in head:
        subtype = head.split("image/", 1)[1].split(";")[0]
        ext = {"jpeg": "jpg", "svg+xml": "svg"}.get(subtype, subtype)
    try:
        return ext, base64.b64decode(payload)
    except Exception:  # noqa: BLE001
        return ext, b""


def _strip_binary(item: dict) -> dict:
    """Salinan item tanpa blob gambar / daftar stok raksasa.

    Dipakai untuk `raw_json`: gambar base64 diganti placeholder, dan daftar
    stok varian diringkas jadi contoh unik + jumlahnya.
    """
    clean = dict(item)
    for key in ("image", "images"):
        value = clean.get(key)
        if isinstance(value, str) and len(value) > 500:
            clean[key] = f"<{_image_kind(value)}:{len(value)} chars>"
        elif isinstance(value, list):
            clean[key] = [
                v if not isinstance(v, str) or len(v) <= 500 else f"<{_image_kind(v)}:{len(v)} chars>"
                for v in value
            ]

    variants = clean.get("variants")
    if isinstance(variants, list):
        clean["variants"] = [_strip_variant(v) for v in variants if isinstance(v, dict)]
    return clean


def _strip_variant(variant: dict) -> dict:
    """Ringkas daftar `items` varian yang isinya ribuan entri identik."""
    slim = dict(variant)
    items = slim.get("items")
    if isinstance(items, list) and len(items) > 3:
        unique: list[Any] = []
        for entry in items:
            if entry not in unique:
                unique.append(entry)
            if len(unique) >= 3:
                break
        slim["items"] = unique
        slim["items_count"] = len(items)
    return slim


def _write_jsonl(path: str, rows: list[dict]) -> str:
    with open(path, "w", encoding="utf-8") as fh:
        for row in rows:
            fh.write(json.dumps(row, ensure_ascii=False) + "\n")
    return path


def _write_csv(path: str, rows: list[dict]) -> str:
    if not rows:
        open(path, "w", encoding="utf-8").close()
        return path
    fields: list[str] = []
    for row in rows:
        for key in row:
            if key not in fields:
                fields.append(key)
    with open(path, "w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=fields, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            writer.writerow({k: ("" if v is None else v) for k, v in row.items()})
    return path
