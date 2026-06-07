<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->boolean('published')->default(false)->after('data');
            $table->timestamp('published_at')->nullable()->after('published');
            $table->unsignedInteger('games_count')->default(0)->after('published_at');
            $table->unsignedInteger('likes_count')->default(0)->after('games_count');
            $table->unsignedInteger('dislikes_count')->default(0)->after('likes_count');
            $table->unsignedInteger('forks_count')->default(0)->after('dislikes_count');
            $table->foreignId('forked_from_id')->nullable()->after('forks_count')->constrained('maps')->nullOnDelete();
            $table->index(['published', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->dropIndex(['published', 'published_at']);
            $table->dropConstrainedForeignId('forked_from_id');
            $table->dropColumn([
                'published',
                'published_at',
                'games_count',
                'likes_count',
                'dislikes_count',
                'forks_count',
            ]);
        });
    }
};
