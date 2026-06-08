<?php

namespace Tests\Unit\Maps;

use App\Games\Engine\Environment;
use App\Maps\MapMarkers;
use App\Models\Map;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BattlefieldFromMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_environment_from_valid_published_style_map(): void
    {
        $owner = User::factory()->create();
        $map = Map::factory()->for($owner)->playablePublishedTwoTeam()->create();

        $this->assertSame([], MapMarkers::validate($map->data));

        $environment = Environment::fromMapEditorData(42_424, 2, $map->data);

        $this->assertCount(4, $environment->cities);
        $this->assertCount(2, $environment->players);
        $this->assertGreaterThan(0, count($environment->terrainMarching->grid));
    }
}
