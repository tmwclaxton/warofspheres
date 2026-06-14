<?php

namespace Tests\Feature\Games;

use App\Enums\GameStatus;
use App\Games\Services\GameManager;
use App\Models\Game;
use App\Models\Map;
use App\Models\QuickStartEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Predis\Client;
use Tests\TestCase;

class QuickStartAutoCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis') && ! class_exists(Client::class)) {
            $this->markTestSkipped('Redis is required for game tests.');
        }

        Queue::fake();
    }

    protected function tearDown(): void
    {
        $keys = Redis::keys('game:*');
        if (! empty($keys)) {
            Redis::del(...$keys);
        }

        Redis::del('games:active');

        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Queue a user entry that has been waiting long enough to trigger auto-create. */
    private function enqueueUserReady(User $user): QuickStartEntry
    {
        return QuickStartEntry::create([
            'user_id' => $user->id,
            'guest_key' => null,
            'status' => 'queued',
            'created_at' => now()->subSeconds(GameManager::QUICK_START_CREATE_AFTER_SECONDS + 1),
        ]);
    }

    /** Queue a user entry that is too new to trigger auto-create. */
    private function enqueueUserFresh(User $user): QuickStartEntry
    {
        return QuickStartEntry::create([
            'user_id' => $user->id,
            'guest_key' => null,
            'status' => 'queued',
            'created_at' => now()->subSeconds(GameManager::QUICK_START_CREATE_AFTER_SECONDS - 5),
        ]);
    }

    /** Queue a guest entry that has been waiting long enough. */
    private function enqueueGuestReady(string $guestKey): QuickStartEntry
    {
        return QuickStartEntry::create([
            'user_id' => null,
            'guest_key' => $guestKey,
            'status' => 'queued',
            'created_at' => now()->subSeconds(GameManager::QUICK_START_CREATE_AFTER_SECONDS + 1),
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_no_game_created_with_only_one_player_ready(): void
    {
        Map::factory()->playablePublishedTwoTeam()->create();
        $user = User::factory()->create();
        $this->enqueueUserReady($user);

        app(GameManager::class)->runQuickStart();

        $this->assertDatabaseCount('games', 0);
        $this->assertDatabaseHas('quick_start_entries', ['user_id' => $user->id, 'status' => 'queued']);
    }

    public function test_no_game_created_when_no_published_maps_exist(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->enqueueUserReady($userA);
        $this->enqueueUserReady($userB);

        app(GameManager::class)->runQuickStart();

        $this->assertDatabaseCount('games', 0);
    }

    public function test_no_game_created_when_players_havent_waited_long_enough(): void
    {
        Map::factory()->playablePublishedTwoTeam()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->enqueueUserFresh($userA);
        $this->enqueueUserFresh($userB);

        app(GameManager::class)->runQuickStart();

        $this->assertDatabaseCount('games', 0);
    }

    public function test_game_auto_created_for_two_ready_guests(): void
    {
        $map = Map::factory()->playablePublishedTwoTeam()->create();
        $guestA = 'aaaaaaaa-0000-0000-0000-000000000001';
        $guestB = 'aaaaaaaa-0000-0000-0000-000000000002';
        $this->enqueueGuestReady($guestA);
        $this->enqueueGuestReady($guestB);

        app(GameManager::class)->runQuickStart();

        $this->assertDatabaseCount('games', 1);

        $game = Game::query()->firstOrFail();
        // Quick-start games launch immediately when full — expect Playing status.
        $this->assertSame(GameStatus::Playing, $game->status);
        $this->assertSame($map->id, $game->map_id);
        $this->assertNull($game->host_user_id);
        $this->assertTrue((bool) ($game->settings['quick_start_created'] ?? false));

        $this->assertDatabaseHas('quick_start_entries', ['guest_key' => $guestA, 'status' => 'matched']);
        $this->assertDatabaseHas('quick_start_entries', ['guest_key' => $guestB, 'status' => 'matched']);
        $this->assertSame(2, $game->players()->count());
    }

    public function test_game_auto_created_for_two_ready_users(): void
    {
        $map = Map::factory()->playablePublishedTwoTeam()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->enqueueUserReady($userA);
        $this->enqueueUserReady($userB);

        app(GameManager::class)->runQuickStart();

        $this->assertDatabaseCount('games', 1);

        $game = Game::query()->firstOrFail();
        // Quick-start games launch immediately when full — expect Playing status.
        $this->assertSame(GameStatus::Playing, $game->status);
        $this->assertSame($map->id, $game->map_id);
        $this->assertSame(2, $game->max_players);
        $this->assertTrue((bool) ($game->settings['quick_start_created'] ?? false));

        $this->assertDatabaseHas('quick_start_entries', ['user_id' => $userA->id, 'status' => 'matched']);
        $this->assertDatabaseHas('quick_start_entries', ['user_id' => $userB->id, 'status' => 'matched']);

        $this->assertSame(2, $game->players()->count());
    }

    public function test_game_auto_created_for_user_and_guest(): void
    {
        Map::factory()->playablePublishedTwoTeam()->create();
        $userA = User::factory()->create();
        $guestKey = 'bbbbbbbb-0000-0000-0000-000000000001';
        $this->enqueueUserReady($userA);
        $this->enqueueGuestReady($guestKey);

        app(GameManager::class)->runQuickStart();

        $this->assertDatabaseCount('games', 1);

        $game = Game::query()->firstOrFail();
        $this->assertDatabaseHas('quick_start_entries', ['user_id' => $userA->id, 'status' => 'matched']);
        $this->assertDatabaseHas('quick_start_entries', ['guest_key' => $guestKey, 'status' => 'matched']);
        $this->assertSame(2, $game->players()->count());
    }

    public function test_most_liked_map_is_preferred(): void
    {
        Map::factory()->playablePublishedTwoTeam()->create(['likes_count' => 1]);
        $highLikes = Map::factory()->playablePublishedTwoTeam()->create(['likes_count' => 99]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->enqueueUserReady($userA);
        $this->enqueueUserReady($userB);

        app(GameManager::class)->runQuickStart();

        $game = Game::query()->firstOrFail();
        $this->assertSame($highLikes->id, $game->map_id);
    }

    public function test_exact_player_count_map_preferred_over_smaller(): void
    {
        // 3 players ready; a 3-team map should be chosen over a 2-team map even with more likes.
        Map::factory()->playablePublishedTwoTeam()->create(['likes_count' => 100]);
        $threeTeam = Map::factory()->playablePublishedThreeTeam()->create(['likes_count' => 1]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();
        $this->enqueueUserReady($userA);
        $this->enqueueUserReady($userB);
        $this->enqueueUserReady($userC);

        app(GameManager::class)->runQuickStart();

        $game = Game::query()->firstOrFail();
        $this->assertSame($threeTeam->id, $game->map_id);
    }

    public function test_multiple_games_created_when_enough_players_for_two_rounds(): void
    {
        // 4 ready players + a 2-team map → should produce 2 separate 2-player games.
        Map::factory()->playablePublishedTwoTeam()->create();

        $users = User::factory()->count(4)->create();
        foreach ($users as $user) {
            $this->enqueueUserReady($user);
        }

        app(GameManager::class)->runQuickStart();

        $this->assertDatabaseCount('games', 2);

        foreach ($users as $user) {
            $this->assertDatabaseHas('quick_start_entries', ['user_id' => $user->id, 'status' => 'matched']);
        }
    }

    public function test_fresh_players_not_auto_created_while_ready_players_are(): void
    {
        Map::factory()->playablePublishedTwoTeam()->create();

        $readyUser = User::factory()->create();
        $freshUser = User::factory()->create();
        $this->enqueueUserReady($readyUser);
        $this->enqueueUserFresh($freshUser);

        // Only one ready player — not enough to auto-create.
        app(GameManager::class)->runQuickStart();

        $this->assertDatabaseCount('games', 0);
    }

    public function test_existing_lobby_filled_before_auto_create(): void
    {
        $map = Map::factory()->playablePublishedTwoTeam()->create();

        // Create a lobby with 1 open slot.
        $host = User::factory()->create();
        $game = app(GameManager::class)->create($host, $map);

        // One player has been waiting long enough.
        $readyUser = User::factory()->create();
        $this->enqueueUserReady($readyUser);

        app(GameManager::class)->runQuickStart();

        // Player should be placed in the existing lobby, not trigger a new game.
        $this->assertDatabaseCount('games', 1);
        $this->assertDatabaseHas('quick_start_entries', ['user_id' => $readyUser->id, 'status' => 'matched']);
        $this->assertDatabaseHas('game_players', ['game_id' => $game->id, 'user_id' => $readyUser->id]);
    }

    public function test_map_larger_than_queue_not_chosen(): void
    {
        // Only 2 ready players; a 3-team map should NOT be used (would leave an empty slot).
        Map::factory()->playablePublishedThreeTeam()->create(['likes_count' => 99]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->enqueueUserReady($userA);
        $this->enqueueUserReady($userB);

        app(GameManager::class)->runQuickStart();

        // No suitable map fits; no game created.
        $this->assertDatabaseCount('games', 0);
    }
}
