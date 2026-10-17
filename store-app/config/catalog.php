<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sumber katalog hasil scrape
    |--------------------------------------------------------------------------
    |
    | Direktori berisi `categories.json`, `products.json`, `variants.json`, dan
    | `reviews.json` yang dihasilkan oleh `tools/build_seed.py` di root project.
    | Dipakai oleh `CatalogSeeder` saat `php artisan migrate:fresh --seed`.
    |
    | Ditaruh di config (bukan `env()` langsung di seeder) supaya tetap benar
    | ketika `php artisan config:cache` dipakai di produksi.
    |
    */

    'seed_dir' => env('CATALOG_SEED_DIR') ?: base_path('../data/seed'),

];
