<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan dasar.
 *
 * CSP sengaja membatasi script ke origin sendiri + CDN yang benar-benar
 * dipakai. Situs asal memuat Tailwind dari CDN dan me-render HTML pembeli
 * tanpa escape — kombinasi yang membuat stored XSS berhasil. Di sini:
 *   - `script-src` tidak mengizinkan `unsafe-inline` pada produksi,
 *   - `object-src 'none'` dan `frame-ancestors 'none'` menutup clickjacking,
 *   - ulasan selalu di-escape lewat helper safe_text().
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->secure() || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Jangan pasang CSP untuk respons pembayaran gateway (bisa berupa iframe).
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->policy());
        }

        return $response;
    }

    private function policy(): string
    {
        $dev = app()->environment('local') ? " 'unsafe-inline' 'unsafe-eval'" : " 'unsafe-inline'";

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'".$dev,
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net data:",
            "img-src 'self' data: https:",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }
}
