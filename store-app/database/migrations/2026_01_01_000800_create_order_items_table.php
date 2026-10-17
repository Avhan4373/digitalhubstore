<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshot nama & harga disimpan supaya invoice lama tidak berubah
        // saat admin mengedit produk.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name', 160);
            $table->string('variant_name', 120);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedBigInteger('subtotal');
            $table->text('delivery_instruction')->nullable();
            $table->boolean('is_fulfilled')->default(false);
            $table->timestamps();

            $table->index(['order_id']);
            $table->index(['variant_id', 'is_fulfilled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
