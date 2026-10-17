<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom untuk alur reseller manual (mis. beli di itemku lalu kirim ke pembeli):
 * - referensi produk di supplier + harga modal, supaya admin tahu persis apa
 *   yang harus dibeli dan berapa marginnya;
 * - `required_fields`/`required_info`: data yang wajib diminta ke pembeli
 *   (mis. Player ID & Zone ID untuk top up, atau email untuk produk invite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->string('supplier_name', 60)->nullable();
            $table->string('supplier_sku', 120)->nullable();
            $table->string('supplier_url', 500)->nullable();
            $table->unsignedBigInteger('cost_price')->default(0);
            $table->json('required_fields')->nullable();
        });

        Schema::table('order_items', function (Blueprint $table) {
            // salinan saat dibeli: referensi supplier & harga modal tidak boleh
            // ikut berubah kalau admin mengedit variannya di kemudian hari
            $table->unsignedBigInteger('cost_price')->default(0);
            $table->string('supplier_name', 60)->nullable();
            $table->string('supplier_sku', 120)->nullable();
            $table->string('supplier_url', 500)->nullable();
            $table->json('required_info')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->dropColumn(['supplier_name', 'supplier_sku', 'supplier_url', 'cost_price', 'required_fields']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'supplier_name', 'supplier_sku', 'supplier_url', 'required_info']);
        });
    }
};
