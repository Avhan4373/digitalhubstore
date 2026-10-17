<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function edit()
    {
        // Kunci yang ditambahkan pada versi seeder baru (mis. `store_logo`)
        // tidak otomatis muncul di instalasi lama karena seeder hanya
        // menyisipkan kunci yang belum ada. Jalankan seeder idempoten ini
        // supaya form pengaturan selalu memuat semua kunci yang didukung.
        $this->pastikanKunciPengaturan();

        $settings = Setting::query()->orderBy('group')->orderBy('id')->get()->groupBy('group');

        return view('admin.settings.edit', ['groups' => $settings]);
    }

    private function pastikanKunciPengaturan(): void
    {
        $sudah = Setting::query()->pluck('key')->all();

        foreach (Setting::kunciBawaan() as [$group, $key, $value, $type, $label, $secret]) {
            if (! in_array($key, $sudah, true)) {
                Setting::put($key, $value, $type, $group, $label, $secret);
            }
        }
    }

    public function update(Request $request)
    {
        $rows = Setting::query()->get();
        $changes = [];

        foreach ($rows as $setting) {
            $input = 'setting_'.$setting->key;

            if ($setting->type === 'file') {
                if ($request->hasFile($input)) {
                    $request->validate([$input => ['image', 'mimes:jpg,jpeg,png,webp', 'max:3072']]);

                    if ($setting->value) {
                        Storage::disk('public')->delete($setting->value);
                    }

                    Setting::put($setting->key, $request->file($input)->store('settings', 'public'), 'file', $setting->group, $setting->label);
                    $changes[] = $setting->key;
                }

                continue;
            }

            if (! $request->has($input)) {
                // checkbox tidak dikirim saat tidak dicentang
                if ($setting->type === 'bool') {
                    Setting::put($setting->key, false, 'bool', $setting->group, $setting->label);
                    $changes[] = $setting->key;
                }

                continue;
            }

            $value = $request->input($input);

            // Kunci rahasia hanya ditimpa kalau diisi (tidak dikosongkan tak sengaja).
            if ($setting->is_secret && ($value === null || $value === '')) {
                continue;
            }

            Setting::put($setting->key, $value, $setting->type, $setting->group, $setting->label, $setting->is_secret);
            $changes[] = $setting->key;
        }

        ActivityLog::record('settings.updated', 'Pengaturan toko diperbarui', null, ['keys' => $changes]);

        return back()->with('success', count($changes).' pengaturan disimpan.');
    }
}
