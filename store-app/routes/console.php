<?php

use App\Services\OrderService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Penjadwalan
|--------------------------------------------------------------------------
|
| Order yang lewat batas bayar ditandai kedaluwarsa dan stoknya dikembalikan
| supaya tidak ada unit yang "tertahan" selamanya di status reserved.
|
| Jalankan salah satu: `php artisan schedule:work` (dev) atau cron
| `* * * * * cd /path/to/store-app && php artisan schedule:run >> /dev/null 2>&1`.
|
*/
Schedule::call(fn () => app(OrderService::class)->expireStaleOrders())
    ->everyTenMinutes()
    ->name('expire-stale-orders')
    ->withoutOverlapping();

