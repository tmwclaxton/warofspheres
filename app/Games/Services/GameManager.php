<?php

namespace App\Games\Services;

use App\Enums\GameStatus;
use App\Events\GameEnded;
use App\Events\GameInitialized;
use App\Events\GameStateUpdated;
use App\Games\Engine\Environment;
use App\Games\GameConstants;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\Redis;

final class GameManager
{
    private const string ACTIVE_SET = 'games:active';

    public function __construct(
        private GameCodeGenerator $codeGenerator,
        private GameTickService $tickService,
    ) {}

    public function create(User $host, int $maxPlayers): Game
    {
        $maxPlayers = max(GameConstants::MIN_PLAYERS, min(GameConstants::MAX_PLAYERS, $maxPlayers));

        $game = Game::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'code' => $this->codeGenerator->generate(),
            'status' => GameStatus::Lobby,
            'max_players' => $maxPlayers,
            'seed' => random_int(1, PHP_INT_MAX),
            'host_user_id' => $host->id,
        ]);

        $this->join($game, $host);

        return $game->fresh(['players.user']);
    }

    public function join(Game $game, User $user): GamePlayer
    {
        if ($game->status !== GameStatus::Lobby) {
            abort(422, 'This game has already started.');
        }

        if ($game->players()->where('user_id', $user->id)->exists()) {
            return $game->players()->where('user_id', $user->id)->firstOrFail();
        }

        if ($game->isFull()) {
            abort(422, 'This lobby is full.');
        }

        $slot = $game->players()->count();

        return $game->players()->create([
            'user_id' => $user->id,
            'slot' => $slot,
            'color' => GameConstants::colorHex($slot),
        ]);
    }

    public function start(Game $game, User $user): Game
    {
        if ($game->host_user_id !== $user->id) {
            abort(403, 'Only the host can start the game.');
        }

        if (! $game->canStart()) {
            abort(422, 'Need at least two players to start.');
        }

        $playerCount = $game->players()->count();
        $environment = Environment::create($game->seed, $playerCount);

        $game->update([
            'status' => GameStatus::Playing,
            'started_at' => now(),
        ]);

        $this->storeLiveState($game, [
            'environment' => $environment->toArray(),
            'playerInputs' => array_fill(0, $playerCount, []),
            'playerCityInputs' => array_fill(0, $playerCount, []),
            'pauseRequests' => array_fill(0, $playerCount, false),
        ]);

        Redis::sadd(self::ACTIVE_SET, $game->uuid);

        foreach ($game->players as $player) {
            broadcast(new GameInitialized(
                $game,
                $player->user_id,
                $player->slot,
                $environment->getTerrainInfo(),
            ));
        }

        return $game->fresh(['players.user']);
    }

    /**
     * @param  array{0: list<array{0: int, 1: list<array{0: float, 1: float}>}>, 1: list<array{0: int, 1: list<array{0: float, 1: float}>}>}  $orders
     */
    public function submitOrders(Game $game, User $user, array $orders): void
    {
        $state = $this->getLiveState($game);
        $player = $game->players()->where('user_id', $user->id)->firstOrFail();
        $slot = $player->slot;

        [$troopOrders, $cityOrders] = $orders;
        $state['playerInputs'][$slot] = array_merge($state['playerInputs'][$slot] ?? [], $troopOrders);
        $state['playerCityInputs'][$slot] = array_merge($state['playerCityInputs'][$slot] ?? [], $cityOrders);

        $this->storeLiveState($game, $state);
    }

    public function togglePause(Game $game, User $user, bool $paused): void
    {
        $state = $this->getLiveState($game);
        $player = $game->players()->where('user_id', $user->id)->firstOrFail();

        $state['pauseRequests'][$player->slot] = $paused;
        $player->update(['pause_requested' => $paused]);

        $this->storeLiveState($game, $state);
    }

    public function tick(Game $game): void
    {
        $this->tickService->tick($game, $this);
    }

    public function finish(Game $game, int $winnerSlot): void
    {
        $winner = $game->players()->where('slot', $winnerSlot)->first();

        $game->update([
            'status' => GameStatus::Finished,
            'winner_user_id' => $winner?->user_id,
            'finished_at' => now(),
        ]);

        Redis::srem(self::ACTIVE_SET, $game->uuid);
        Redis::del($this->redisKey($game));

        $game->load('players');

        broadcast(new GameEnded($game, $winner?->user_id));
    }

    /**
     * @return list<string>
     */
    public function activeGameUuids(): array
    {
        return Redis::smembers(self::ACTIVE_SET) ?: [];
    }

    public function findByUuid(string $uuid): ?Game
    {
        return Game::query()->where('uuid', $uuid)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function getLiveState(Game $game): array
    {
        $raw = Redis::get($this->redisKey($game));

        if (! $raw) {
            abort(404, 'Live game state not found.');
        }

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function storeLiveState(Game $game, array $state): void
    {
        Redis::set($this->redisKey($game), json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function environmentFromState(array $state): Environment
    {
        return Environment::fromArray($state['environment']);
    }

    public function broadcastState(Game $game, Environment $environment): void
    {
        foreach ($game->players as $player) {
            broadcast(new GameStateUpdated(
                $game,
                $player->user_id,
                $environment->drawInfo($player->slot),
            ));
        }
    }

    private function redisKey(Game $game): string
    {
        return 'game:live:'.$game->uuid;
    }
}
