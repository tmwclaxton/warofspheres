<?php

namespace App\Game;

use App\Maps\TerrainCatalog;

/**
 * Canonical game reference data for the wiki and future engine tuning.
 *
 * Values are inspired by War of Dots community guides, adapted for War of Spheres
 * terrain types (12 editor terrains, infantry/tank split, capitals vs outposts).
 */
final class GameSpecs
{
    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     role: string,
     *     health: int,
     *     recruitCost: int,
     *     upkeepPerSecond: float,
     *     defense: float,
     *     summary: string
     * }>
     */
    public static function troops(): array
    {
        return [
            [
                'id' => 'infantry',
                'label' => 'Infantry',
                'role' => 'Light',
                'health' => 100,
                'recruitCost' => 200,
                'upkeepPerSecond' => 1.0,
                'defense' => 1.0,
                'summary' => 'Fast, cheap, and resilient in forests and hills. Best for flanking, cycling, and holding rough terrain.',
            ],
            [
                'id' => 'tank',
                'label' => 'Tank',
                'role' => 'Heavy',
                'health' => 200,
                'recruitCost' => 400,
                'upkeepPerSecond' => 1.0,
                'defense' => 1.0,
                'summary' => 'Twice the health and damage on open ground, but slow in forests and deadly to push through water. Keep on plains, desert, or beach.',
            ],
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     marker: string,
     *     incomePerSecond: int,
     *     supplyCapacity: int,
     *     healMultiplier: float,
     *     summary: string
     * }>
     */
    public static function settlements(): array
    {
        return [
            [
                'id' => 'outpost',
                'label' => 'Outpost',
                'marker' => 'Flag (star)',
                'incomePerSecond' => 5,
                'supplyCapacity' => 5,
                'healMultiplier' => 2.0,
                'summary' => 'Capturable settlements scattered across the map. Each funds and supplies up to five field units.',
            ],
            [
                'id' => 'capital',
                'label' => 'Capital',
                'marker' => 'Capital (hexagon)',
                'incomePerSecond' => 8,
                'supplyCapacity' => 8,
                'healMultiplier' => 2.0,
                'summary' => 'One per faction. Generates more income, supports a larger army, and is the primary strategic objective.',
            ],
        ];
    }

    /**
     * Terrain speed and attack use War of Dots scale (plains infantry speed = 0.5, attack = 0.08).
     * Defense is a multiplier — both unit types share 1.0 (no inherent defense bonus).
     *
     * @return array<string, array{
     *     infantry: array{speed: float, attack: float, defense: float},
     *     tank: array{speed: float, attack: float, defense: float}
     * }>
     */
    public static function terrainCombat(): array
    {
        return [
            'plains' => [
                'infantry' => ['speed' => 0.5, 'attack' => 0.08, 'defense' => 1.0],
                'tank' => ['speed' => 0.3, 'attack' => 0.16, 'defense' => 1.0],
            ],
            'meadow' => [
                'infantry' => ['speed' => 0.5, 'attack' => 0.08, 'defense' => 1.0],
                'tank' => ['speed' => 0.32, 'attack' => 0.16, 'defense' => 1.0],
            ],
            'forest' => [
                'infantry' => ['speed' => 0.5, 'attack' => 0.08, 'defense' => 1.0],
                'tank' => ['speed' => 0.2, 'attack' => 0.08, 'defense' => 1.0],
            ],
            'dense_forest' => [
                'infantry' => ['speed' => 0.45, 'attack' => 0.07, 'defense' => 1.0],
                'tank' => ['speed' => 0.15, 'attack' => 0.06, 'defense' => 1.0],
            ],
            'hill' => [
                'infantry' => ['speed' => 0.5, 'attack' => 0.08, 'defense' => 1.0],
                'tank' => ['speed' => 0.2, 'attack' => 0.16, 'defense' => 1.0],
            ],
            'mountain' => [
                'infantry' => ['speed' => 0.01, 'attack' => 0.0, 'defense' => 1.0],
                'tank' => ['speed' => 0.01, 'attack' => 0.0, 'defense' => 1.0],
            ],
            'water' => [
                'infantry' => ['speed' => 0.12, 'attack' => 0.03, 'defense' => 0.75],
                'tank' => ['speed' => 0.1, 'attack' => 0.03, 'defense' => 0.75],
            ],
            'deep_water' => [
                'infantry' => ['speed' => 0.08, 'attack' => 0.02, 'defense' => 0.65],
                'tank' => ['speed' => 0.06, 'attack' => 0.02, 'defense' => 0.65],
            ],
            'river' => [
                'infantry' => ['speed' => 0.1, 'attack' => 0.02, 'defense' => 0.7],
                'tank' => ['speed' => 0.08, 'attack' => 0.02, 'defense' => 0.7],
            ],
            'swamp' => [
                'infantry' => ['speed' => 0.2, 'attack' => 0.04, 'defense' => 0.85],
                'tank' => ['speed' => 0.1, 'attack' => 0.08, 'defense' => 0.85],
            ],
            'desert' => [
                'infantry' => ['speed' => 0.3, 'attack' => 0.08, 'defense' => 1.0],
                'tank' => ['speed' => 0.3, 'attack' => 0.16, 'defense' => 1.0],
            ],
            'beach' => [
                'infantry' => ['speed' => 0.4, 'attack' => 0.07, 'defense' => 0.95],
                'tank' => ['speed' => 0.35, 'attack' => 0.14, 'defense' => 0.95],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function terrainDescriptions(): array
    {
        return [
            'plains' => 'Open grassland. No movement or combat penalties.',
            'meadow' => 'Soft rolling grass. Behaves like plains with a slight tank speed edge.',
            'forest' => 'Light woodland. Infantry unaffected; tanks slow and lose their damage advantage.',
            'dense_forest' => 'Thick woodland. Infantry penalty is mild; tanks are severely hampered.',
            'hill' => 'High ground. Tanks move slowly but keep full damage — strong on hilltops above forest.',
            'mountain' => 'Impassable peaks. Units cannot cross; no combat occurs here.',
            'water' => 'Shallow lakes. Slows all units, weakens attacks, and deals damage over time.',
            'deep_water' => 'Open ocean. Worse penalties than shallow water — cross only in desperation.',
            'river' => 'Narrow waterways. Same risks as water; a defended river stops most pushes cold.',
            'swamp' => 'Boggy wetland. Mud-like penalties slow everyone and reduce damage output.',
            'desert' => 'Sandy dunes. Tanks move as fast as infantry — the best heavy-unit terrain.',
            'beach' => 'Coastal sand. Minor slowdown; still favorable for tanks approaching landings.',
        ];
    }

    /**
     * @return list<array{id: string, label: string, description: string, traits: list<string>}>
     */
    public static function mapGenerationTypes(): array
    {
        return [
            [
                'id' => 'mix',
                'label' => 'Mixed',
                'description' => 'Balanced continents with forests, deserts, hills, and carved river networks.',
                'traits' => ['Rivers enabled', 'Varied biomes', 'Good default for competitive play'],
            ],
            [
                'id' => 'islands',
                'label' => 'Islands',
                'description' => 'Two to four large islands in open ocean, with beaches, shallow coastal water, and deep sea beyond.',
                'traits' => ['Archipelago layout', 'Naval chokepoints', 'Troop placement favors coasts'],
            ],
            [
                'id' => 'desert',
                'label' => 'Desert',
                'description' => 'Vast arid dunes punctuated by lush oasis rings around scattered water.',
                'traits' => ['Tank-friendly terrain', 'No rivers', 'High-value oasis clusters'],
            ],
            [
                'id' => 'mountains',
                'label' => 'Mountains',
                'description' => 'Rugged highlands with valleys linked by carved mountain passes.',
                'traits' => ['Elevated chokepoints', 'Pass carving', 'Fewer open flanking routes'],
            ],
        ];
    }

    /**
     * @return list<array{
     *     title: string,
     *     body: string
     * }>
     */
    public static function economyNotes(): array
    {
        return [
            [
                'title' => 'Income',
                'body' => 'Outposts generate 5 funds per second; capitals generate 8. Income stacks across every settlement you hold.',
            ],
            [
                'title' => 'Upkeep',
                'body' => 'Every field unit costs 1 fund per second. Units garrisoned on a settlement cost nothing — parking troops on cities makes money.',
            ],
            [
                'title' => 'Supply',
                'body' => 'Each outpost supplies up to 5 units; each capital supplies up to 8. Exceed your supply cap and the newest unsupported units slowly die.',
            ],
            [
                'title' => 'Recruitment',
                'body' => 'Infantry cost 200 funds; tanks cost 400. Upkeep is identical regardless of unit health — a wounded infantry costs the same as a fresh tank.',
            ],
            [
                'title' => 'Encirclement',
                'body' => 'Cut off from friendly settlements, a pocket army depends only on cities inside the encirclement. Isolate enemy groups to starve them without fighting.',
            ],
        ];
    }

    /**
     * @return array{
     *     troops: list<array<string, mixed>>,
     *     settlements: list<array<string, mixed>>,
     *     terrain: list<array<string, mixed>>,
     *     mapGeneration: list<array<string, mixed>>,
     *     economyNotes: list<array<string, string>>
     * }
     */
    public static function forWiki(): array
    {
        $combat = self::terrainCombat();
        $descriptions = self::terrainDescriptions();
        $terrain = [];

        foreach (TerrainCatalog::forClient() as $entry) {
            $id = $entry['id'];
            $effects = $combat[$id];

            $terrain[] = [
                'id' => $id,
                'label' => $entry['label'],
                'color' => $entry['color'],
                'isWater' => $entry['isWater'],
                'description' => $descriptions[$id],
                'infantry' => $effects['infantry'],
                'tank' => $effects['tank'],
                'impassable' => $id === 'mountain',
            ];
        }

        return [
            'troops' => self::troops(),
            'settlements' => self::settlements(),
            'terrain' => $terrain,
            'mapGeneration' => self::mapGenerationTypes(),
            'economyNotes' => self::economyNotes(),
        ];
    }
}
