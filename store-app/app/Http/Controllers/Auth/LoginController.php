<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:160'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = 'login:'.mb_strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan login. Coba lagi dalam '.RateLimiter::availableIn($throttleKey).' detik.',
            ]);
        }

        if (! Auth::attempt($data, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 300);

            throw ValidationException::withMessages([
                'email' => 'Email atau password salah.',
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Akun ini dinonaktifkan. Hubungi admin utama.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        ActivityLog::record('auth.login', 'Login berhasil', $user, [], $user);

        return redirect()->intended(route('admin.dashboard'))->with('success', 'Selamat datang, '.$user->name.'!');
    }

    public function destroy(Request $request)
    {
        ActivityLog::record('auth.logout', 'Logout', null);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Anda telah keluar.');
    }
}
