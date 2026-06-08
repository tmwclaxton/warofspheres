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
     * Two-team map that passes {@see MapMarkers::validate()} and center-crops into the live battlefield.
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
}
