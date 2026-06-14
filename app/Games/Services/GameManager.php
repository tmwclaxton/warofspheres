<?php

namespace App\Games\Services;

use App\Enums\GameStatus;
use App\Events\GameEnded;
use App\Events\GameInitialized;
use App\Events\GameStateUpdated;
use App\Games\Engine\City;
use App\Games\Engine\Environment;
use App\Games\Engine\Player;
use App\Games\GameConstants;
use App\Games\Logging\GameSimLog;
use App\Jobs\LaunchLobbyJob;
use App\Maps\MapMarkers;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Map;
use App\Models\QuickStartEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final class GameManager
{
    private const string ACTIVE_SET = 'games:active';

    /**
     * Seconds a quick-start player must wait before the system creates a new
     * game on their behalf (when no existing lobby is available to fill).
     */
    public const int QUICK_START_CREATE_AFTER_SECONDS = 10;

    /**
     * Refreshed by {@see \App\Console\Commands\GameTickCommand} each loop iteration while the daemon runs.
     * Used to avoid double-ticking when {@see maybeAdvanceTickIfDaemonAbsent()} runs from HTTP snapshots.
     */
    public const string TICK_DAEMON_HEARTBEAT_KEY = 'games:tick:daemon-heartbeat';

    public function __construct(
        private GameCodeGenerator $codeGenerator,
        private GameTickService $tickService,
    ) {}

    public function create(User $host, Map $sourceMap): Game
    {
        $data = $sourceMap->data;
        if (! is_array($data)) {
            abort(422, 'This map has invalid data.');
        }

        $errors = MapMarkers::validate($data);
        if ($errors !== []) {
            abort(422, implode(' ', $errors));
        }

        $teamCount = (int) $data['teamCount'];
        $teamCount = max(GameConstants::MIN_PLAYERS, min(GameConstants::MAX_PLAYERS, $teamCount));

        $sourceMap->loadMissing('user');

        $snapshot = [
            'source_uuid' => $sourceMap->uuid,
            'source_name' => $sourceMap->name,
            'source_author' => $sourceMap->user?->name ?? 'Unknown',
            'data' => $data,
        ];

        $game = Game::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => $this->codeGenerator->generate(),
            'status' => GameStatus::Lobby,
            'max_players' => $teamCount,
            'seed' => random_int(1, PHP_INT_MAX),
            'host_user_id' => $host->id,
            'map_id' => $sourceMap->id,
            'map_data' => $snapshot,
            'settings' => [
                'source_map_uuid' => $sourceMap->uuid,
                'source_map_name' => $sourceMap->name,
            ],
        ]);

        $this->join($game, $host);

        // Allow quick-start players already in the pool to fill this new lobby.
        $this->runQuickStart();

        return $game->fresh(['players.user']);
    }

    public function join(Game $game, User $user): GamePlayer
    {
        if ($game->status !== GameStatus::Lobby) {
            abort(422, $this->lobbyClosedMessage($game));
        }

        $this->assertLobbyWithinMaxAge($game);

        if ($game->players()->where('user_id', $user->id)->exists()) {
            return $game->players()->where('user_id', $user->id)->firstOrFail();
        }

        // Leave any other lobby the user is currently in before joining this one.
        $this->stripExistingLobby($user->id, null, $game->id);

        if ($game->isFull()) {
            abort(422, 'This lobby is full.');
        }

        $slot = $game->players()->count();

        $player = $game->players()->create([
            'user_id' => $user->id,
            'guest_key' => null,
            'display_name' => null,
            'slot' => $slot,
            'color' => GameConstants::colorHex($slot),
        ]);

        $this->autoStartIfFull($game);

        return $player;
    }

    /**
     * Remove a participant from a lobby. If the leaving player is the host the lobby is cancelled.
     */
    public function leaveLobby(Game $game, ?User $user, ?string $guestKey): void
    {
        if ($game->status !== GameStatus::Lobby) {
            return;
        }

        if ($user !== null && $game->host_user_id !== null && $game->host_user_id === $user->id) {
            // Host leaves → cancel the lobby.
            $game->update(['status' => GameStatus::Finished, 'aborted_reason' => 'host_left']);

            return;
        }

        if ($user !== null) {
            $game->players()->where('user_id', $user->id)->delete();
        } elseif ($guestKey !== null) {
            $game->players()->where('guest_key', $guestKey)->delete();
        }
    }

    public function joinAsGuest(Game $game, string $guestUuid, ?string $displayName): GamePlayer
    {
        if (! Str::isUuid($guestUuid)) {
            abort(422, 'Invalid guest session.');
        }

        if ($game->status !== GameStatus::Lobby) {
            abort(422, $this->lobbyClosedMessage($game));
        }

        $this->assertLobbyWithinMaxAge($game);

        $existing = $game->players()->where('guest_key', $guestUuid)->first();
        if ($existing !== null) {
            return $existing;
        }

        // Leave any other lobby the guest is currently in before joining this one.
        $this->stripExistingLobby(null, $guestUuid, $game->id);

        if ($game->isFull()) {
            abort(422, 'This lobby is full.');
        }

        $label = $displayName !== null ? trim($displayName) : null;
        if ($label === '') {
            $label = null;
        }

        // Auto-generate a name for guests who didn't provide one.
        if ($label === null) {
            $label = 'Guest'.random_int(10000, 99999);
        }

        $slot = $game->players()->count();

        $player = $game->players()->create([
            'user_id' => null,
            'guest_key' => $guestUuid,
            'display_name' => Str::limit($label, 50, ''),
            'slot' => $slot,
            'color' => GameConstants::colorHex($slot),
        ]);

        $this->autoStartIfFull($game);

        return $player;
    }

    public function start(Game $game, User $user): Game
    {
        if ($game->host_user_id !== $user->id) {
            abort(403, 'Only the host can start the game.');
        }

        if (! $game->canStart()) {
            abort(422, 'Need at least two players to start.');
        }

        return $this->launch($game);
    }

    /**
     * Initiates a 10-second countdown before the game launches.
     * Records the timestamp and schedules a job to call launch() after the delay.
     */
    public function beginCountdown(Game $game, User $user): void
    {
        if ($game->host_user_id !== $user->id) {
            abort(403, 'Only the host can start the game.');
        }

        if (! $game->canStart()) {
            abort(422, 'Need at least two players to start.');
        }

        if ($game->countdown_started_at !== null) {
            return;
        }

        $game->update(['countdown_started_at' => now()]);

        LaunchLobbyJob::dispatch($game->id)->delay(now()->addSeconds(10));
    }

    /**
     * Performs the actual game launch: transitions status to Playing, initialises
     * the environment, stores live state, and broadcasts GameInitialized to all players.
     */
    public function launch(Game $game): Game
    {
        if ($game->status === GameStatus::Lobby) {
            $this->assertLobbyWithinMaxAge($game);
        }

        $playerCount = $game->players()->count();
        $snapshot = $game->map_data;
        if (! is_array($snapshot) || ! is_array($snapshot['data'] ?? null)) {
            abort(422, 'Lobby has no map snapshot.');
        }

        $mapData = $snapshot['data'];
        $teamCount = (int) ($mapData['teamCount'] ?? 0);
        if ($playerCount !== $teamCount) {
            abort(422, 'Every commander slot must join before starting.');
        }

        $game->loadMissing('players');
        $teamIndicesBySlot = $game->players->pluck('team_index', 'slot')->all();
        $environment = Environment::fromMapEditorData($game->seed, $playerCount, $mapData, $teamIndicesBySlot);

        $game->update([
            'status' => GameStatus::Playing,
            'started_at' => now(),
        ]);

        if ($game->map_id !== null) {
            Map::query()->whereKey($game->map_id)->increment('games_count');
        }

        $now = microtime(true);

        $this->storeLiveState($game, [
            'environment' => $environment->toArray(),
            'playerInputs' => array_fill(0, $playerCount, []),
            'playerCityInputs' => array_fill(0, $playerCount, []),
            'lastPlayerActivityAt' => array_fill(0, $playerCount, $now),
            'worldTick' => 0,
            'economy' => array_fill(0, $playerCount, [
                'credits' => GameConstants::ECONOMY_STARTING_CREDITS,
                'incomePerTick' => 0,
            ]),
        ]);

        Redis::sadd(self::ACTIVE_SET, $game->uuid);

        $game->load('players');

        $terrainInfo = $environment->getTerrainInfo();
        $terrainCells = $this->terrainCellsForSnapshot($game->map_data, $terrainInfo['terrain']);
        if ($terrainCells !== null) {
            $terrainInfo['terrainCells'] = $terrainCells;
        }

        $stateAfterStart = $this->getLiveState($game);
        $worldTick = (int) ($stateAfterStart['worldTick'] ?? 0);
        $economy = $stateAfterStart['economy'] ?? [];

        foreach ($game->players as $player) {
            $this->broadcastIgnoringTransportFailure(new GameInitialized(
                $game,
                $player->broadcastConnection(),
                $player->slot,
                array_merge($terrainInfo, [
                    'economy' => $economy,
                    'worldTick' => $worldTick,
                ]),
            ));
        }

        return $game->fresh(['players.user']);
    }

    /**
     * Auto-launches quick-start games immediately when all slots are filled.
     * Private lobbies (not quick-start) wait for the host to press Start.
     */
    private function autoStartIfFull(Game $game): void
    {
        $game->unsetRelation('players');

        $isQuickStart = (bool) (($game->settings ?? [])['quick_start_created'] ?? false);
        if (! $isQuickStart || ! $game->canStart()) {
            return;
        }

        $this->launch($game);
    }

    /**
     * Matches queued quick-start players into open lobbies.
     *
     * Strategy: iterate lobbies sorted by fewest open slots first (most full),
     * so games that only need 1–2 more players complete before emptier ones.
     * Only fill a lobby when the queue has enough players to completely fill it,
     * so no one gets stuck in a half-filled lobby waiting for more quick-starters.
     *
     * If no open lobbies can be filled and ≥ 2 players have been waiting at least
     * {@see QUICK_START_CREATE_AFTER_SECONDS} seconds, a new game is automatically
     * created from the most-liked published map whose team count fits the queue.
     * This repeats until the remaining ready queue drops below 2.
     */
    public function runQuickStart(): void
    {
        DB::transaction(function () {
            $queue = QuickStartEntry::query()
                ->where('status', 'queued')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            if ($queue->isEmpty()) {
                return;
            }

            $lobbies = Game::query()
                ->where('status', GameStatus::Lobby)
                ->where('created_at', '>=', now()->subSeconds(GameConstants::LOBBY_MAX_AGE_SECONDS))
                ->withCount('players')
                ->get()
                ->map(fn (Game $g) => [
                    'game' => $g,
                    'openSlots' => $g->max_players - $g->players_count,
                ])
                ->filter(fn ($row) => $row['openSlots'] > 0)
                ->sortBy('openSlots') // fewest open slots first
                ->values();

            $remaining = $queue->values();

            if ($lobbies->isNotEmpty()) {
                foreach ($lobbies as $row) {
                    /** @var Game $game */
                    $game = $row['game'];
                    $need = $row['openSlots'];

                    if ($remaining->count() < $need) {
                        continue; // not enough queued players to fill this lobby
                    }

                    $batch = $remaining->take($need);
                    $remaining = $remaining->slice($need)->values();

                    foreach ($batch as $entry) {
                        try {
                            if ($entry->user_id !== null) {
                                $user = User::find($entry->user_id);
                                if ($user !== null) {
                                    $this->join($game, $user);
                                }
                            } elseif ($entry->guest_key !== null) {
                                $this->joinAsGuest($game, $entry->guest_key, null);
                            }
                        } catch (\Throwable) {
                            // If joining fails (e.g. lobby filled mid-transaction), skip gracefully.
                        }

                        $entry->update(['status' => 'matched', 'game_id' => $game->id]);
                    }

                    if ($remaining->isEmpty()) {
                        break;
                    }
                }
            }

            // Auto-create games for players who have waited long enough and
            // could not be placed into an existing lobby.
            $this->autoCreateQuickStartGames($remaining);
        });
    }

    /**
     * Creates one or more new games for quick-start players who have been
     * waiting at least {@see QUICK_START_CREATE_AFTER_SECONDS} seconds and
     * could not be matched into an existing lobby.
     *
     * Rules:
     *  - At least 2 ready players (waited ≥ threshold) are required.
     *  - The most-liked published map whose teamCount fits the ready group is chosen.
     *  - If no map matches exactly, the largest map whose teamCount ≤ ready count is used.
     *  - Guest-only batches are supported; host_user_id will be null for those games.
     *  - This repeats until fewer than 2 ready players remain.
     *
     * @param  Collection<int, QuickStartEntry>  $unmatched
     */
    private function autoCreateQuickStartGames(Collection $unmatched): void
    {
        $readyAt = now()->subSeconds(self::QUICK_START_CREATE_AFTER_SECONDS);

        // Only consider players who have been waiting long enough.
        $ready = $unmatched->filter(
            fn (QuickStartEntry $e) => $e->created_at <= $readyAt
        )->values();

        while ($ready->count() >= GameConstants::MIN_PLAYERS) {
            $playerCount = $ready->count();

            // Find the best published map: exact teamCount match preferred,
            // then largest teamCount that fits within the ready count, both
            // ordered by likes_count descending to prefer popular maps.
            // Sorting is done in PHP to remain database-agnostic (data is a JSON column).
            $map = Map::query()
                ->where('published', true)
                ->orderByDesc('likes_count')
                ->get()
                ->filter(function (Map $m) use ($playerCount): bool {
                    $teamCount = (int) ($m->data['teamCount'] ?? 0);

                    return $teamCount >= GameConstants::MIN_PLAYERS && $teamCount <= $playerCount;
                })
                ->sortBy(function (Map $m) use ($playerCount): array {
                    $teamCount = (int) ($m->data['teamCount'] ?? 0);

                    // Sort ascending: exact match → 0, non-exact → 1 (exact comes first).
                    // Then prefer larger team counts (negate for ascending sort).
                    // likes_count already sorted descending by the DB query.
                    return [($teamCount === $playerCount) ? 0 : 1, -$teamCount];
                })
                ->first();

            if ($map === null) {
                break; // No suitable published map available.
            }

            $mapTeamCount = (int) ($map->data['teamCount'] ?? 0);
            if ($mapTeamCount < GameConstants::MIN_PLAYERS) {
                break;
            }

            $batch = $ready->take($mapTeamCount);

            // Use the first authenticated user as host if one exists; guests can play without one.
            $hostEntry = $batch->first(fn (QuickStartEntry $e) => $e->user_id !== null);
            $host = $hostEntry !== null ? User::find($hostEntry->user_id) : null;

            try {
                $game = $this->createForQuickStart($host, $map);
            } catch (\Throwable) {
                break;
            }

            // Join all batch members and mark them as matched.
            // The host (if any) was already joined inside createForQuickStart.
            foreach ($batch as $entry) {
                $entry->update(['status' => 'matched', 'game_id' => $game->id]);

                if ($hostEntry !== null && $entry->id === $hostEntry->id) {
                    continue;
                }

                try {
                    if ($entry->user_id !== null) {
                        $user = User::find($entry->user_id);
                        if ($user !== null) {
                            $this->join($game, $user);
                        }
                    } elseif ($entry->guest_key !== null) {
                        $this->joinAsGuest($game, $entry->guest_key, null);
                    }
                } catch (\Throwable) {
                    // Skip gracefully if a slot was taken mid-transaction.
                }
            }

            $ready = $ready->slice($mapTeamCount)->values();
        }
    }

    /**
     * Creates a lobby for the quick-start auto-match flow.
     * Unlike {@see create()}, this does NOT trigger another runQuickStart() call
     * to prevent infinite recursion. Supports a null host for guest-only games.
     */
    private function createForQuickStart(?User $host, Map $map): Game
    {
        $data = $map->data;
        if (! is_array($data)) {
            abort(422, 'This map has invalid data.');
        }

        $errors = MapMarkers::validate($data);
        if ($errors !== []) {
            abort(422, implode(' ', $errors));
        }

        $teamCount = (int) $data['teamCount'];
        $teamCount = max(GameConstants::MIN_PLAYERS, min(GameConstants::MAX_PLAYERS, $teamCount));

        $map->loadMissing('user');

        $snapshot = [
            'source_uuid' => $map->uuid,
            'source_name' => $map->name,
            'source_author' => $map->user?->name ?? 'Unknown',
            'data' => $data,
        ];

        $game = Game::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => $this->codeGenerator->generate(),
            'status' => GameStatus::Lobby,
            'max_players' => $teamCount,
            'seed' => random_int(1, PHP_INT_MAX),
            'host_user_id' => $host?->id,
            'map_id' => $map->id,
            'map_data' => $snapshot,
            'settings' => [
                'source_map_uuid' => $map->uuid,
                'source_map_name' => $map->name,
                'quick_start_created' => true,
            ],
        ]);

        if ($host !== null) {
            $this->join($game, $host);
        }

        return $game->fresh(['players.user']);
    }

    /**
     * Removes the user/guest from any other lobby they're currently in (excluding $exceptGameId).
     */
    private function stripExistingLobby(?int $userId, ?string $guestKey, int $exceptGameId): void
    {
        $query = GamePlayer::query()
            ->whereHas('game', fn ($q) => $q->where('status', GameStatus::Lobby)->where('id', '!=', $exceptGameId));

        if ($userId !== null) {
            $query->where('user_id', $userId);
        } elseif ($guestKey !== null) {
            $query->where('guest_key', $guestKey);
        } else {
            return;
        }

        $query->delete();
    }

    /**
     * @param  array{0: list<array{0: int, 1: list<array{0: float, 1: float}>}>, 1: list<array{0: int, 1: list<array{0: float, 1: float}>}>}  $orders
     */
    public function submitOrders(Game $game, GamePlayer $player, array $orders): void
    {
        if ($game->status !== GameStatus::Playing) {
            abort(422, 'Orders can only be submitted during a live match.');
        }

        if ($player->game_id !== $game->id) {
            abort(403);
        }

        $state = $this->getLiveState($game);
        $slot = $player->slot;

        [$troopOrders, $cityOrders] = $orders;
        $state['playerInputs'][$slot] = $this->mergeOrdersById($state['playerInputs'][$slot] ?? [], $troopOrders);
        $state['playerCityInputs'][$slot] = $this->mergeOrdersById($state['playerCityInputs'][$slot] ?? [], $cityOrders);

        $this->touchPlayerActivityInState($state, $slot);
        $this->storeLiveState($game, $state);

        GameSimLog::info('game.orders.accepted', [
            'game_uuid' => $game->uuid,
            'slot' => $slot,
            'world_tick' => (int) ($state['worldTick'] ?? 0),
            'troop_order_rows' => count($troopOrders),
            'city_order_rows' => count($cityOrders),
            'troop_orders' => array_map(static function ($row): array {
                if (! is_array($row) || $row === []) {
                    return ['entity_id' => null, 'path_points' => 0];
                }

                $path = $row[1] ?? [];

                return [
                    'entity_id' => is_numeric($row[0] ?? null) ? (int) $row[0] : null,
                    'path_points' => is_array($path) ? count($path) : 0,
                ];
            }, $troopOrders),
        ]);

        // The tick daemon applies and broadcasts orders within ~33 ms (30 Hz loop).
        // Running simulation synchronously in the HTTP path was removed to avoid blocking
        // the web worker and double-applying path assignments.
    }

    /**
     * Merges new orders into an existing pending-orders list, keyed by entity ID.
     * New orders for the same entity ID replace any previously queued orders so that
     * re-issuing a command always discards the stale pending entry.
     *
     * @param  list<array{0: mixed, 1: mixed}>  $existing
     * @param  list<array{0: mixed, 1: mixed}>  $incoming
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function mergeOrdersById(array $existing, array $incoming): array
    {
        $byId = [];
        foreach ($existing as $order) {
            if (is_array($order) && isset($order[0])) {
                $byId[(int) $order[0]] = $order;
            }
        }
        foreach ($incoming as $order) {
            if (is_array($order) && isset($order[0])) {
                $byId[(int) $order[0]] = $order;
            }
        }

        return array_values($byId);
    }

    /**
     * Sets the production type for one of the player's owned cities.
     *
     * @param  'infantry'|'tank'|'none'  $productionType
     */
    public function setCityProduction(
        Game $game,
        GamePlayer $gamePlayer,
        int $cityId,
        string $productionType,
        ?int $tankRatio = null,
        ?float $speedMultiplier = null,
    ): void {
        if ($game->status !== GameStatus::Playing) {
            abort(422, 'Production can only be changed during a live match.');
        }

        if ($gamePlayer->game_id !== $game->id) {
            abort(403);
        }

        if (! in_array($productionType, ['infantry', 'tank', 'none'])) {
            abort(422, 'Invalid production type.');
        }

        $state = $this->getLiveState($game);
        $environment = $this->environmentFromState($state);
        $slot = $gamePlayer->slot;
        $player = $environment->players[$slot] ?? null;

        if ($player === null) {
            abort(500, 'Invalid commander slot.');
        }

        $city = null;
        foreach ($environment->cities as $c) {
            if ($c->id === $cityId) {
                $city = $c;
                break;
            }
        }

        if ($city === null) {
            abort(422, 'City not found.');
        }

        if ($city->owner !== $player) {
            abort(403, 'You do not own this city.');
        }

        $city->productionType = $productionType;

        if ($tankRatio !== null) {
            $city->productionTankRatio = max(0, min(100, $tankRatio));
        }

        if ($speedMultiplier !== null) {
            $city->productionSpeedMultiplier = max(0.0, min(3.0, $speedMultiplier));
        }

        $state['environment'] = $environment->toArray();

        $this->touchPlayerActivityInState($state, $slot);
        $this->storeLiveState($game, $state);
        $this->broadcastState($game, $environment, $state);
    }

    /**
     * Marks the given commander slot as active (used for inactivity timeouts).
     */
    public function touchPlayerActivity(Game $game, int $slot): void
    {
        if ($game->status !== GameStatus::Playing) {
            return;
        }

        $state = $this->getLiveState($game);
        $this->touchPlayerActivityInState($state, $slot);
        $this->storeLiveState($game, $state);
    }

    /**
     * Closes lobbies that never started within {@see GameConstants::LOBBY_MAX_AGE_SECONDS}.
     *
     * @return int Number of games updated
     */
    public function expireStaleLobbies(): int
    {
        $cutoff = now()->subSeconds(GameConstants::LOBBY_MAX_AGE_SECONDS);

        $games = Game::query()
            ->where('status', GameStatus::Lobby)
            ->where('created_at', '<', $cutoff)
            ->get();

        $count = 0;

        foreach ($games as $game) {
            $settings = $game->settings ?? [];
            $settings['aborted_reason'] = GameConstants::ABORTED_LOBBY_TIMEOUT;
            $game->update([
                'status' => GameStatus::Finished,
                'finished_at' => now(),
                'winner_user_id' => null,
                'winner_slot' => null,
                'settings' => $settings,
            ]);
            $count++;
        }

        return $count;
    }

    public function tick(Game $game): void
    {
        $this->tickService->tick($game, $this);
    }

    /**
     * When the dedicated tick worker is not running, live matches would stay at worldTick 0.
     * A single tick before serving JSON snapshots keeps local/dev stacks usable without running `game:tick --daemon`.
     * Skipped while the heartbeat key exists (daemon is alive) or during PHPUnit.
     */
    public function maybeAdvanceTickIfDaemonAbsent(Game $game): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if ($game->status !== GameStatus::Playing) {
            return;
        }

        if (Redis::exists(self::TICK_DAEMON_HEARTBEAT_KEY)) {
            return;
        }

        $lock = Cache::lock('game-tick-snapshot-assist:'.$game->uuid, 2);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->tickService->tick($game, $this);
        } catch (\Throwable $e) {
            Log::warning('Snapshot tick assist failed; returning current state.', [
                'game_uuid' => $game->uuid,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }
    }

    public function finish(Game $game, int $winnerSlot): void
    {
        $winner = $game->players()->where('slot', $winnerSlot)->first();

        $game->update([
            'status' => GameStatus::Finished,
            'winner_user_id' => $winner?->user_id,
            'winner_slot' => $winnerSlot,
            'finished_at' => now(),
        ]);

        Redis::srem(self::ACTIVE_SET, $game->uuid);
        Redis::del($this->redisKey($game));
        Redis::del($this->redisMapKey($game));

        $game->load('players');

        $winnerName = $winner?->displayLabel() ?? 'Unknown';

        // Update MMR: +25 for winner, -20 for losers.
        $this->applyMmrChanges($game, $winnerSlot);

        $this->broadcastIgnoringTransportFailure(new GameEnded(
            $game,
            $winner?->user_id,
            $winner?->slot,
            $winnerName,
        ));
    }

    private function applyMmrChanges(Game $game, int $winnerSlot): void
    {
        foreach ($game->players as $player) {
            if ($player->user_id === null) {
                continue;
            }
            $delta = $player->slot === $winnerSlot ? 25 : -20;
            User::query()
                ->where('id', $player->user_id)
                ->update(['mmr' => DB::raw("greatest(0, mmr + {$delta})")]);
        }
    }

    /**
     * Ends a live match with no winner (abandonment / inactivity).
     */
    public function finishWithoutWinner(Game $game, string $abortedReason, string $publicMessage): void
    {
        if ($game->status !== GameStatus::Playing) {
            return;
        }

        $settings = $game->settings ?? [];
        $settings['aborted_reason'] = $abortedReason;

        $game->update([
            'status' => GameStatus::Finished,
            'winner_user_id' => null,
            'winner_slot' => null,
            'finished_at' => now(),
            'settings' => $settings,
        ]);

        Redis::srem(self::ACTIVE_SET, $game->uuid);
        Redis::del($this->redisKey($game));
        Redis::del($this->redisMapKey($game));

        $game->load('players');

        $this->broadcastIgnoringTransportFailure(new GameEnded(
            $game,
            null,
            null,
            $publicMessage,
        ));
    }

    /**
     * @return list<string>
     */
    public function activeGameUuids(): array
    {
        return Redis::smembers(self::ACTIVE_SET) ?: [];
    }

    /**
     * Remove UUIDs from the tick set when the row is gone or the match is no longer Playing.
     */
    public function pruneStaleActiveGameUuids(): void
    {
        foreach ($this->activeGameUuids() as $uuid) {
            $game = $this->findByUuid($uuid);
            if ($game === null || $game->status !== GameStatus::Playing) {
                Redis::srem(self::ACTIVE_SET, $uuid);
            }
        }
    }

    /**
     * Re-add every Playing game that still has live Redis JSON so {@see GameTickCommand} keeps
     * advancing world time even if {@see self::ACTIVE_SET} was cleared or never written.
     */
    public function syncActiveSetWithPlayingMatches(): void
    {
        $games = Game::query()
            ->where('status', GameStatus::Playing)
            ->whereNotNull('started_at')
            ->where('started_at', '>=', now()->subDays(30))
            ->orderByDesc('id')
            ->limit(500)
            ->cursor();

        foreach ($games as $game) {
            if (Redis::exists($this->redisKey($game))) {
                Redis::sadd(self::ACTIVE_SET, $game->uuid);
            }
        }
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

        $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        // If terrain was stripped (split format), re-inject static map data so that
        // Environment::fromArray() can reconstruct the full simulation object.
        if (isset($state['environment']) && ! isset($state['environment']['terrain'])) {
            $mapRaw = Redis::get($this->redisMapKey($game));
            if ($mapRaw) {
                $state['environment'] = array_merge(
                    json_decode($mapRaw, true, flags: JSON_THROW_ON_ERROR),
                    $state['environment'],
                );
            }
        }

        $this->ensurePlayingGameTrackedForTicks($game);

        return $state;
    }

    /**
     * The tick worker only advances games listed in {@see self::ACTIVE_SET}. If that set was
     * cleared or drifted while Redis still holds live JSON, re-register so `game:tick` picks up
     * the match again (idempotent SADD).
     */
    private function ensurePlayingGameTrackedForTicks(Game $game): void
    {
        if ($game->status !== GameStatus::Playing) {
            return;
        }

        $added = (int) Redis::sadd(self::ACTIVE_SET, $game->uuid);

        if ($added > 0) {
            GameSimLog::info('game.active_set.repaired', [
                'game_uuid' => $game->uuid,
                'added_members' => $added,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function storeLiveState(Game $game, array $state): void
    {
        // Terrain grids are the bulk of the state blob (~10× the mutable data). Strip them before
        // every write. ensureStaticMapDataStored uses SETNX so it only writes once per game life.
        $envData = $state['environment'] ?? [];
        if (isset($envData['terrain'])) {
            $this->ensureStaticMapDataStored($game, $envData);
            foreach (['terrain', 'forest', 'defaultVision'] as $staticField) {
                unset($state['environment'][$staticField]);
            }
        }

        Redis::set($this->redisKey($game), json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function environmentFromState(array $state): Environment
    {
        $environment = Environment::fromArray($state['environment']);
        // Vision is not persisted to Redis; rebuild it from current positions so that any
        // subsequent drawInfo() call (snapshot, broadcast, recruit) sees correct fog-of-war.
        $environment->recomputeVision();

        return $environment;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function repairLiveStateEconomy(Game $game, array &$state): bool
    {
        $slotCount = MatchPresenceMonitor::commanderSlotCount($state);
        if ($slotCount < 1) {
            return false;
        }

        $dirty = false;

        if (! isset($state['worldTick'])) {
            $state['worldTick'] = 0;
            $dirty = true;
        }

        if (! isset($state['economy']) || ! is_array($state['economy']) || count($state['economy']) !== $slotCount) {
            $state['economy'] = array_fill(0, $slotCount, [
                'credits' => GameConstants::ECONOMY_STARTING_CREDITS,
                'incomePerTick' => 0,
            ]);
            $dirty = true;
        }

        if ($dirty) {
            $this->storeLiveState($game, $state);
        }

        return $dirty;
    }

    public function recruitTank(Game $game, GamePlayer $gamePlayer): void
    {
        $this->recruit($game, $gamePlayer, 'tank', GameConstants::ECONOMY_RECRUIT_COST_TANK);
    }

    public function recruitInfantry(Game $game, GamePlayer $gamePlayer): void
    {
        $this->recruit($game, $gamePlayer, 'infantry', GameConstants::ECONOMY_RECRUIT_COST);
    }

    private function recruit(Game $game, GamePlayer $gamePlayer, string $troopType, int $cost): void
    {
        if ($game->status !== GameStatus::Playing) {
            abort(422, 'Recruiting is only available during a live match.');
        }

        if ($gamePlayer->game_id !== $game->id) {
            abort(403);
        }

        $state = $this->getLiveState($game);
        $this->repairLiveStateEconomy($game, $state);

        $environment = $this->environmentFromState($state);
        $slot = $gamePlayer->slot;
        $player = $environment->players[$slot] ?? null;

        if ($player === null) {
            abort(500, 'Invalid commander slot.');
        }

        $capital = $this->findOwnedCapitalCity($environment, $player);

        if ($capital === null) {
            abort(422, 'You must control your capital to recruit.');
        }

        if (count($player->troops) >= GameConstants::ECONOMY_MAX_ARMY_PER_PLAYER) {
            abort(422, 'Your army is at maximum size.');
        }

        if (! isset($state['economy'][$slot]) || ! is_array($state['economy'][$slot])) {
            abort(500, 'Economy state is missing for this commander.');
        }

        $credits = (int) ($state['economy'][$slot]['credits'] ?? 0);

        if ($credits < $cost) {
            abort(422, 'Not enough credits to recruit.');
        }

        $spawn = $this->findRecruitSpawnPosition($environment, $capital->position);

        if ($spawn === null) {
            abort(422, 'No clear rally point near your capital. Move units aside.');
        }

        $worldTick = (int) ($state['worldTick'] ?? 0);
        $troopId = $environment->takeNextTroopId();
        $player->spawnTroop($spawn, [], $troopId, $worldTick, $troopType);

        $state['economy'][$slot]['credits'] = $credits - $cost;
        $state['environment'] = $environment->toArray();

        $this->touchPlayerActivityInState($state, $slot);
        $this->storeLiveState($game, $state);

        $game->loadMissing('players');
        $this->broadcastState($game, $environment, $state);
    }

    public function broadcastState(Game $game, Environment $environment, array $state): void
    {
        $worldTick = (int) ($state['worldTick'] ?? 0);
        $economy = $state['economy'] ?? null;

        $game->loadMissing('players');

        foreach ($game->players as $player) {
            $this->broadcastIgnoringTransportFailure(new GameStateUpdated(
                $game,
                $player->broadcastConnection(),
                $environment->drawInfo($player->slot, $worldTick),
                is_array($economy) ? $economy : null,
                $worldTick,
            ));
        }
    }

    private function findOwnedCapitalCity(Environment $environment, Player $player): ?City
    {
        foreach ($environment->cities as $city) {
            if ($city->markerType === MapMarkers::TYPE_CAPITAL && $city->owner === $player) {
                return $city;
            }
        }

        return null;
    }

    /**
     * @param  array{0: float, 1: float}  $capitalPosition
     * @return array{0: float, 1: float}|null
     */
    private function findRecruitSpawnPosition(Environment $environment, array $capitalPosition): ?array
    {
        $blockedTerrain = ['mountain', 'water', 'deep_water', 'river'];
        $offsets = [];

        for ($dx = -15; $dx <= 15; $dx++) {
            for ($dy = -15; $dy <= 15; $dy++) {
                if ($dx === 0 && $dy === 0) {
                    continue;
                }

                $offsets[] = [$dx * GameConstants::CELL_SIZE, $dy * GameConstants::CELL_SIZE];
            }
        }

        usort($offsets, function (array $a, array $b): int {
            $da = $a[0] ** 2 + $a[1] ** 2;
            $db = $b[0] ** 2 + $b[1] ** 2;

            return $da <=> $db;
        });

        foreach ($offsets as [$ox, $oy]) {
            $pos = [(float) $capitalPosition[0] + $ox, (float) $capitalPosition[1] + $oy];
            $name = $environment->terrainNameAtWorldPosition($pos);

            if (in_array($name, $blockedTerrain, true)) {
                continue;
            }

            if (! $this->recruitPositionHasClearance($environment, $pos, (float) GameConstants::ECONOMY_RECRUIT_CLEARANCE)) {
                continue;
            }

            return $pos;
        }

        return null;
    }

    /**
     * @param  array{0: float, 1: float}  $pos
     */
    private function recruitPositionHasClearance(Environment $environment, array $pos, float $minDistance): bool
    {
        foreach ($environment->players as $p) {
            foreach ($p->troops as $t) {
                $dx = $t->position[0] - $pos[0];
                $dy = $t->position[1] - $pos[1];

                if (hypot($dx, $dy) < $minDistance) {
                    return false;
                }
            }
        }

        return true;
    }

    private function redisKey(Game $game): string
    {
        return 'game:live:'.$game->uuid;
    }

    /**
     * Key for the static terrain/grid data written once at game start.
     * Separating it from the per-tick mutable state (~10× smaller writes).
     */
    private function redisMapKey(Game $game): string
    {
        return 'game:map:'.$game->uuid;
    }

    /**
     * Writes the immutable terrain fields to a dedicated key. Uses SETNX so it is idempotent —
     * safe to call on every {@see storeLiveState} when terrain is present in the environment array.
     *
     * @param  array<string, mixed>  $envArray
     */
    private function ensureStaticMapDataStored(Game $game, array $envArray): void
    {
        $staticFields = ['seed', 'playerCount', 'gridMaxX', 'gridMaxY', 'terrain', 'forest', 'defaultVision'];
        $staticData = [];
        foreach ($staticFields as $field) {
            if (array_key_exists($field, $envArray)) {
                $staticData[$field] = $envArray[$field];
            }
        }

        Redis::setnx($this->redisMapKey($game), json_encode($staticData, JSON_THROW_ON_ERROR));
    }

    /**
     * Pushes a broadcast event without failing HTTP requests when Reverb/Pusher is unreachable
     * (for example when `php artisan reverb:start` is not running locally).
     */
    private function broadcastIgnoringTransportFailure(object $event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::warning('Game broadcast skipped (transport error).', [
                'event' => $event::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function assertLobbyWithinMaxAge(Game $game): void
    {
        if ($game->created_at->lt(now()->subSeconds(GameConstants::LOBBY_MAX_AGE_SECONDS))) {
            abort(410, 'This lobby expired after one hour without starting.');
        }
    }

    private function lobbyClosedMessage(Game $game): string
    {
        if ($game->status === GameStatus::Finished
            && ($game->settings['aborted_reason'] ?? null) === GameConstants::ABORTED_LOBBY_TIMEOUT) {
            return 'This lobby expired after one hour without starting.';
        }

        return 'This game has already started.';
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function touchPlayerActivityInState(array &$state, int $slot): void
    {
        $count = MatchPresenceMonitor::commanderSlotCount($state);
        if ($slot < 0 || $slot >= $count) {
            return;
        }

        $now = microtime(true);
        $activity = $state['lastPlayerActivityAt'] ?? [];
        if (! is_array($activity)) {
            $activity = [];
        }

        $normalized = [];
        for ($i = 0; $i < $count; $i++) {
            $normalized[$i] = isset($activity[$i]) && is_numeric($activity[$i])
                ? (float) $activity[$i]
                : $now;
        }
        $normalized[$slot] = $now;
        $state['lastPlayerActivityAt'] = $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotPayloadForSlot(Game $game, int $slot): array
    {
        $player = $game->players()->where('slot', $slot)->firstOrFail();
        $state = $this->getLiveState($game);
        $this->repairLiveStateEconomy($game, $state);
        $worldTick = (int) ($state['worldTick'] ?? 0);
        $environment = $this->environmentFromState($state);
        $terrainInfo = $environment->getTerrainInfo();
        $terrainCells = $this->terrainCellsForSnapshot($game->map_data, $terrainInfo['terrain']);

        return [
            'gameUuid' => $game->uuid,
            'slot' => $player->slot,
            'color' => $player->color,
            'terrain' => $terrainInfo['terrain'],
            'forest' => $terrainInfo['forest'],
            'cityPositions' => $terrainInfo['cityPositions'],
            'world' => $terrainInfo['world'],
            'state' => $environment->drawInfo($player->slot, $worldTick),
            'economy' => $state['economy'] ?? [],
            'worldTick' => $worldTick,
            ...($terrainCells !== null ? ['terrainCells' => $terrainCells] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotPayloadForPlayer(Game $game, int $userId): array
    {
        $player = $game->players()->where('user_id', $userId)->firstOrFail();

        return $this->snapshotPayloadForSlot($game, $player->slot);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotPayloadForGuest(Game $game, string $guestUuid): array
    {
        $player = $game->players()->where('guest_key', $guestUuid)->firstOrFail();

        return $this->snapshotPayloadForSlot($game, $player->slot);
    }

    /**
     * Original Map Builder terrain ids (when dimensions match the live marching-squares grid).
     *
     * @param  array<string, mixed>|null  $mapDataSnapshot
     * @param  list<list<float>>  $terrainGrid
     * @return list<list<string>>|null
     */
    private function terrainCellsForSnapshot(?array $mapDataSnapshot, array $terrainGrid): ?array
    {
        if (! is_array($mapDataSnapshot)) {
            return null;
        }

        $data = $mapDataSnapshot['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        $cells = $data['cells'] ?? null;
        if (! is_array($cells) || $cells === []) {
            return null;
        }

        $expectedRows = count($terrainGrid);
        $expectedCols = $expectedRows > 0 && isset($terrainGrid[0]) && is_array($terrainGrid[0])
            ? count($terrainGrid[0])
            : 0;

        if ($expectedRows < 1 || $expectedCols < 1) {
            return null;
        }

        if (count($cells) !== $expectedRows) {
            return null;
        }

        $normalized = [];

        foreach ($cells as $row) {
            if (! is_array($row) || count($row) !== $expectedCols) {
                return null;
            }

            $normalizedRow = [];
            foreach ($row as $cell) {
                $normalizedRow[] = is_string($cell) ? $cell : 'plains';
            }

            $normalized[] = $normalizedRow;
        }

        return $normalized;
    }
}
