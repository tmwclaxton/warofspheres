<?php

namespace App\Games\Services;

use App\Models\Game;
use Illuminate\Support\Str;

final class GameCodeGenerator
{
    public function generate(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Game::query()->where('code', $code)->exists());

        return $code;
    }
}
