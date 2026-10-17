<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index()
    {
        return view('admin.users.index', [
            'users' => User::query()->orderByDesc('role')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'role' => ['required', 'in:admin,staff'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $user = User::create($data);

        ActivityLog::record('user.created', 'Pengguna '.$user->email.' dibuat', $user, ['role' => $user->role]);

        return back()->with('success', 'Pengguna '.$user->email.' dibuat.');
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', 'in:admin,staff'],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:10', 'confirmed'],
        ]);

        if ($user->id === auth()->id() && $data['role'] !== User::ROLE_ADMIN) {
            throw ValidationException::withMessages(['role' => 'Tidak bisa menurunkan role akun Anda sendiri.']);
        }

        if ($user->id === auth()->id() && ! $request->boolean('is_active')) {
            throw ValidationException::withMessages(['is_active' => 'Tidak bisa menonaktifkan akun Anda sendiri.']);
        }

        $user->fill($data)->save();

        ActivityLog::record('user.updated', 'Pengguna '.$user->email.' diperbarui', $user, [
            'role' => $user->role,
            'is_active' => $user->is_active,
        ]);

        return back()->with('success', 'Pengguna diperbarui.');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Tidak bisa menghapus akun Anda sendiri.');
        }

        if (User::query()->where('role', User::ROLE_ADMIN)->where('is_active', true)->count() <= 1
            && $user->isAdmin()) {
            return back()->with('error', 'Harus ada minimal satu admin aktif.');
        }

        $email = $user->email;
        $user->delete();

        ActivityLog::record('user.deleted', 'Pengguna '.$email.' dihapus');

        return back()->with('success', 'Pengguna dihapus.');
    }
}
