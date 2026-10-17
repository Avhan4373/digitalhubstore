<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Cart;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Keranjang dipakai lintas request dalam satu sesi.
        $this->app->singleton(Cart::class);
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        Gate::define('manage-users', fn (User $user) => $user->isAdmin());
        Gate::define('manage-settings', fn (User $user) => $user->isAdmin());

        // Batas umum untuk endpoint publik yang bisa disalahgunakan.
        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
