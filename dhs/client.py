"""HTTP client untuk DigitalHub Store (digitalhubstore.my.id).

Situsnya SPA statis di GitHub Pages; seluruh data katalog dilayani oleh satu
Cloudflare Worker. URL API-nya tidak ditulis di HTML secara polos, tapi
di-encode base64 pada variabel `_wEnc` lalu di-decode `atob()` saat runtime.

Client ini meniru langkah itu: ambil halaman depan -> ambil `_wEnc` ->
decode -> pakai sebagai base API. Nilai hasil decode terakhir di-cache supaya
tidak perlu fetch halaman tiap kali.
"""

from __future__ import annotations

import base64
import binascii
import json
import re
import time
from typing import Any

import requests

SITE_URL = "https://digitalhubstore.my.id"
# Fallback kalau halaman depan tidak bisa diambil / pola _wEnc berubah.
FALLBACK_API_URL = "https://toko-digital-api.rizqinastore97.workers.dev"

USER_AGENT = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
)

_WENC_RE = re.compile(r"_wEnc\s*=\s*[\"']([A-Za-z0-9+/=]+)[\"']")


class DigitalHubError(RuntimeError):
    """Error saat mengambil / mengurai respons API."""


def decode_api_url(html: str) -> str | None:
    """Ambil `_wEnc` dari HTML lalu decode base64 jadi base URL API."""
    match = _WENC_RE.search(html)
    if not match:
        return None
    try:
        decoded = base64.b64decode(match.group(1)).decode("utf-8", "strict").strip()
    except (binascii.Error, UnicodeDecodeError):
        return None
    return decoded if decoded.startswith("http") else None


class DigitalHubClient:
    """Wrapper tipis endpoint publik DigitalHub Store.

    Hanya endpoint read-only yang dipakai. Endpoint yang membocorkan data
    pribadi pembeli (`/orders`, `/stats`) sengaja TIDAK diimplementasikan;
    lihat ANALYSIS.md.
    """

    def __init__(
        self,
        api_url: str | None = None,
        site_url: str = SITE_URL,
        timeout: int = 30,
        retries: int = 3,
        delay: float = 0.6,
    ) -> None:
        self.site_url = site_url.rstrip("/")
        self.timeout = timeout
        self.retries = retries
        self.delay = delay
        self.session = requests.Session()
        self.session.headers.update(
            {
                "User-Agent": USER_AGENT,
                "Accept": "application/json, text/html;q=0.9,*/*;q=0.8",
                "Accept-Language": "id-ID,id;q=0.9,en;q=0.8",
            }
        )
        self._api_url = api_url.rstrip("/") if api_url else None

    # ------------------------------------------------------------------ infra
    @property
    def api_url(self) -> str:
        if self._api_url is None:
            self._api_url = self.discover_api_url()
        return self._api_url

    def discover_api_url(self) -> str:
        """Decode URL API dari halaman depan; fallback ke konstanta."""
        try:
            html = self.get_text("/")
            found = decode_api_url(html)
        except DigitalHubError:
            found = None
        if found:
            return found.rstrip("/")
        return FALLBACK_API_URL

    def _request(self, path: str, *, expect_json: bool = True) -> Any:
        url = path if path.startswith("http") else f"{self.site_url}{path}"
        last_exc: Exception | None = None
        for attempt in range(self.retries):
            try:
                res = self.session.get(url, timeout=self.timeout)
                if res.status_code >= 500:
                    raise DigitalHubError(f"HTTP {res.status_code} untuk {url}")
                res.raise_for_status()
                if not expect_json:
                    return res.text
                try:
                    return res.json()
                except ValueError as exc:
                    raise DigitalHubError(f"respons bukan JSON: {url}") from exc
            except Exception as exc:  # noqa: BLE001
                last_exc = exc
                time.sleep(1.0 * (attempt + 1))
        raise DigitalHubError(f"gagal ambil {url}: {last_exc}")

    def api_get(self, path: str = "", **kwargs: Any) -> Any:
        base = self.api_url
        url = f"{base}/{path.lstrip('/')}" if path else base
        params = kwargs.get("params")
        if params:
            url = f"{url}?{requests.compat.urlencode(params)}"
        return self._request(url)

    # ------------------------------------------------------------- endpoint
    def get_text(self, path: str) -> str:
        """Ambil aset statis dari GitHub Pages (HTML/JSON)."""
        return self._request(path, expect_json=False)

    def get_bytes(self, url: str) -> bytes:
        res = self.session.get(url, timeout=self.timeout)
        res.raise_for_status()
        return res.content

    def catalog(self) -> list[dict]:
        """GET / -> daftar produk + objek konfigurasi (banner, promo)."""
        data = self.api_get()
        if not isinstance(data, list):
            raise DigitalHubError("katalog bukan array")
        return data

    def reviews(self, product_id: str | int | None = None) -> list[dict]:
        """GET /reviews?productId= -> ulasan sebuah produk."""
        params = {"productId": product_id} if product_id is not None else None
        data = self.api_get("/reviews", params=params)
        return data if isinstance(data, list) else []

    def stats_schema(self) -> dict:
        """Hanya bentuk respons /stats (tanpa menyimpan isinya).

        Dipakai untuk dokumentasi temuan; isinya berisi IP visitor + hits.
        """
        data = self.api_get("/stats")
        if not isinstance(data, dict):
            return {"type": type(data).__name__}
        return {
            "type": "dict",
            "keys": sorted(data.keys()),
            "visitor_count": len(data.get("visitors") or {}) if isinstance(data.get("visitors"), dict) else None,
        }
