<?php

namespace Tests\Feature;

use App\Game\GameSpecs;
use App\Maps\TerrainCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiTest extends TestCase
{
    use RefreshDatabase;

    public function test_wiki_page_renders_with_game_specs(): void
    {
        $response = $this->get(route('wiki'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Wiki')
            ->has('appDebug')
            ->has('troops', 2)
            ->has('settlements', 2)
            ->has('terrain', count(TerrainCatalog::IDS))
            ->has('mapGeneration', 8)
            ->where('mapGeneration.0.preview', '/images/wiki/map-generation-mix.svg')
            ->has('economyNotes', 5)
            ->where('economyNotes.0.icon', 'coins')
        );
    }

    public function test_game_specs_include_all_terrain_combat_data(): void
    {
        $specs = GameSpecs::forWiki();

        foreach (TerrainCatalog::IDS as $terrainId) {
            $this->assertContains($terrainId, array_column($specs['terrain'], 'id'));
        }

        $infantry = collect($specs['troops'])->firstWhere('id', 'infantry');
        $tank = collect($specs['troops'])->firstWhere('id', 'tank');
        $capital = collect($specs['settlements'])->firstWhere('id', 'capital');
        $outpost = collect($specs['settlements'])->firstWhere('id', 'outpost');

        $this->assertSame(100, $infantry['health']);
        $this->assertSame(200, $tank['health']);
        $this->assertGreaterThan($outpost['incomePerSecond'], $capital['incomePerSecond']);
    }
}
