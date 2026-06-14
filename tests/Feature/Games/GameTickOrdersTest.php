<?php

namespace Tests\Feature\Games;

use App\Enums\GameStatus;
use App\Games\Services\GameManager;
use App\Games\Services\GameTickService;
use App\Models\Game;
use App\Models\Map;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Predis\Client;
use Tests\TestCase;

class GameTickOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis') && ! class_exists(Client::class)) {
            $this->markTestSkipped('Redis is required for game tick tests.');
        }
    }

    public function test_submit_orders_then_tick_moves_troop_along_path(): void
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
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(GameStatus::Playing, $game->status);

        $manager = app(GameManager::class);
        $tickService = app(GameTickService::class);

        try {
            $state = $manager->getLiveState($game);
            $hostPlayer = $game->players()->where('user_id', $host->id)->firstOrFail();
            $this->assertSame(0, $hostPlayer->slot);

            $troopId = $state['environment']['players'][0]['troops'][0]['id'];
            $posBefore = $state['environment']['players'][0]['troops'][0]['position'];
            $this->assertIsArray($posBefore);
            $this->assertCount(2, $posBefore);

            $targetX = (float) $posBefore[0] + 200.0;
            $targetY = (float) $posBefore[1];

            $troopOrders = [[$troopId, [[$targetX, $targetY]]]];
            $cityOrders = [];
            $manager->submitOrders($game, $hostPlayer, [$troopOrders, $cityOrders]);

            $stateAfterSubmit = $manager->getLiveState($game);
            $pathAfterSubmit = $stateAfterSubmit['environment']['players'][0]['troops'][0]['path'] ?? [];
            $this->assertNotSame([], $pathAfterSubmit, 'Troop path should be stored on submit so clients see routes before the next tick.');

            for ($i = 0; $i < 400; $i++) {
                $game->refresh();
                if ($game->status !== GameStatus::Playing) {
                    break;
                }
                $tickService->tick($game, $manager);
            }

            $game->refresh();
            $this->assertSame(GameStatus::Playing, $game->status);

            $stateAfter = $manager->getLiveState($game);
            $posAfter = $stateAfter['environment']['players'][0]['troops'][0]['position'];

            $moved = abs($posAfter[0] - $posBefore[0]) > 2.0 || abs($posAfter[1] - $posBefore[1]) > 2.0;
            $this->assertTrue($moved, 'Troop should move toward order waypoint after ticks when game:tick pipeline runs.');
        } finally {
            Redis::del('game:live:'.$game->uuid);
            Redis::srem('games:active', $game->uuid);
        }
    }

    public function test_new_orders_override_pending_orders_for_same_troop(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $owner = User::factory()->create();
        $map = Map::factory()->for($owner)->playablePublishedTwoTeam()->create();

        $this->actingAs($host)
            ->post(route('games.store'), ['map_uuid' => $map->uuid]);
        $game = Game::query()->firstOrFail();

        $this->actingAs($guest)->post(route('games.join', $game));
        $this->actingAs($host)->post(route('games.start', $game));
        $game->refresh();

        $manager = app(GameManager::class);

        try {
            $state = $manager->getLiveState($game);
            $hostPlayer = $game->players()->where('user_id', $host->id)->firstOrFail();
            $troopId = $state['environment']['players'][0]['troops'][0]['id'];
            $pos = $state['environment']['players'][0]['troops'][0]['position'];

            $pathA = [[(float) $pos[0] + 100.0, (float) $pos[1]]];
            $pathB = [[(float) $pos[0], (float) $pos[1] + 200.0]];

            // Submit first set of orders (pathA) for the troop.
            $manager->submitOrders($game, $hostPlayer, [[[$troopId, $pathA]], []]);

            // Submit a second set of orders (pathB) for the same troop — should replace pathA.
            $manager->submitOrders($game, $hostPlayer, [[[$troopId, $pathB]], []]);

            $stateAfter = $manager->getLiveState($game);
            $pending = $stateAfter['playerInputs'][$hostPlayer->slot];

            // Only one entry should remain for the troop — the latest one.
            $this->assertCount(1, $pending, 'Duplicate pending entries for the same troop must be collapsed to one.');

            // The stored path should be pathB (the override), not pathA.
            $this->assertSame($pathB, $pending[0][1], 'New orders must replace the previously queued orders for the same troop.');
        } finally {
            Redis::del('game:live:'.$game->uuid);
            Redis::srem('games:active', $game->uuid);
        }
    }

    public function test_tick_advances_even_when_legacy_pause_flags_present_in_state(): void
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

        $game->refresh();
        $manager = app(GameManager::class);
        $tickService = app(GameTickService::class);

        try {
            $state = $manager->getLiveState($game);
            $tickBefore = (int) ($state['worldTick'] ?? 0);
            $state['pauseRequests'] = [true, true];
            $manager->storeLiveState($game, $state);

            $tickService->tick($game, $manager);

            $stateAfter = $manager->getLiveState($game);
            $this->assertGreaterThan(
                $tickBefore,
                (int) ($stateAfter['worldTick'] ?? 0),
                'Simulation should advance each tick; battle pause was removed.',
            );
        } finally {
            Redis::del('game:live:'.$game->uuid);
            Redis::srem('games:active', $game->uuid);
        }
    }

    public function test_get_live_state_re_registers_playing_game_in_active_tick_set(): void
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

        $game->refresh();
        $manager = app(GameManager::class);

        try {
            $manager->getLiveState($game);
            Redis::srem('games:active', $game->uuid);
            $members = Redis::smembers('games:active') ?: [];
            $this->assertNotContains($game->uuid, $members, 'Sanity: active set entry removed for test.');

            $manager->getLiveState($game);
            $membersAfter = Redis::smembers('games:active') ?: [];
            $this->assertContains($game->uuid, $membersAfter, 'Loading live state should re-add Playing games so game:tick advances them.');
        } finally {
            Redis::del('game:live:'.$game->uuid);
            Redis::srem('games:active', $game->uuid);
        }
    }

    public function test_sync_active_set_restores_playing_match_removed_from_tick_set(): void
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

        $game->refresh();
        $manager = app(GameManager::class);

        try {
            $manager->getLiveState($game);
            Redis::srem('games:active', $game->uuid);
            $this->assertNotContains($game->uuid, Redis::smembers('games:active') ?: []);

            $manager->syncActiveSetWithPlayingMatches();

            $members = Redis::smembers('games:active') ?: [];
            $this->assertContains(
                $game->uuid,
                $members,
                'Periodic sync should re-register Playing matches that still have live Redis state.',
            );
        } finally {
            Redis::del('game:live:'.$game->uuid);
            Redis::srem('games:active', $game->uuid);
        }
    }
}
