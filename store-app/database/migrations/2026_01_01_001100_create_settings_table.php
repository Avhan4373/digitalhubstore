<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pengaturan toko menggantikan hardcode di JS situs asal
        // (nomor WA, gambar QRIS, kredensial Tripay, dsb).
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string|text|bool|int|json|file
            $table->string('group', 40)->default('general');
            $table->string('label', 160)->nullable();
            $table->boolean('is_secret')->default(false);
            $table->timestamps();

            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
