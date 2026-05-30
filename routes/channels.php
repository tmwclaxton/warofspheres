<?php

use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('game.{gameUuid}.{userId}', function (User $user, string $gameUuid, int $userId) {
    if ($user->id !== $userId) {
        return false;
    }

    return Game::query()
        ->where('uuid', $gameUuid)
        ->whereHas('players', fn ($query) => $query->where('user_id', $user->id))
        ->exists();
});
