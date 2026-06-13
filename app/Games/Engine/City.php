<?php

namespace App\Games\Engine;

final class City
{
    public int $timer = 0;

    public ?Player $owner = null;

    /** Map marker role for UI: `flag`, `capital`, or null (neutral). */
    public ?string $markerType = null;

    /** @var list<array{0: float, 1: float}> */
    public array $path = [];

    /** What unit type this city auto-produces: `infantry`, `tank`, or `none`. */
    public string $productionType = 'infantry';

    /** Tank production ratio: 0-100 (0 = always infantry, 100 = always tanks). */
    public int $productionTankRatio = 0;

    /** Production speed multiplier: 0-3.0 (0 = idle/none, lower > 0 = faster spawns). */
    public float $productionSpeedMultiplier = 1.0;

    /**
     * @param  array{0: float, 1: float}  $position
     */
    public function __construct(
        public array $position,
        public int $id,
        ?string $markerType = null,
    ) {
        $this->markerType = $markerType;
    }

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
            'markerType' => $this->markerType,
            'productionType' => $this->productionType,
            'productionTankRatio' => $this->productionTankRatio,
            'productionSpeedMultiplier' => $this->productionSpeedMultiplier,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, Environment $environment): self
    {
        $marker = $data['markerType'] ?? null;
        $city = new self($data['position'], $data['id'], is_string($marker) ? $marker : null);
        $city->timer = $data['timer'];
        $city->path = $data['path'] ?? [];
        $rawProduction = $data['productionType'] ?? 'infantry';
        $city->productionType = in_array($rawProduction, ['infantry', 'tank', 'none']) ? $rawProduction : 'infantry';
        $city->productionTankRatio = max(0, min(100, (int) ($data['productionTankRatio'] ?? 0)));
        $city->productionSpeedMultiplier = max(0.0, min(3.0, (float) ($data['productionSpeedMultiplier'] ?? 1.0)));

        if ($data['ownerSlot'] !== null) {
            $city->owner = $environment->players[$data['ownerSlot']] ?? null;
        }

        return $city;
    }
}
