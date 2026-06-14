<?php

namespace App\Games\Engine;

final class Player
{
    /** @var list<Troop> */
    public array $troops = [];

    public MarchingSquares $border;

    public MarchingSquares $vision;

    /**
     * @param  array{0: int, 1: int, 2: int}  $color
     * @param  array{0: float, 1: float}  $startPos
     */
    public function __construct(
        public array $startPos,
        public array $color,
        public int $slot,
        Environment $environment,
        int $initialTroopId,
        public int $teamIndex = 0,
    ) {
        $this->border = new MarchingSquares;
        // Border must be sized to the actual map dimensions, not the default constants.
        // The brush clamps writes using count(grid), so an undersized grid silently drops
        // all brush stamps for players whose start positions exceed the default grid bounds.
        $this->border->setGrid(MarchingSquares::emptyGrid($environment->gridMaxX, $environment->gridMaxY));
        $this->vision = new MarchingSquares;
        $this->vision->setGrid($environment->defaultVision);
        $this->troops = [new Troop($this->startPos, $this, $initialTroopId, null, -1)];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'startPos' => $this->startPos,
            'color' => $this->color,
            'slot' => $this->slot,
            'teamIndex' => $this->teamIndex,
            'border' => $this->border->grid,
            // vision is intentionally excluded: it is always reset and recomputed at the start of
            // every tick, so persisting it wastes ~111 KB per player per write. It is rebuilt on
            // demand via Environment::recomputeVision() before any drawInfo() call.
            'troops' => array_map(fn (Troop $t) => $t->toArray(), $this->troops),
            'nextTroopId' => $this->nextTroopId(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, Environment $environment): self
    {
        $firstTroopId = $data['troops'][0]['id'] ?? 1;
        $player = new self($data['startPos'], $data['color'], $data['slot'], $environment, $firstTroopId, (int) ($data['teamIndex'] ?? 0));
        $player->troops = [];

        // Merge stored border values into the correctly-sized grid that the constructor
        // already created.  Stored grids from before this fix may be default-sized (64×35),
        // so we copy only cells that exist in both the stored data and the map grid rather
        // than replacing the correctly-sized grid wholesale.
        $borderGrid = $player->border->grid;
        foreach (($data['border'] ?? []) as $gx => $col) {
            if (! isset($borderGrid[$gx])) {
                continue;
            }
            foreach ((array) $col as $gy => $val) {
                if (isset($borderGrid[$gx][$gy])) {
                    $borderGrid[$gx][$gy] = (float) $val;
                }
            }
        }
        $player->border->setGrid($borderGrid);
        // vision is not persisted; Environment::recomputeVision() rebuilds it from current
        // troop positions before any drawInfo() call. The constructor already initialises it
        // to defaultVision, so fog-of-war is safe even before recomputeVision() runs.

        foreach ($data['troops'] as $troopData) {
            $player->troops[] = Troop::fromArray($troopData, $player);
        }

        return $player;
    }

    public function nextTroopId(): int
    {
        $max = 0;
        foreach ($this->troops as $troop) {
            $max = max($max, $troop->id);
        }

        return $max + 1;
    }

    public function spawnTroop(array $position, array $path, int $troopId, int $spawnedAtWorldTick = -1, string $type = 'infantry'): Troop
    {
        $troop = new Troop($position, $this, $troopId, $path, $spawnedAtWorldTick, $type);
        $this->troops[] = $troop;

        return $troop;
    }
}
