<?php

use App\Http\Controllers\Api\BotController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API internal bot WhatsApp
|--------------------------------------------------------------------------
|
| Dipanggil proses bot (Node + whatsapp-web.js) yang berjalan terpisah, jadi
| TIDAK ADA sesi pengguna dan TIDAK ADA CSRF. Autentikasinya bearer token
| statis `BOT_API_TOKEN` lewat middleware `bot.token`.
|
| Harga, stok, dan diskon tetap dihitung di server (OrderService). Bot tidak
| pernah mengirim angka sendiri, dan token kosong menutup semua route
| (fail closed) — lihat App\Http\Middleware\EnsureBotToken.
|
*/

Route::prefix('bot')->name('api.bot.')
    ->middleware(['bot.token', 'throttle:120,1'])
    ->group(function () {
        Route::get('katalog', [BotController::class, 'catalog'])->name('catalog');
        Route::get('produk/{product:slug}', [BotController::class, 'product'])->name('product');
        Route::post('pesanan', [BotController::class, 'order'])->name('order');
        Route::post('pesanan/status', [BotController::class, 'orderStatus'])->name('order.status');
        Route::post('pesanan/riwayat', [BotController::class, 'orderHistory'])->name('order.history');
    });
