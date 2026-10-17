<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jejak apa yang benar-benar diterima pembeli (payload terenkripsi).
        // Berguna saat sengketa garansi: admin bisa lihat isi yang dikirim
        // tanpa perlu menyimpannya dalam bentuk polos.
        Schema::create('order_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('stock_item_id')->nullable();
            $table->text('content');
            $table->string('source', 30)->default('stock'); // stock | manual | link
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index('stock_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_deliveries');
    }
};
