<?php

namespace Database\Factories;

use App\Maps\MapEditorGrid;
use App\Maps\MapMarkers;
use App\Models\Map;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Map>
 */
class MapFactory extends Factory
{
    protected $model = Map::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'data' => MapEditorGrid::emptyData(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'published' => true,
            'published_at' => now(),
        ]);
    }

    /**
     * Two-team map that passes {@see MapMarkers::validate()} and is playable on the full editor vertex grid.
     */
    public function playablePublishedTwoTeam(): static
    {
        return $this->state(function (): array {
            $data = MapEditorGrid::emptyData();
            $data['markers'] = [
                [
                    'type' => MapMarkers::TYPE_CAPITAL,
                    'team' => 0,
                    'row' => 80,
                    'col' => 45,
                ],
                [
                    'type' => MapMarkers::TYPE_CAPITAL,
                    'team' => 1,
                    'row' => 110,
                    'col' => 65,
                ],
                [
                    'type' => MapMarkers::TYPE_FLAG,
                    'team' => 0,
                    'row' => 66,
                    'col' => 70,
                ],
                [
                    'type' => MapMarkers::TYPE_FLAG,
                    'team' => 1,
                    'row' => 128,
                    'col' => 38,
                ],
            ];

            return [
                'data' => $data,
                'published' => true,
                'published_at' => now(),
            ];
        });
    }

    /**
     * Three-team map that passes {@see MapMarkers::validate()} and is playable on the full editor vertex grid.
     * Capitals and flags are placed well apart so marker-spacing validation passes.
     */
    public function playablePublishedThreeTeam(): static
    {
        return $this->state(function (): array {
            $data = MapEditorGrid::emptyData();
            $data['teamCount'] = 3;
            $data['teamPaletteSlots'] = [0, 1, 2];
            $data['markers'] = [
                // Capitals — well-separated across the 195×108 grid
                ['type' => MapMarkers::TYPE_CAPITAL, 'team' => 0, 'row' => 50,  'col' => 20],
                ['type' => MapMarkers::TYPE_CAPITAL, 'team' => 1, 'row' => 50,  'col' => 90],
                ['type' => MapMarkers::TYPE_CAPITAL, 'team' => 2, 'row' => 150, 'col' => 55],
                // Flags — each team's flag placed far from capitals and other flags
                ['type' => MapMarkers::TYPE_FLAG,    'team' => 0, 'row' => 130, 'col' => 20],
                ['type' => MapMarkers::TYPE_FLAG,    'team' => 1, 'row' => 130, 'col' => 90],
                ['type' => MapMarkers::TYPE_FLAG,    'team' => 2, 'row' => 50,  'col' => 55],
            ];

            return [
                'data' => $data,
                'published' => true,
                'published_at' => now(),
            ];
        });
    }
}
