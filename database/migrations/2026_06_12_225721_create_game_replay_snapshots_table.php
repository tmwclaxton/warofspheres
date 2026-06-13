<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_replay_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('world_tick');
            $table->binary('state_json');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['game_id', 'world_tick']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_replay_snapshots');
    }
};
