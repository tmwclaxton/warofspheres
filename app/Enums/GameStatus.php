<?php

namespace App\Enums;

enum GameStatus: string
{
    case Lobby = 'lobby';
    case Playing = 'playing';
    case Finished = 'finished';
}
