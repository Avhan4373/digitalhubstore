<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi bot WhatsApp lewat bearer token statis.
 *
 * Bot berjalan di proses terpisah, jadi tidak punya sesi pengguna. Token
 * dibandingkan dengan `hash_equals` supaya tidak bisa ditebak lewat timing.
 *
 * Gagal tertutup: kalau `bot.token` kosong (env belum diisi), SEMUA permintaan
 * ditolak. Ini disengaja — salah konfigurasi tidak boleh membuka endpoint
 * katalog/pesanan ke publik.
 */
class EnsureBotToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('bot.token');

        if ($expected === '' || ! config('bot.enabled')) {
            return response()->json([
                'error' => 'bot_disabled',
                'message' => 'Bot belum diaktifkan. Isi BOT_ENABLED=true dan BOT_API_TOKEN di .env.',
            ], 503);
        }

        $given = (string) $request->bearerToken();

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Token bot tidak valid.',
            ], 401);
        }

        $response = $next($request);

        // Respons bot bisa memuat isi akun (kredensial). Jangan sampai
        // tersimpan di cache proxy/browser di jalan.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
