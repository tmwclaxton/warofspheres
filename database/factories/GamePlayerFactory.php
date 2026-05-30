<?php

namespace Database\Factories;

use App\Games\GameConstants;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GamePlayer>
 */
class GamePlayerFactory extends Factory
{
    protected $model = GamePlayer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'user_id' => User::factory(),
            'slot' => 0,
            'color' => GameConstants::colorHex(0),
        ];
    }
}
