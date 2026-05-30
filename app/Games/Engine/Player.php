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
    ) {
        $this->border = new MarchingSquares;
        $this->vision = new MarchingSquares;
        $this->vision->setGrid($environment->defaultVision);
        $this->troops = [new Troop($this->startPos, $this, $initialTroopId)];
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
            'border' => $this->border->grid,
            'vision' => $this->vision->grid,
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
        $player = new self($data['startPos'], $data['color'], $data['slot'], $environment, $firstTroopId);
        $player->troops = [];
        $player->border->setGrid($data['border']);
        $player->vision->setGrid($data['vision']);
        $player->troops = [];

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

    public function spawnTroop(array $position, array $path, int $troopId): Troop
    {
        $troop = new Troop($position, $this, $troopId, $path);
        $this->troops[] = $troop;

        return $troop;
    }
}
