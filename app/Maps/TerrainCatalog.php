<?php

namespace App\Maps;

/**
 * Discrete terrain types for the map editor (future engine conversion).
 */
final class TerrainCatalog
{
    /** @var list<string> */
    public const array IDS = [
        'plains',
        'meadow',
        'forest',
        'dense_forest',
        'hill',
        'mountain',
        'water',
        'deep_water',
        'river',
        'swamp',
        'desert',
        'beach',
        'snow',
    ];

    /** @var array<string, string> id => hex */
    private const array COLORS = [
        'plains' => '#c8d68a',
        'meadow' => '#b8d4a0',
        'forest' => '#3d6b45',
        'dense_forest' => '#1e4a28',
        'hill' => '#d4d4d4',
        'mountain' => '#5a5a5a',
        'water' => '#4a90d9',
        'deep_water' => '#2d5a8c',
        'river' => '#5ba3e8',
        'swamp' => '#6b8f7a',
        'desert' => '#e6c87a',
        'beach' => '#f5e6b3',
        'snow' => '#ddeeff',
    ];

    /** @var array<string, string> id => short label */
    private const array LABELS = [
        'plains' => 'Plains',
        'meadow' => 'Meadow',
        'forest' => 'Forest',
        'dense_forest' => 'Dense forest',
        'hill' => 'Hill',
        'mountain' => 'Mountain',
        'water' => 'Water',
        'deep_water' => 'Deep water',
        'river' => 'River',
        'swamp' => 'Swamp',
        'desert' => 'Desert',
        'beach' => 'Beach',
        'snow' => 'Snow',
    ];

    /** @var list<string> */
    private const array WATER_IDS = [
        'water',
        'deep_water',
        'river',
    ];

    public static function isValid(string $id): bool
    {
        return in_array($id, self::IDS, true);
    }

    public static function isWaterTerrain(string $id): bool
    {
        return in_array($id, self::WATER_IDS, true);
    }

    /**
     * @return list<array{id: string, label: string, color: string, isWater: bool}>
     */
    public static function forClient(): array
    {
        $out = [];
        foreach (self::IDS as $id) {
            $out[] = [
                'id' => $id,
                'label' => self::LABELS[$id],
                'color' => self::COLORS[$id],
                'isWater' => self::isWaterTerrain($id),
            ];
        }

        return $out;
    }
}
