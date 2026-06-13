<?php

namespace App\Games\Engine;

use App\Games\GameConstants;

final class Troop
{
    /** `infantry` or `tank` */
    public string $type = 'infantry';

    public int $health = 100;

    /** Consecutive ticks this troop has been on water terrain. */
    public int $waterTicks = 0;

    /** True when waterTicks has reached {@see GameConstants::SHIP_CONVERSION_TICKS}. */
    public bool $isShip = false;

    /** 0–100; affects attack effectiveness when fatigued. */
    public int $morale = 100;

    /**
     * World tick index when this unit was created, or -1 for "born at match start" (age uses current tick).
     */
    public int $spawnedAtWorldTick = -1;

    /** @var list<array{0: float, 1: float}> */
    public array $path = [];

    /**
     * @param  array{0: float, 1: float}  $position
     * @param  list<array{0: float, 1: float}>|null  $path
     */
    public function __construct(
        public array $position,
        public Player $owner,
        public int $id,
        ?array $path = null,
        int $spawnedAtWorldTick = -1,
        string $type = 'infantry',
    ) {
        $this->path = $path ?? [];
        $this->spawnedAtWorldTick = $spawnedAtWorldTick;
        $this->type = $type === 'tank' ? 'tank' : 'infantry';
        $this->health = $this->maxHealth();
    }

    public function maxHealth(): int
    {
        return $this->type === 'tank' ? GameConstants::TANK_MAX_HEALTH : GameConstants::INFANTRY_MAX_HEALTH;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'health' => $this->health,
            'morale' => $this->morale,
            'spawnedAtWorldTick' => $this->spawnedAtWorldTick,
            'path' => $this->path,
            'ownerSlot' => $this->owner->slot,
            'type' => $this->type,
            'waterTicks' => $this->waterTicks,
            'isShip' => $this->isShip,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, Player $owner): self
    {
        $type = ($data['type'] ?? 'infantry') === 'tank' ? 'tank' : 'infantry';
        $troop = new self(
            $data['position'],
            $owner,
            $data['id'],
            $data['path'] ?? [],
            (int) ($data['spawnedAtWorldTick'] ?? -1),
            $type,
        );
        $troop->health = (int) ($data['health'] ?? $troop->maxHealth());
        $troop->morale = (int) ($data['morale'] ?? 100);
        $troop->waterTicks = (int) ($data['waterTicks'] ?? 0);
        $troop->isShip = (bool) ($data['isShip'] ?? false);

        return $troop;
    }
}
