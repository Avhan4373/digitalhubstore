<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bot WhatsApp
    |--------------------------------------------------------------------------
    |
    | Bot berjalan sebagai proses Node terpisah (whatsapp-web.js) dan membaca
    | katalog serta membuat pesanan lewat API internal di bawah ini. Semua
    | keputusan harga/stok tetap di Laravel — bot hanya menyusun teks balasan.
    |
    | `token` WAJIB diisi dan berbeda dari APP_KEY. Tanpa token, seluruh route
    | /api/bot/* menolak semua permintaan (fail closed), jadi lupa mengisi
    | tidak berubah menjadi celah terbuka.
    |
    */

    'enabled' => (bool) env('BOT_ENABLED', false),

    'token' => env('BOT_API_TOKEN'),

    /*
    | Batas pesan masuk per nomor per menit. Penahan ini murah (tidak menyentuh
    | database) dan mencegah satu nomor membanjiri bot sampai proses Node
    | kehabisan memori di VPS kecil.
    */
    'rate_limit_per_minute' => (int) env('BOT_RATE_LIMIT', 20),

    /*
    | Nomor admin yang menerima notifikasi pesanan baru dari bot (format 62xxx).
    | Boleh lebih dari satu, dipisah koma.
    */
    'admin_numbers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BOT_ADMIN_NUMBERS', ''))
    ))),

    /*
    | Katalog yang dikirim bot dibatasi supaya satu balasan tidak menjadi
    | pesan raksasa. Produk di luar batas tetap bisa dicari lewat nama.
    */
    'max_products' => (int) env('BOT_MAX_PRODUCTS', 40),
    'max_variants' => (int) env('BOT_MAX_VARIANTS', 12),
    'max_categories' => (int) env('BOT_MAX_CATEGORIES', 20),

];
