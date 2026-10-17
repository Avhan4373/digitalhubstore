<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // id varian di situs asal (format "<productId>:<index>") — dipakai
            // sebagai kunci idempoten saat seeding. NULL untuk varian buatan admin.
            $table->string('external_id', 60)->nullable()->unique();
            $table->string('name', 120);
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('original_price')->default(0);
            // angka stok di situs asal — disimpan untuk referensi saja
            $table->unsignedInteger('stock_original')->default(0);
            // mode pengiriman: manual (chat admin), auto (stok akun), file (link unduhan)
            $table->string('delivery_type', 20)->default('manual');
            $table->text('delivery_instruction')->nullable();
            $table->json('delivery_links')->nullable();
            $table->text('description')->nullable();
            $table->text('terms')->nullable();
            $table->string('payment_type', 30)->default('default');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variants');
    }
};
