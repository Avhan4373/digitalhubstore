<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inventaris akun nyata. Payload disimpan TERENKRIPSI (Laravel Crypt),
        // bukan polos seperti di KV situs asal.
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->text('payload');
            // sha256 payload, untuk mendeteksi duplikat tanpa membuka enkripsi
            $table->char('payload_hash', 64);
            $table->enum('status', ['available', 'reserved', 'delivered', 'void'])->default('available');
            // FK ke order_items sengaja tidak dipasang di sini (tabelnya dibuat
            // setelah ini); integritas dijaga di level aplikasi.
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['variant_id', 'status']);
            $table->index('order_item_id');
            $table->index('payload_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_items');
    }
};
