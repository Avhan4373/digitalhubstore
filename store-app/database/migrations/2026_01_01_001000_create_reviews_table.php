<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id', 60)->nullable()->index();
            $table->string('user_name', 60);
            $table->unsignedTinyInteger('rating');
            $table->text('comment');
            $table->string('variant_name', 120)->nullable();
            $table->string('image_path', 255)->nullable();
            // moderasi: ulasan masuk sebagai pending, tampil hanya bila approved
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('ip', 45)->nullable();
            $table->string('admin_note', 160)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
