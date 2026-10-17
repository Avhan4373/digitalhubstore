<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('description', 160)->nullable();
            $table->enum('type', ['fixed', 'percent'])->default('fixed');
            $table->unsignedBigInteger('value')->default(0);
            $table->unsignedBigInteger('min_amount')->default(0);
            // 0 = tanpa batas potongan (khusus tipe percent)
            $table->unsignedBigInteger('max_discount')->default(0);
            // 0 = tanpa batas
            $table->unsignedInteger('usage_limit')->default(0);
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('per_buyer_limit')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
