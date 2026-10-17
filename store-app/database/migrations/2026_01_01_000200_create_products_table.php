<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            // id produk di situs asal, dipakai untuk jejak migrasi seed
            $table->string('external_id', 40)->nullable()->index();
            $table->string('name', 160);
            $table->string('slug', 190)->unique();
            $table->longText('description')->nullable();
            $table->text('terms')->nullable();
            $table->string('warranty', 120)->nullable();
            $table->string('payment_type', 30)->default('default');
            $table->string('cover_image', 255)->nullable();
            $table->boolean('is_best_seller')->default(false);
            $table->boolean('is_hot')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sold_count')->default(0);
            // angka "terjual" hasil scrape tidak bisa diverifikasi
            $table->boolean('sold_is_estimate')->default(true);
            // stok nyata dihitung dari stock_items, bukan angka marketing
            $table->unsignedInteger('stock_mode_cache')->default(0);
            $table->string('rating_source', 10)->nullable();
            $table->unsignedBigInteger('view_count')->default(0);
            $table->string('meta_title', 180)->nullable();
            $table->string('meta_description', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
            $table->index(['is_active', 'sold_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
