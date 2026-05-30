<?php

namespace App\Games\Engine;

use App\Games\GameConstants;

final class Brush
{
    public function __construct(
        public float $radius = 40.0,
        public float $strength = 1.0,
        public float $falloff = 1.0,
    ) {}

    /**
     * @param  array{0: float, 1: float}  $pos
     */
    public function apply(MarchingSquares $marchingSquares, array $pos, float $targetValue): void
    {
        [$mx, $my] = $pos;
        $cs = GameConstants::CELL_SIZE;
        $r = $this->radius;

        if ($r <= 0) {
            return;
        }

        $colStart = max(0, (int) (($my - $r) / $cs));
        $colEnd = min(GameConstants::COLS, (int) (($my + $r) / $cs) + 1);
        $rowStart = max(0, (int) (($mx - $r) / $cs));
        $rowEnd = min(GameConstants::ROWS, (int) (($mx + $r) / $cs) + 1);

        $invR = 1.0 / $r;
        $strength = $this->strength;
        $falloff = $this->falloff;

        for ($j = $rowStart; $j < $rowEnd; $j++) {
            $px = $j * $cs;
            $dxSqBase = ($px - $mx) ** 2;

            for ($i = $colStart; $i < $colEnd; $i++) {
                $py = $i * $cs;
                $dy = $py - $my;
                $distSq = $dy * $dy + $dxSqBase;

                if ($distSq <= $r * $r) {
                    $dist = sqrt($distSq);
                    $t = $dist * $invR;
                    $weight = $strength + $t * ($falloff - $strength);
                    $old = $marchingSquares->grid[$j][$i];
                    $marchingSquares->grid[$j][$i] = max(0.0, min(1.0, $old + ($targetValue - $old) * $weight));
                }
            }
        }
    }
}
