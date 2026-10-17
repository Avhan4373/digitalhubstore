Lihat `AGENTS.md` di direktori ini — berisi konvensi dan aturan kerja untuk
proyek Creative Solutions.

Versi singkat:

- Laravel 13 + Blade + MySQL 8 + Tailwind v4. **Tanpa Laravel Boost.**
- Dokumentasi lengkap: `README.md`. Analisis situs asal: `../ANALYSIS.md`.
- Harga/potongan/stok selalu dihitung di server (`OrderService`).
- Teks pengguna selalu di-escape (`safe_text`).
- Kredensial stok hanya lewat `setPayloadPlain()` / `payloadPlain()`.
- Query harus portabel MySQL + SQLite. Hindari `FIELD()`.
- `npm run build` wajib setelah mengubah view atau CSS.
- Jangan menambah `@apply` untuk class custom Tailwind v4.
