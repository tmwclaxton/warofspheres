<?php

namespace Database\Factories;

use App\Maps\MapEditorGrid;
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
}
