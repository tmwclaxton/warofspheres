<?php

namespace App\Jobs;

use App\Enums\GameStatus;
use App\Games\Services\GameManager;
use App\Models\Game;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class LaunchLobbyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $gameId) {}

    public function handle(GameManager $gameManager): void
    {
        $game = Game::find($this->gameId);

        if ($game === null || $game->status !== GameStatus::Lobby) {
            return;
        }

        if ($game->countdown_started_at === null) {
            return;
        }

        $game->loadMissing('players');

        if (! $game->canStart()) {
            return;
        }

        $gameManager->launch($game);
    }
}
