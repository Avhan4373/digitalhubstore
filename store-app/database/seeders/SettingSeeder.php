<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        // Daftar kunci ada di model supaya panel pengaturan bisa menambal
        // instalasi lama (lihat SettingController::edit()).
        $settings = Setting::kunciBawaan();

        foreach ($settings as [$group, $key, $value, $type, $label, $secret]) {
            $exists = Setting::query()->where('key', $key)->exists();

            if (! $exists) {
                Setting::put($key, $value, $type, $group, $label, $secret);
            }
        }

        $this->command?->info('Setting: '.count($settings).' kunci');
    }
}
