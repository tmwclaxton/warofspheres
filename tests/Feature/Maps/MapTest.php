<?php

namespace Tests\Feature\Maps;

use App\Maps\MapEditorGrid;
use App\Models\Map;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_user_can_create_and_fetch_map(): void
    {
        $user = User::factory()->create();
        $data = MapEditorGrid::emptyData();

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
        $data = MapEditorGrid::emptyData();
        $data['cells'][0][0] = 'water';

        $this->actingAs($user)
            ->patchJson(route('maps.update', $map), [
                'name' => 'Renamed',
                'data' => $data,
            ])
            ->assertOk()
            ->assertJsonPath('map.name', 'Renamed');

        $this->assertSame('water', $map->fresh()->data['cells'][0][0]);
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
                'data' => $data,
            ])
            ->assertUnprocessable();
    }

    public function test_store_accepts_custom_grid_dimensions(): void
    {
        $user = User::factory()->create();
        $data = MapEditorGrid::emptyData(24, 18);

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
        $data = MapEditorGrid::emptyData();
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
        $data = MapEditorGrid::emptyData();
        array_pop($data['cells']);

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Bad',
                'data' => $data,
            ])
            ->assertUnprocessable();
    }

    public function test_store_normalizes_numeric_bridge_values_to_booleans(): void
    {
        $user = User::factory()->create();
        $data = MapEditorGrid::emptyData();
        $data['bridges'][0][0] = 1;
        $data['bridges'][0][1] = 0;

        $this->actingAs($user)
            ->postJson(route('maps.store'), [
                'name' => 'Bridge bits',
                'data' => $data,
            ])
            ->assertCreated();

        $map = Map::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($map->data['bridges'][0][0]);
        $this->assertFalse($map->data['bridges'][0][1]);
    }
}
