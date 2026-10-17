<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 32)->unique();
            // nomor WhatsApp dinormalisasi ke 62xxxx, dipakai untuk cek riwayat
            $table->string('buyer_wa', 24);
            $table->string('buyer_name', 120);
            $table->string('buyer_email', 160)->nullable();
            $table->text('buyer_note')->nullable();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coupon_code', 40)->nullable();
            $table->enum('payment_method', ['qris_manual', 'tripay'])->default('qris_manual');
            $table->enum('payment_status', ['pending', 'paid', 'expired', 'failed', 'refunded'])->default('pending');
            $table->enum('order_status', ['new', 'processing', 'delivered', 'completed', 'cancelled'])->default('new');
            $table->string('gateway_reference', 80)->nullable();
            $table->json('gateway_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['buyer_wa', 'created_at']);
            $table->index(['payment_status', 'created_at']);
            $table->index('gateway_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
