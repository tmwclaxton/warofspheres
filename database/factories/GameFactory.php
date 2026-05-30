<?php

namespace Database\Factories;

use App\Enums\GameStatus;
use App\Games\GameConstants;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    protected $model = Game::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'code' => strtoupper(Str::random(6)),
            'status' => GameStatus::Lobby,
            'max_players' => 2,
            'seed' => random_int(1, PHP_INT_MAX),
            'host_user_id' => User::factory(),
            'settings' => [],
        ];
    }

    public function playing(): static
    {
        return $this->state(fn () => [
            'status' => GameStatus::Playing,
            'started_at' => now(),
        ]);
    }
}
