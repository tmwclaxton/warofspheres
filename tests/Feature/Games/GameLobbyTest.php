<?php

namespace Tests\Feature\Games;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class GameLobbyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis') && ! class_exists(\Predis\Client::class)) {
            $this->markTestSkipped('Redis is required for game tests.');
        }
    }

    public function test_authenticated_user_can_view_lobby_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('games.index'))
            ->assertOk();
    }

    public function test_user_can_create_and_join_a_lobby(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();

        $this->actingAs($host)
            ->post(route('games.store'), ['max_players' => 4])
            ->assertRedirect();

        $game = Game::query()->firstOrFail();

        $this->assertSame(GameStatus::Lobby, $game->status);
        $this->assertSame(4, $game->max_players);
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

        $this->actingAs($host)
            ->post(route('games.store'), ['max_players' => 2]);

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
}
