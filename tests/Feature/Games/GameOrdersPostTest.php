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

class GameOrdersPostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis') && ! class_exists(Client::class)) {
            $this->markTestSkipped('Redis is required for game orders HTTP tests.');
        }
    }

    public function test_post_orders_accepted_and_advances_world_tick_after_ticks(): void
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
            $stateBefore = $manager->getLiveState($game);
            $tickBefore = (int) ($stateBefore['worldTick'] ?? 0);

            $hostPlayer = $game->players()->where('user_id', $host->id)->firstOrFail();
            $this->assertSame(0, $hostPlayer->slot);

            $troopId = $stateBefore['environment']['players'][0]['troops'][0]['id'];
            $posBefore = $stateBefore['environment']['players'][0]['troops'][0]['position'];
            $this->assertIsArray($posBefore);
            $this->assertCount(2, $posBefore);

            $targetX = (float) $posBefore[0] + 200.0;
            $targetY = (float) $posBefore[1];
            $troopOrders = [[$troopId, [[$targetX, $targetY]]]];

            $this->actingAs($host)
                ->from(route('games.play', $game))
                ->post(route('games.orders', $game), [
                    'troop_orders' => $troopOrders,
                    'city_orders' => [],
                ])
                ->assertRedirect(route('games.play', $game));

            $stateAfterPost = $manager->getLiveState($game);
            $pathAfterPost = $stateAfterPost['environment']['players'][0]['troops'][0]['path'] ?? [];
            $this->assertNotSame([], $pathAfterPost, 'HTTP orders should persist troop path before the next tick.');

            for ($i = 0; $i < 20; $i++) {
                $game->refresh();
                if ($game->status !== GameStatus::Playing) {
                    break;
                }
                $tickService->tick($game, $manager);
            }

            $game->refresh();
            $this->assertSame(GameStatus::Playing, $game->status);

            $stateAfter = $manager->getLiveState($game);
            $tickAfter = (int) ($stateAfter['worldTick'] ?? 0);
            $this->assertGreaterThan($tickBefore, $tickAfter, 'World tick should advance when the tick worker runs.');
        } finally {
            Redis::del('game:live:'.$game->uuid);
            Redis::srem('games:active', $game->uuid);
        }
    }
}
