<?php

namespace App\Games\Engine;

use App\Games\GameConstants;

final class MarchingSquares
{
    /** @var list<list<float>> */
    public array $grid;

    public function __construct()
    {
        $this->grid = self::emptyGrid();
    }

    /**
     * @return list<list<float>>
     */
    public static function emptyGrid(): array
    {
        $grid = [];
        for ($x = 0; $x <= GameConstants::ROWS; $x++) {
            $grid[$x] = array_fill(0, GameConstants::COLS + 1, 0.0);
        }

        return $grid;
    }

    /**
     * @param  list<list<float>>  $newGrid
     */
    public function setGrid(array $newGrid): void
    {
        $this->grid = $newGrid;
    }

    public function getGridValue(float $x, float $y): float
    {
        $x1 = (int) $x;
        $y1 = (int) $y;
        $x2 = min($x1 + 1, GameConstants::ROWS);
        $y2 = min($y1 + 1, GameConstants::COLS);

        $dx = $x - $x1;
        $dy = $y - $y1;

        $p11 = $this->grid[$x1][$y1];
        $p21 = $this->grid[$x2][$y1];
        $p12 = $this->grid[$x1][$y2];
        $p22 = $this->grid[$x2][$y2];

        return ($p11 * (1 - $dx) * (1 - $dy))
            + ($p21 * $dx * (1 - $dy))
            + ($p12 * (1 - $dx) * $dy)
            + ($p22 * $dx * $dy);
    }
}
