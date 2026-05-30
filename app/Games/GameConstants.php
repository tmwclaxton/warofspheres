<?php

namespace App\Games;

final class GameConstants
{
    public const int CELL_SIZE = 20;

    public const int WORLD_X = 1280;

    public const int WORLD_Y = 700;

    public const int ROWS = 64;

    public const int COLS = 35;

    public const float THRESHOLD = 0.5;

    public const int TICK_RATE = 30;

    public const int MAX_PLAYERS = 6;

    public const int MIN_PLAYERS = 2;

    /** @var array<string, float> */
    public const array TERRAIN_VALUES = [
        'water' => -0.1,
        'plains' => 0.1,
        'hill' => 0.7,
        'mountain' => 0.83,
    ];

    /** @var array<int, array{0: int, 1: int, 2: int}> */
    public const array COLORS = [
        [255, 0, 0],
        [0, 0, 255],
        [255, 150, 0],
        [175, 0, 175],
        [0, 175, 0],
        [0, 255, 255],
    ];

    /** @var array<string, float> */
    public const array TERRAIN_SPEEDS = [
        'water' => 0.6,
        'forest' => 0.8,
        'plains' => 1.0,
        'hill' => 0.7,
        'mountain' => 3.0,
    ];

    /** @var array<string, float> */
    public const array TERRAIN_ATTACKS = [
        'water' => 0.5,
        'forest' => 0.75,
        'plains' => 1.0,
        'hill' => 1.5,
        'mountain' => 0.0,
    ];

    public static function colorHex(int $slot): string
    {
        $rgb = self::COLORS[$slot] ?? self::COLORS[0];

        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }
}
