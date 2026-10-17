<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk Panel Admin — {{ \App\Models\Setting::storeName() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-full place-items-center bg-slate-100 p-4 antialiased">
    <div class="w-full max-w-md">
        <div class="mb-6 text-center">
            @php($logoKustom = \App\Models\Setting::hasCustomLogo())
            @if($logoKustom)
                <img src="{{ \App\Models\Setting::logoUrl() }}" alt="Logo {{ \App\Models\Setting::storeName() }}"
                     class="mx-auto h-12 w-12 rounded-2xl border border-slate-100 bg-white object-contain shadow-xs">
            @else
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-gradient-to-br from-brand-500 to-rose-600 text-sm font-black tracking-tight text-white shadow-md"
                      aria-label="Logo {{ \App\Models\Setting::storeName() }}">{{ \App\Models\Setting::logoMonogram() }}</span>
            @endif
            <h1 class="mt-3 text-xl font-bold text-slate-900">Panel Admin</h1>
            <p class="mt-1 text-sm text-slate-500">{{ \App\Models\Setting::storeName() }}</p>
        </div>

        <form action="{{ route('login.store') }}" method="POST" class="card-pad space-y-4">
            @csrf

            @if(session('success'))
                <div class="rounded-xl bg-emerald-50 px-3.5 py-2.5 text-sm text-emerald-800 ring-1 ring-emerald-200">
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div class="rounded-xl bg-rose-50 px-3.5 py-2.5 text-sm text-rose-800 ring-1 ring-rose-200">
                    {{ $errors->first() }}
                </div>
            @endif

            <div>
                <label class="label" for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       class="field" required autocomplete="email" autofocus>
            </div>

            <div>
                <label class="label" for="password">Password</label>
                <input id="password" name="password" type="password" class="field" required autocomplete="current-password">
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1"
                       class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                Ingat saya di perangkat ini
            </label>

            <button class="btn-primary w-full">Masuk</button>

            <p class="hint text-center">
                Login dibatasi 5 percobaan gagal per menit. Setiap percobaan dicatat di log aktivitas.
            </p>
        </form>

        <p class="mt-4 text-center text-xs text-slate-400">
            <a href="{{ route('home') }}" class="hover:text-brand-600">&larr; Kembali ke toko</a>
        </p>
    </div>
</body>
</html>
