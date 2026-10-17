<?php

namespace Database\Seeders;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@digitalhubstore.test'],
            [
                'name' => 'Admin DigitalHub',
                'password' => Hash::make(env('ADMIN_SEED_PASSWORD', 'password')),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'staff@digitalhubstore.test'],
            [
                'name' => 'Staff Toko',
                'password' => Hash::make(env('STAFF_SEED_PASSWORD', 'password')),
                'role' => User::ROLE_STAFF,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $this->command?->info("Admin: {$admin->email} (ganti password setelah login pertama)");

        // Kupon contoh supaya halaman checkout bisa langsung diuji.
        Coupon::updateOrCreate(
            ['code' => 'HEMAT10'],
            [
                'description' => 'Potongan 10% maksimal Rp 20.000',
                'type' => Coupon::TYPE_PERCENT,
                'value' => 10,
                'min_amount' => 20000,
                'max_discount' => 20000,
                'usage_limit' => 0,
                'per_buyer_limit' => 1,
                'is_active' => true,
            ]
        );

        Coupon::updateOrCreate(
            ['code' => 'NEWBIE5K'],
            [
                'description' => 'Potongan Rp 5.000 untuk belanja min. Rp 25.000',
                'type' => Coupon::TYPE_FIXED,
                'value' => 5000,
                'min_amount' => 25000,
                'max_discount' => 0,
                'usage_limit' => 100,
                'per_buyer_limit' => 1,
                'is_active' => true,
            ]
        );
    }
}
