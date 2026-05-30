<?php

namespace App\Http\Controllers\Games;

use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Games\CreateGameRequest;
use App\Http\Requests\Games\SubmitOrdersRequest;
use App\Games\Services\GameManager;
use App\Models\Game;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    public function index(Request $request): Response
    {
        $lobbies = Game::query()
            ->where('status', GameStatus::Lobby)
            ->with(['players.user', 'host'])
            ->withCount('players')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (Game $game) => $this->serializeLobby($game, $request->user()?->id));

        return Inertia::render('games/Lobby', [
            'lobbies' => $lobbies,
        ]);
    }

    public function store(CreateGameRequest $request, GameManager $gameManager): RedirectResponse
    {
        $game = $gameManager->create(
            $request->user(),
            $request->integer('max_players'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lobby created.']);

        return to_route('games.show', $game);
    }

    public function show(Request $request, Game $game): Response
    {
        $game->load(['players.user', 'host']);

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
                'players' => $game->players->map(fn ($p) => [
                    'slot' => $p->slot,
                    'name' => $p->user->name,
                    'color' => $p->color,
                ])->values(),
            ],
        ]);
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
    private function serializeLobby(Game $game, ?int $userId): array
    {
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
            'players' => $game->players->map(fn ($player) => [
                'slot' => $player->slot,
                'name' => $player->user->name,
                'color' => $player->color,
            ])->values(),
        ];
    }
}
