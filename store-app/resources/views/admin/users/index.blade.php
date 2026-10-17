@extends('layouts.admin')

@section('title', 'Pengguna')
@section('heading', 'Pengguna Panel')
@section('subheading', 'Hanya admin bisa mengelola akun. Setiap perubahan tercatat di log.')

@section('content')
    @if($errors->any())
        <div class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800 ring-1 ring-rose-200">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="space-y-3">
                @foreach($users as $user)
                    <div class="card-pad">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-bold text-slate-900">{{ $user->name }}</p>
                                    <span class="badge {{ $user->isAdmin() ? 'bg-brand-50 text-brand-700 ring-brand-100' : 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                                        {{ $user->role }}
                                    </span>
                                    @if(! $user->is_active)
                                        <span class="badge bg-rose-50 text-rose-700 ring-rose-100">nonaktif</span>
                                    @endif
                                    @if($user->id === auth()->id())
                                        <span class="badge bg-white text-slate-500 ring-slate-200">Anda</span>
                                    @endif
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ $user->email }}</p>
                                <p class="mt-1 text-[11px] text-slate-400">
                                    Login terakhir: {{ $user->last_login_at?->diffForHumans() ?? 'belum pernah' }}
                                </p>
                            </div>

                            <div class="flex gap-2">
                                <details>
                                    <summary class="btn-secondary btn-sm cursor-pointer">Ubah</summary>
                                    <form action="{{ route('admin.users.update', $user) }}" method="POST" class="mt-3 space-y-3">
                                        @csrf @method('PUT')
                                        <div>
                                            <label class="label">Nama</label>
                                            <input name="name" value="{{ $user->name }}" class="field" required maxlength="80">
                                        </div>
                                        <div>
                                            <label class="label">Email</label>
                                            <input name="email" type="email" value="{{ $user->email }}" class="field" required maxlength="160">
                                        </div>
                                        <div>
                                            <label class="label">Role</label>
                                            <select name="role" class="field">
                                                <option value="staff" @selected($user->isStaff())>Staff</option>
                                                <option value="admin" @selected($user->isAdmin())>Admin</option>
                                            </select>
                                        </div>
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label class="label">Password baru</label>
                                                <input name="password" type="password" class="field" minlength="10" placeholder="opsional" autocomplete="new-password">
                                            </div>
                                            <div>
                                                <label class="label">Ulangi password</label>
                                                <input name="password_confirmation" type="password" class="field" minlength="10" autocomplete="new-password">
                                            </div>
                                        </div>
                                        <label class="flex items-center gap-2 text-sm text-slate-600">
                                            <input type="checkbox" name="is_active" value="1" @checked($user->is_active)
                                                   class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                                            Akun aktif
                                        </label>
                                        <button class="btn-primary btn-sm">Simpan</button>
                                    </form>
                                </details>

                                @if($user->id !== auth()->id())
                                    <form action="{{ route('admin.users.destroy', $user) }}" method="POST"
                                          data-confirm="Hapus akun {{ $user->email }}?">
                                        @csrf @method('DELETE')
                                        <button class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50">Hapus</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card-pad">
            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Tambah pengguna</h2>

            <form action="{{ route('admin.users.store') }}" method="POST" class="mt-3 space-y-3">
                @csrf
                <div>
                    <label class="label">Nama</label>
                    <input name="name" class="field" required maxlength="80">
                </div>
                <div>
                    <label class="label">Email</label>
                    <input name="email" type="email" class="field" required maxlength="160">
                </div>
                <div>
                    <label class="label">Role</label>
                    <select name="role" class="field">
                        <option value="staff">Staff (tanpa akses pengguna)</option>
                        <option value="admin">Admin (akses penuh)</option>
                    </select>
                </div>
                <div>
                    <label class="label">Password (min. 10 karakter)</label>
                    <input name="password" type="password" class="field" required minlength="10" autocomplete="new-password">
                </div>
                <div>
                    <label class="label">Ulangi password</label>
                    <input name="password_confirmation" type="password" class="field" required minlength="10" autocomplete="new-password">
                </div>
                <button class="btn-primary w-full">Buat pengguna</button>
            </form>

            <p class="hint mt-3">
                Role <span class="font-semibold">staff</span> bisa mengelola katalog dan pesanan, tapi tidak
                bisa menambah pengguna atau membuka halaman ini.
            </p>
        </div>
    </div>
@endsection
