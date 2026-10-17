<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pengaman sebelum trait database berjalan.
     *
     * Konfigurasi di `phpunit.xml` diabaikan kalau `bootstrap/cache/config.php`
     * ada (sisa `php artisan config:cache`), karena Laravel tidak lagi membaca
     * `.env`. Dulu itu membuat `RefreshDatabase` menjalankan `migrate:fresh`
     * di MySQL pengembangan — data dev terhapus tanpa peringatan.
     *
     * Pemeriksaan ini harus terjadi SEBELUM `parent::setUpTraits()`, sebab di
     * situlah `RefreshDatabase` menjalankan migrasinya.
     */
    protected function setUpTraits()
    {
        $uses = array_flip(class_uses_recursive(static::class));

        $pakaiDatabase = isset($uses[RefreshDatabase::class])
            || isset($uses[DatabaseMigrations::class])
            || isset($uses[DatabaseTruncation::class]);

        if ($pakaiDatabase) {
            $connection = (string) config('database.default');

            if ($connection !== 'sqlite') {
                $this->fail(
                    "Test wajib memakai SQLite, tetapi koneksi aktif adalah '{$connection}'. "
                    .'Jalankan `php artisan optimize:clear` dulu (config yang ter-cache membuat '
                    .'phpunit.xml diabaikan), lalu ulangi `php artisan test`.'
                );
            }
        }

        return parent::setUpTraits();
    }
}
