<?php

namespace App\Games\Engine;

final class Troop
{
    public int $health = 100;

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
    ) {
        $this->path = $path ?? [];
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
            'path' => $this->path,
            'ownerSlot' => $this->owner->slot,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, Player $owner): self
    {
        $troop = new self($data['position'], $owner, $data['id'], $data['path'] ?? []);
        $troop->health = $data['health'];

        return $troop;
    }
}
