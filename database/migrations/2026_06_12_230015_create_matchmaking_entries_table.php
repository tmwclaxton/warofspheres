<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matchmaking_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('queued_at')->useCurrent();
            $table->enum('status', ['queued', 'matched', 'cancelled'])->default('queued');
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();

            $table->index(['status', 'queued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matchmaking_entries');
    }
};
