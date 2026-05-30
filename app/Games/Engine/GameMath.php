<?php

namespace App\Games\Engine;

final class GameMath
{
    /**
     * @return array{0: float, 1: float}
     */
    public static function dirDisToXy(float $direction, float $distance): array
    {
        $rad = deg2rad($direction);

        return [
            $distance * cos($rad),
            $distance * sin($rad),
        ];
    }

    /**
     * @param  array{0: float, 1: float}  $xy
     * @return array{0: float, 1: float}
     */
    public static function xyToDirDis(array $xy): array
    {
        [$x, $y] = $xy;

        return [
            rad2deg(atan2($y, $x)),
            sqrt($x ** 2 + $y ** 2),
        ];
    }
}
