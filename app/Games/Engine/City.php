<?php

namespace App\Games\Engine;

final class City
{
    public int $timer = 0;

    public ?Player $owner = null;

    /** @var list<array{0: float, 1: float}> */
    public array $path = [];

    /**
     * @param  array{0: float, 1: float}  $position
     */
    public function __construct(
        public array $position,
        public int $id,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'timer' => $this->timer,
            'ownerSlot' => $this->owner?->slot,
            'path' => $this->path,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, Environment $environment): self
    {
        $city = new self($data['position'], $data['id']);
        $city->timer = $data['timer'];
        $city->path = $data['path'] ?? [];

        if ($data['ownerSlot'] !== null) {
            $city->owner = $environment->players[$data['ownerSlot']] ?? null;
        }

        return $city;
    }
}
