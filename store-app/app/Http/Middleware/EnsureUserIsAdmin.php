<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Penjaga panel admin.
     *
     * Kedua role (admin & staff) boleh masuk panel, tapi akun nonaktif tidak.
     * Pembatasan yang lebih spesifik (pengguna & pengaturan) diatur di route
     * dengan gate masing-masing — jadi staff tidak bisa membuka kredensial
     * pembayaran atau mengelola akun.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            abort(403, 'Akses ditolak.');
        }

        if (! $user->isAdmin() && ! $user->isStaff()) {
            abort(403, 'Akses ditolak.');
        }

        return $next($request);
    }
}
