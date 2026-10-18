<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `bot_label` — tampilan khusus bot WhatsApp untuk nama varian, dipakai bila
 * admin ingin nama paket di bot berbeda dengan nama di web (mis. "1 Bulan
 * Famhead" di bot, "Youtube Head 1 Bulan" di web). Kosong = nama utuh varian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->string('bot_label', 60)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->dropColumn('bot_label');
        });
    }
};
