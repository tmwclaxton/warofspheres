<?php

namespace Tests\Feature\Games;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Map;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Predis\Client;
use Tests\TestCase;

class GameLobbyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis') && ! class_exists(Client::class)) {
            $this->markTestSkipped('Redis is required for game tests.');
        }
    }

    public function test_authenticated_user_can_view_lobby_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('lobbies.index'))
            ->assertOk();
    }

    public function test_user_can_create_and_join_a_lobby(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $owner = User::factory()->create();
        $map = Map::factory()->for($owner)->playablePublishedTwoTeam()->create();

        $this->actingAs($host)
            ->post(route('games.store'), ['map_uuid' => $map->uuid])
            ->assertRedirect();

        $game = Game::query()->firstOrFail();

        $this->assertSame(GameStatus::Lobby, $game->status);
        $this->assertSame(2, $game->max_players);
        $this->assertIsArray($game->map_data);
        $this->assertSame($map->uuid, $game->map_data['source_uuid']);
        $this->assertSame(1, $game->players()->count());

        $this->actingAs($guest)
            ->post(route('games.join', $game))
            ->assertRedirect(route('games.show', $game));

        $this->assertSame(2, $game->fresh()->players()->count());
    }

    public function test_host_can_start_a_two_player_game(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $owner = User::factory()->create();
        $map = Map::factory()->for($owner)->playablePublishedTwoTeam()->create();

        $this->actingAs($host)
            ->post(route('games.store'), ['map_uuid' => $map->uuid]);

        $game = Game::query()->firstOrFail();

        $this->actingAs($guest)
            ->post(route('games.join', $game));

        $this->actingAs($host)
            ->post(route('games.start', $game))
            ->assertRedirect(route('games.play', $game));

        $game->refresh();

        $this->assertSame(GameStatus::Playing, $game->status);
        $this->assertTrue(Redis::exists('game:live:'.$game->uuid) > 0);
        $this->assertContains($game->uuid, Redis::smembers('games:active'));
    }

    public function test_create_lobby_snapshots_map_data_and_survives_map_deletion(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $owner = User::factory()->create();
        $map = Map::factory()->for($owner)->playablePublishedTwoTeam()->create();

        $this->actingAs($host)
            ->post(route('games.store'), ['map_uuid' => $map->uuid])
            ->assertRedirect();

        $game = Game::query()->firstOrFail();
        $this->assertSame($map->id, $game->map_id);
        $this->assertIsArray($game->map_data);
        $this->assertArrayHasKey('data', $game->map_data);

        $this->actingAs($guest)
            ->post(route('games.join', $game));

        $map->delete();

        $game->refresh();
        $this->assertNull($game->map_id);
        $this->assertIsArray($game->map_data);

        $this->actingAs($host)
            ->post(route('games.start', $game))
            ->assertRedirect(route('games.play', $game));

        $this->assertSame(GameStatus::Playing, $game->fresh()->status);
    }

    public function test_snapshot_endpoint_returns_json_for_playing_participant(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $owner = User::factory()->create();
        $map = Map::factory()->for($owner)->playablePublishedTwoTeam()->create();

        $this->actingAs($host)
            ->post(route('games.store'), ['map_uuid' => $map->uuid]);
        $game = Game::query()->firstOrFail();

        $this->actingAs($guest)
            ->post(route('games.join', $game));

        $this->actingAs($host)
            ->post(route('games.start', $game));

        $this->actingAs($host)
            ->getJson(route('games.snapshot', $game))
            ->assertOk()
            ->assertJsonStructure([
                'gameUuid',
                'slot',
                'color',
                'terrain',
                'forest',
                'cityPositions',
                'world',
                'state',
            ]);
    }

    public function test_submit_orders_rejected_when_game_is_lobby(): void
    {
        $host = User::factory()->create();
        $owner = User::factory()->create();
        $map = Map::factory()->for($owner)->playablePublishedTwoTeam()->create();

        $this->actingAs($host)
            ->post(route('games.store'), ['map_uuid' => $map->uuid]);
        $game = Game::query()->firstOrFail();

        $this->actingAs($host)
            ->post(route('games.orders', $game), [
                'troop_orders' => [],
                'city_orders' => [],
            ])
            ->assertStatus(422);
    }

    public function test_store_requires_map_uuid(): void
    {
        $host = User::factory()->create();

        $this->actingAs($host)
            ->post(route('games.store'), [])
            ->assertSessionHasErrors('map_uuid');
    }

    public function test_play_returns_404_while_in_lobby(): void
    {
        $host = User::factory()->create();
        $owner = User::factory()->create();
        $map = Map::factory()->for($owner)->playablePublishedTwoTeam()->create();

        $this->actingAs($host)
            ->post(route('games.store'), ['map_uuid' => $map->uuid]);
        $game = Game::query()->firstOrFail();

        $this->actingAs($host)
            ->get(route('games.play', $game))
            ->assertNotFound();
    }

    public function test_snapshot_returns_404_while_in_lobby(): void
    {
        $host = User::factory()->create();
        $owner = User::factory()->create();
        $map = Map::factory()->for($owner)->playablePublishedTwoTeam()->create();

        $this->actingAs($host)
            ->post(route('games.store'), ['map_uuid' => $map->uuid]);
        $game = Game::query()->firstOrFail();

        $this->actingAs($host)
            ->getJson(route('games.snapshot', $game))
            ->assertNotFound();
    }
}
