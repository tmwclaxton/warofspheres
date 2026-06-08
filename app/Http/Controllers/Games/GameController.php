<?php

namespace App\Http\Controllers\Games;

use App\Enums\GameStatus;
use App\Games\GameConstants;
use App\Games\Services\GameManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Games\CreateGameRequest;
use App\Http\Requests\Games\SubmitOrdersRequest;
use App\Models\Game;
use App\Models\Map;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    public function lobbies(Request $request): Response
    {
        $lobbies = Game::query()
            ->where('status', GameStatus::Lobby)
            ->with(['players.user', 'host', 'map.user'])
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (Game $game) => $this->serializeLobby($game, $request->user()->id));

        $publishedMaps = Map::query()
            ->where('published', true)
            ->with(['user:id,name'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (Map $map) => [
                'uuid' => $map->uuid,
                'name' => $map->name,
                'teamCount' => (int) ($map->data['teamCount'] ?? GameConstants::MIN_PLAYERS),
                'ownerName' => $map->user?->name ?? 'Unknown',
            ]);

        return Inertia::render('games/Lobby', [
            'lobbies' => $lobbies,
            'publishedMaps' => $publishedMaps,
        ]);
    }

    public function ongoing(Request $request): Response
    {
        $matches = Game::query()
            ->where('status', GameStatus::Playing)
            ->whereHas('players', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with(['players.user', 'host', 'map.user'])
            ->latest('started_at')
            ->limit(20)
            ->get()
            ->map(fn (Game $game) => $this->serializeMatch($game, $request->user()->id));

        return Inertia::render('matches/Ongoing', [
            'matches' => $matches,
        ]);
    }

    public function past(Request $request): Response
    {
        $matches = Game::query()
            ->where('status', GameStatus::Finished)
            ->whereHas('players', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with(['players.user', 'host', 'winner', 'map.user'])
            ->latest('finished_at')
            ->limit(20)
            ->get()
            ->map(fn (Game $game) => $this->serializeMatch($game, $request->user()->id));

        return Inertia::render('matches/Past', [
            'matches' => $matches,
        ]);
    }

    public function store(CreateGameRequest $request, GameManager $gameManager): RedirectResponse
    {
        $map = Map::query()
            ->where('uuid', $request->string('map_uuid'))
            ->where('published', true)
            ->firstOrFail();

        $game = $gameManager->create(
            $request->user(),
            $map,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lobby created.']);

        return to_route('games.show', $game);
    }

    public function show(Request $request, Game $game): Response
    {
        $game->load(['players.user', 'host', 'map.user']);

        return Inertia::render('games/Show', [
            'game' => $this->serializeLobby($game, $request->user()?->id),
        ]);
    }

    public function join(Request $request, Game $game, GameManager $gameManager): RedirectResponse
    {
        $gameManager->join($game, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Joined lobby.']);

        return to_route('games.show', $game);
    }

    public function joinByCode(Request $request, GameManager $gameManager): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:6']]);

        $game = Game::query()->where('code', strtoupper($request->string('code')))->firstOrFail();
        $gameManager->join($game, $request->user());

        return to_route('games.show', $game);
    }

    public function start(Request $request, Game $game, GameManager $gameManager): RedirectResponse
    {
        $gameManager->start($game, $request->user());

        return to_route('games.play', $game);
    }

    public function play(Request $request, Game $game): Response
    {
        abort_unless($game->status === GameStatus::Playing, 404);

        $player = $game->players()->where('user_id', $request->user()->id)->firstOrFail();

        $game->load(['players.user']);

        return Inertia::render('games/Play', [
            'game' => [
                'uuid' => $game->uuid,
                'code' => $game->code,
                'maxPlayers' => $game->max_players,
                'slot' => $player->slot,
                'color' => $player->color,
                'players' => $game->players->sortBy('slot')->values()->map(fn ($p) => [
                    'slot' => $p->slot,
                    'name' => $p->user->name,
                    'color' => $p->color,
                ]),
            ],
            'snapshotUrl' => route('games.snapshot', $game),
        ]);
    }

    public function snapshot(Request $request, Game $game, GameManager $gameManager): JsonResponse
    {
        abort_unless($game->status === GameStatus::Playing, 404);

        $game->players()->where('user_id', $request->user()->id)->firstOrFail();

        return response()->json($gameManager->snapshotPayloadForPlayer($game, $request->user()->id));
    }

    public function submitOrders(SubmitOrdersRequest $request, Game $game, GameManager $gameManager): RedirectResponse
    {
        $gameManager->submitOrders($game, $request->user(), [
            $request->input('troop_orders', []),
            $request->input('city_orders', []),
        ]);

        return back();
    }

    public function togglePause(Request $request, Game $game, GameManager $gameManager): RedirectResponse
    {
        $request->validate(['paused' => ['required', 'boolean']]);
        $gameManager->togglePause($game, $request->user(), $request->boolean('paused'));

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMatch(Game $game, int $userId): array
    {
        return [
            ...$this->serializeLobby($game, $userId),
            'startedAt' => $game->started_at?->toIso8601String(),
            'finishedAt' => $game->finished_at?->toIso8601String(),
            'winnerName' => $game->winner?->name,
            'isWinner' => $game->winner_user_id === $userId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLobby(Game $game, ?int $userId): array
    {
        $game->loadMissing('map.user');

        $sourceMap = null;
        if ($game->map !== null) {
            $sourceMap = [
                'uuid' => $game->map->uuid,
                'name' => $game->map->name,
                'by' => $game->map->user?->name ?? 'Unknown',
            ];
        } elseif (is_array($game->map_data)) {
            $snap = $game->map_data;
            $sourceMap = [
                'uuid' => (string) ($snap['source_uuid'] ?? ''),
                'name' => (string) ($snap['source_name'] ?? 'Unknown map'),
                'by' => (string) ($snap['source_author'] ?? 'Unknown'),
            ];
        }

        return [
            'uuid' => $game->uuid,
            'code' => $game->code,
            'status' => $game->status->value,
            'maxPlayers' => $game->max_players,
            'playerCount' => $game->players->count(),
            'isHost' => $userId !== null && $game->host_user_id === $userId,
            'isParticipant' => $userId !== null && $game->players->contains('user_id', $userId),
            'canStart' => $game->canStart(),
            'hostName' => $game->host?->name,
            'players' => $game->players->sortBy('slot')->values()->map(fn ($player) => [
                'slot' => $player->slot,
                'name' => $player->user->name,
                'color' => $player->color,
            ]),
            'sourceMap' => $sourceMap,
        ];
    }
}
