<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail untuk aksi admin. Situs asal sama sekali tidak punya ini,
        // sehingga perubahan harga/stok tidak bisa dilacak siapa pelakunya.
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 40)->nullable();
            $table->string('description', 255);
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
