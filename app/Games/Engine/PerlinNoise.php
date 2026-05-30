<?php

namespace App\Games\Engine;

/**
 * Simple seeded Perlin-style noise for deterministic terrain generation.
 */
final class PerlinNoise
{
    /** @var list<int> */
    private array $perm;

    public function __construct(private int $seed, private int $octaves = 3)
    {
        mt_srand($this->seed);
        $p = range(0, 255);
        shuffle($p);
        $this->perm = array_merge($p, $p);
        mt_srand();
    }

    /**
     * @param  array{0: float, 1: float}  $coords
     */
    public function noise(array $coords): float
    {
        $result = 0.0;
        $amplitude = 1.0;
        $frequency = 1.0;
        $maxValue = 0.0;

        for ($o = 0; $o < $this->octaves; $o++) {
            $result += $this->sample($coords[0] * $frequency, $coords[1] * $frequency) * $amplitude;
            $maxValue += $amplitude;
            $amplitude *= 0.5;
            $frequency *= 2.0;
        }

        return $result / $maxValue;
    }

    private function sample(float $x, float $y): float
    {
        $xi = (int) floor($x) & 255;
        $yi = (int) floor($y) & 255;
        $xf = $x - floor($x);
        $yf = $y - floor($y);

        $u = $this->fade($xf);
        $v = $this->fade($yf);

        $aa = $this->grad($this->perm[$xi] + $this->perm[$yi], $xf, $yf);
        $ba = $this->grad($this->perm[$xi + 1] + $this->perm[$yi], $xf - 1, $yf);
        $ab = $this->grad($this->perm[$xi] + $this->perm[$yi + 1], $xf, $yf - 1);
        $bb = $this->grad($this->perm[$xi + 1] + $this->perm[$yi + 1], $xf - 1, $yf - 1);

        $x1 = $this->lerp($u, $aa, $ba);
        $x2 = $this->lerp($u, $ab, $bb);

        return $this->lerp($v, $x1, $x2);
    }

    private function fade(float $t): float
    {
        return $t * $t * $t * ($t * ($t * 6 - 15) + 10);
    }

    private function lerp(float $t, float $a, float $b): float
    {
        return $a + $t * ($b - $a);
    }

    private function grad(int $hash, float $x, float $y): float
    {
        $h = $hash & 3;
        $u = $h < 2 ? $x : $y;
        $v = $h < 2 ? $y : $x;

        return (($h & 1) === 0 ? $u : -$u) + (($h & 2) === 0 ? $v : -$v);
    }
}
