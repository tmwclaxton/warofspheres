<?php

namespace Tests\Feature\Maps;

use App\Games\GameConstants;
use App\Maps\MapEditorGrid;
use App\Maps\MapMarkers;
use App\Models\Map;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function v2DataWithTwoCapitals(?int $cellRows = null, ?int $cellCols = null): array
    {
        $data = MapEditorGrid::emptyData($cellRows, $cellCols);
        $data['markers'] = [
            [
                'type' => MapMarkers::TYPE_CAPITAL,
                'team' => 0,
                'row' => 0,
                'col' => 0,
            ],
            [
                'type' => MapMarkers::TYPE_CAPITAL,
                'team' => 1,
                'row' => 0,
                'col' => 1,
            ],
        ];
        $data['teamPaletteSlots'] = [0, 1];

        return $data;
    }

    public function test_guests_cannot_access_map_builder(): void
    {
        $this->get(route('map-builder'))
            ->assertRedirect(route('login'));
    }

    public function test_guests_cannot_list_maps(): void
    {
        $this->getJson(route('maps.index'))
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_open_map_builder(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('map-builder'));

        $response->assertOk();
        $this->assertStringContainsString('MapBuilder', $response->getContent());
    }

    public function test_authenticated_user_can_open_map_builder_with_slug(): void
    {
        $user = User::factory()->create();
        $map = Map::factory()->for($user)->create(['name' => 'Slugged map']);

        $this->actingAs($user)
            ->get(route('map-builder', ['map' => $map->uuid]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('MapBuilder')
                ->has('initialDocument')
                ->where('initialDocument.uuid', $map->uuid)
                ->where('initialDocument.name', 'Slugged map'));
    }

    public function test_map_builder_slug_returns_forbidden_for_another_users_map(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $map = Map::factory()->for($owner)->create();

        $this->actingAs($other)
            ->get(route('map-builder', ['map' => $map->uuid]))
            ->assertForbidden();
    }

    public function test_map_builder_without_slug_has_null_initial_document(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('map-builder'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('MapBuilder')
                ->where('initialDocument', null));
    }

    public function test_user_can_create_and_fetch_map(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals();

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Test map',
                'data' => $data,
            ])
            ->assertCreated()
            ->assertJsonPath('map.name', 'Test map');

        $this->assertDatabaseHas('maps', [
            'user_id' => $user->id,
            'name' => 'Test map',
        ]);
    }

    public function test_store_accepts_version_two_payload_with_empty_markers_array(): void
    {
        $user = User::factory()->create();
        $data = MapEditorGrid::emptyData(12, 10);

        $this->assertSame([], $data['markers']);

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Draft plains',
                'data' => $data,
            ])
            ->assertCreated()
            ->assertJsonPath('map.name', 'Draft plains');

        $map = Map::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame([], $map->data['markers']);
    }

    public function test_store_version_two_rejects_payload_when_markers_key_is_omitted(): void
    {
        $user = User::factory()->create();
        $data = MapEditorGrid::emptyData(12, 10);
        unset($data['markers']);

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Bad payload',
                'data' => $data,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data.markers']);
    }

    public function test_user_cannot_view_another_users_map(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $map = Map::factory()->for($owner)->create();

        $this->actingAs($other)
            ->getJson(route('maps.show', $map))
            ->assertForbidden();
    }

    public function test_user_can_update_own_map(): void
    {
        $user = User::factory()->create();
        $map = Map::factory()->for($user)->create();
        $data = $this->v2DataWithTwoCapitals();
        $data['cells'][10][10] = 'water';

        $this->actingAs($user)
            ->patchJson(route('maps.update', $map), [
                'name' => 'Renamed',
                'data' => $data,
            ])
            ->assertOk()
            ->assertJsonPath('map.name', 'Renamed');

        $this->assertSame('water', $map->fresh()->data['cells'][10][10]);
    }

    public function test_user_can_delete_own_map(): void
    {
        $user = User::factory()->create();
        $map = Map::factory()->for($user)->create();

        $this->actingAs($user)
            ->deleteJson(route('maps.destroy', $map))
            ->assertNoContent();

        $this->assertDatabaseMissing('maps', ['id' => $map->id]);
    }

    public function test_store_rejects_invalid_terrain_cell(): void
    {
        $user = User::factory()->create();
        $data = MapEditorGrid::emptyData();
        $data['cells'][0][0] = 'invalid_terrain';

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Bad',
                'data' => [
                    'version' => 1,
                    'cellRows' => $data['cellRows'],
                    'cellCols' => $data['cellCols'],
                    'cells' => $data['cells'],
                ],
            ])
            ->assertUnprocessable();
    }

    public function test_store_accepts_custom_grid_dimensions(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Small map',
                'data' => $data,
            ])
            ->assertCreated();

        $map = Map::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(24, $map->data['cellRows']);
        $this->assertSame(18, $map->data['cellCols']);
        $this->assertCount(24, $map->data['cells']);
        $this->assertCount(18, $map->data['cells'][0]);
    }

    public function test_store_rejects_cell_rows_above_max(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals();
        $data['cellRows'] = MapEditorGrid::MAX_CELL_ROWS + 1;
        $data['cellCols'] = 10;

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Too big',
                'data' => $data,
            ])
            ->assertUnprocessable();
    }

    public function test_store_rejects_wrong_grid_dimensions(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals();
        array_pop($data['cells']);

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Bad',
                'data' => $data,
            ])
            ->assertUnprocessable();
    }

    public function test_empty_map_data_is_version_2_without_prefilled_markers(): void
    {
        $data = MapEditorGrid::emptyData(24, 18);

        $this->assertSame(2, $data['version']);
        $this->assertSame(GameConstants::MIN_PLAYERS, $data['teamCount']);
        $this->assertSame([], $data['markers']);
        $this->assertSame([0, 1], $data['teamPaletteSlots']);
    }

    public function test_store_v2_rejects_duplicate_team_palette_slots(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);
        $data['teamPaletteSlots'] = [0, 0];

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Dup palette',
                'data' => $data,
            ])
            ->assertUnprocessable();
    }

    public function test_store_accepts_version_1_without_markers(): void
    {
        $user = User::factory()->create();
        $data = MapEditorGrid::emptyData(12, 12);
        $v1 = [
            'version' => 1,
            'cellRows' => $data['cellRows'],
            'cellCols' => $data['cellCols'],
            'cells' => $data['cells'],
        ];

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Legacy',
                'data' => $v1,
            ])
            ->assertCreated()
            ->assertJsonPath('map.data.version', 1);
    }

    public function test_store_v2_persists_team_palette_slots(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);
        $data['teamPaletteSlots'] = [2, 0];

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Palette order',
                'data' => $data,
            ])
            ->assertCreated();

        $map = Map::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame([2, 0], $map->data['teamPaletteSlots']);
    }

    public function test_store_v2_accepts_capitals_and_flags(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);
        $data['markers'][] = [
            'type' => MapMarkers::TYPE_FLAG,
            'team' => 0,
            'row' => 10,
            'col' => 10,
        ];
        $data['markers'][] = [
            'type' => MapMarkers::TYPE_FLAG,
            'team' => 1,
            'row' => 20,
            'col' => 15,
        ];

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'V2 map',
                'data' => $data,
            ])
            ->assertCreated()
            ->assertJsonPath('map.data.version', 2);

        $map = Map::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertCount(4, $map->data['markers']);
    }

    public function test_store_v2_accepts_infantry_and_tank_markers(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);
        $data['markers'][] = [
            'type' => MapMarkers::TYPE_INFANTRY,
            'team' => 0,
            'row' => 12,
            'col' => 12,
        ];
        $data['markers'][] = [
            'type' => MapMarkers::TYPE_TANK,
            'team' => 1,
            'row' => 22,
            'col' => 16,
        ];

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Troop spawns',
                'data' => $data,
            ])
            ->assertCreated()
            ->assertJsonPath('map.data.version', 2);

        $map = Map::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertCount(4, $map->data['markers']);
        $types = array_column($map->data['markers'], 'type');
        $this->assertContains(MapMarkers::TYPE_INFANTRY, $types);
        $this->assertContains(MapMarkers::TYPE_TANK, $types);
    }

    public function test_store_v2_accepts_incomplete_markers_as_draft(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);
        $data['markers'] = array_values(array_filter(
            $data['markers'],
            static fn (array $m): bool => ($m['team'] ?? -1) !== 1,
        ));

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Incomplete',
                'data' => $data,
            ])
            ->assertCreated();
    }

    public function test_store_v2_accepts_capital_on_water_as_draft(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);
        $data['cells'][0][0] = 'water';

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Wet capital',
                'data' => $data,
            ])
            ->assertCreated();
    }

    public function test_store_v2_accepts_capital_near_water_as_draft(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);
        $data['cells'][0][1] = 'water';

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Shore capital',
                'data' => $data,
            ])
            ->assertCreated();
    }

    public function test_store_v2_accepts_capital_on_hill_as_draft(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);
        $data['cells'][0][0] = 'hill';

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'High capital',
                'data' => $data,
            ])
            ->assertCreated();
    }

    public function test_store_v2_accepts_flag_on_mountain_as_draft(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);
        $data['markers'][] = [
            'type' => MapMarkers::TYPE_FLAG,
            'team' => 0,
            'row' => 10,
            'col' => 10,
        ];
        $data['markers'][] = [
            'type' => MapMarkers::TYPE_FLAG,
            'team' => 1,
            'row' => 20,
            'col' => 15,
        ];
        $data['cells'][10][10] = 'mountain';

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Peak flag',
                'data' => $data,
            ])
            ->assertCreated();
    }

    public function test_store_v2_accepts_flag_adjacent_to_capital_as_draft(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);
        $data['markers'][] = [
            'type' => MapMarkers::TYPE_FLAG,
            'team' => 0,
            'row' => 0,
            'col' => 2,
        ];
        $data['markers'][] = [
            'type' => MapMarkers::TYPE_FLAG,
            'team' => 1,
            'row' => 18,
            'col' => 15,
        ];

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Tight flag',
                'data' => $data,
            ])
            ->assertCreated();
    }

    public function test_store_v2_rejects_marker_team_outside_team_count(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);
        $data['markers'][] = [
            'type' => MapMarkers::TYPE_FLAG,
            'team' => 3,
            'row' => 3,
            'col' => 3,
        ];

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Bad team',
                'data' => $data,
            ])
            ->assertUnprocessable();
    }

    public function test_store_v2_accepts_unequal_flags_per_team_as_draft(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);
        $data['markers'][] = [
            'type' => MapMarkers::TYPE_FLAG,
            'team' => 0,
            'row' => 12,
            'col' => 12,
        ];

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Skewed flags',
                'data' => $data,
            ])
            ->assertCreated();
    }

    public function test_store_v2_accepts_markers_split_by_mountain_wall_as_draft(): void
    {
        $user = User::factory()->create();
        $data = MapEditorGrid::emptyData(5, 5);
        for ($c = 0; $c < 5; $c++) {
            $data['cells'][2][$c] = 'mountain';
        }
        $data['markers'] = [
            [
                'type' => MapMarkers::TYPE_CAPITAL,
                'team' => 0,
                'row' => 0,
                'col' => 0,
            ],
            [
                'type' => MapMarkers::TYPE_CAPITAL,
                'team' => 1,
                'row' => 4,
                'col' => 4,
            ],
        ];

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Split map',
                'data' => $data,
            ])
            ->assertCreated();
    }

    public function test_store_v2_rejects_marker_out_of_bounds(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);
        $data['markers'][0]['row'] = 99;

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'OOB',
                'data' => $data,
            ])
            ->assertUnprocessable();
    }

    public function test_store_v2_rejects_two_markers_in_same_cell(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals(24, 18);
        $data['markers'][] = [
            'type' => MapMarkers::TYPE_FLAG,
            'team' => 0,
            'row' => 5,
            'col' => 5,
        ];
        $data['markers'][] = [
            'type' => MapMarkers::TYPE_FLAG,
            'team' => 1,
            'row' => 5,
            'col' => 5,
        ];

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Stacked',
                'data' => $data,
            ])
            ->assertUnprocessable();
    }

    public function test_guest_can_view_maps_explore(): void
    {
        $this->get(route('maps.explore'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('MapExplore')
                ->has('maps'));
    }

    public function test_owner_can_publish_valid_map(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals();
        $map = Map::factory()->for($user)->create(['name' => 'Ready', 'data' => $data]);

        $this->actingAs($user)
            ->postJson(route('maps.publish', $map))
            ->assertOk()
            ->assertJsonPath('map.published', true);

        $this->assertTrue($map->fresh()->published);
    }

    public function test_owner_cannot_patch_published_map(): void
    {
        $user = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals();
        $map = Map::factory()->for($user)->create(['name' => 'Locked', 'data' => $data]);
        $map->update(['published' => true, 'published_at' => now()]);

        $this->actingAs($user)
            ->patchJson(route('maps.update', $map), [
                'name' => 'Renamed',
                'data' => $data,
            ])
            ->assertForbidden();
    }

    public function test_user_can_fork_published_map(): void
    {
        $owner = User::factory()->create();
        $guest = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals();
        $map = Map::factory()->for($owner)->create(['data' => $data, 'forks_count' => 0]);
        $map->update(['published' => true, 'published_at' => now()]);

        $this->actingAs($guest)
            ->postJson(route('maps.fork', $map))
            ->assertCreated();

        $fork = Map::query()->where('user_id', $guest->id)->firstOrFail();
        $this->assertSame($map->id, $fork->forked_from_id);
        $this->assertSame(1, $map->fresh()->forks_count);
    }

    public function test_owner_can_fork_own_published_map(): void
    {
        $owner = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals();
        $map = Map::factory()->for($owner)->create(['data' => $data, 'forks_count' => 0]);
        $map->update(['published' => true, 'published_at' => now()]);

        $this->actingAs($owner)
            ->postJson(route('maps.fork', $map))
            ->assertCreated();

        $fork = Map::query()->where('forked_from_id', $map->id)->where('user_id', $owner->id)->firstOrFail();
        $this->assertSame($map->id, $fork->forked_from_id);
        $this->assertSame(1, $map->fresh()->forks_count);
    }

    public function test_user_can_vote_on_published_map(): void
    {
        $owner = User::factory()->create();
        $voter = User::factory()->create();
        $data = $this->v2DataWithTwoCapitals();
        $map = Map::factory()->for($owner)->create(['data' => $data]);
        $map->update(['published' => true, 'published_at' => now()]);

        $this->actingAs($voter)
            ->postJson(route('maps.vote', $map), ['vote' => 'like'])
            ->assertOk();

        $this->assertSame(1, $map->fresh()->likes_count);
    }
}
