<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Map;
use App\Models\MapVote;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/Overview', [
            'stats' => $this->liveStats(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function liveStats(): array
    {
        $totalUsers = User::query()->count();
        $activeGames = Game::query()->where('status', GameStatus::Playing)->count();
        $matchesToday = Game::query()
            ->where('status', GameStatus::Finished)
            ->whereDate('finished_at', now()->toDateString())
            ->count();
        $publishedMaps = Map::query()->where('published', true)->count();

        $lobbyCount = Game::query()->where('status', GameStatus::Lobby)->count();
        $finishedCount = Game::query()->where('status', GameStatus::Finished)->count();

        $totalMaps = Map::query()->count();
        $draftMaps = Map::query()->where('published', false)->count();
        $forkedMaps = Map::query()->whereNotNull('forked_from_id')->count();
        $totalVotes = MapVote::query()->count();

        $finishedGames = Game::query()
            ->where('status', GameStatus::Finished)
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->withCount('players')
            ->get(['id', 'started_at', 'finished_at', 'players_count']);

        $avgPlayersRaw = $finishedGames->avg('players_count');
        $avgDurationRaw = $finishedGames->avg(
            fn (Game $game) => $game->started_at->diffInSeconds($game->finished_at) / 60,
        );

        $usersWithMultipleGames = User::query()
            ->whereHas('gamePlayers', fn ($q) => $q, '>=', 2)
            ->count();
        $usersWithAnyGame = User::query()->has('gamePlayers')->count();
        $returningPercent = $usersWithAnyGame > 0
            ? (int) round($usersWithMultipleGames / $usersWithAnyGame * 100)
            : 0;

        $newUsersThisWeek = User::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $topMaps = Map::query()
            ->where('published', true)
            ->orderByDesc('games_count')
            ->take(5)
            ->get(['name', 'games_count'])
            ->map(fn (Map $m) => [
                'name' => $m->name,
                'plays' => $m->games_count,
                'votes' => MapVote::query()
                    ->where('map_id', $m->getKey())
                    ->where('value', 1)
                    ->count(),
            ])
            ->all();

        $recentActivity = $this->recentActivity();

        return [
            'summary' => [
                'totalUsers' => $totalUsers,
                'activeGames' => $activeGames,
                'matchesToday' => $matchesToday,
                'publishedMaps' => $publishedMaps,
            ],
            'games' => [
                'lobby' => $lobbyCount,
                'playing' => $activeGames,
                'finished' => $finishedCount,
            ],
            'maps' => [
                'total' => $totalMaps,
                'published' => $publishedMaps,
                'draft' => $draftMaps,
                'forks' => $forkedMaps,
                'votes' => $totalVotes,
            ],
            'engagement' => [
                'avgPlayersPerGame' => $avgPlayersRaw !== null ? round((float) $avgPlayersRaw, 1) : null,
                'avgGameDurationMinutes' => $avgDurationRaw !== null ? (int) round((float) $avgDurationRaw) : null,
                'returningPlayersPercent' => $returningPercent,
                'newUsersThisWeek' => $newUsersThisWeek,
            ],
            'topMaps' => $topMaps,
            'recentActivity' => $recentActivity,
        ];
    }

    /**
     * @return list<array{type: string, label: string, time: string}>
     */
    private function recentActivity(): array
    {
        $events = [];

        $recentGames = Game::query()
            ->where('status', GameStatus::Playing)
            ->orWhere(fn ($q) => $q->where('status', GameStatus::Finished)->whereNotNull('started_at'))
            ->latest()
            ->take(3)
            ->get(['code', 'status', 'started_at', 'finished_at', 'created_at']);

        foreach ($recentGames as $game) {
            if ($game->status === GameStatus::Playing && $game->started_at !== null) {
                $events[] = [
                    'type' => 'game_started',
                    'label' => "Match {$game->code} started",
                    'time' => $game->started_at->diffForHumans(),
                    'at' => $game->started_at->timestamp,
                ];
            } elseif ($game->status === GameStatus::Finished && $game->finished_at !== null) {
                $events[] = [
                    'type' => 'game_finished',
                    'label' => "Match {$game->code} finished",
                    'time' => $game->finished_at->diffForHumans(),
                    'at' => $game->finished_at->timestamp,
                ];
            }
        }

        $recentMaps = Map::query()
            ->where('published', true)
            ->latest('updated_at')
            ->take(2)
            ->get(['name', 'forked_from_id', 'updated_at']);

        foreach ($recentMaps as $map) {
            $type = $map->forked_from_id !== null ? 'map_forked' : 'map_published';
            $label = $map->forked_from_id !== null
                ? "Map \"{$map->name}\" forked"
                : "Map \"{$map->name}\" published";
            $events[] = [
                'type' => $type,
                'label' => $label,
                'time' => $map->updated_at->diffForHumans(),
                'at' => $map->updated_at->timestamp,
            ];
        }

        $recentUsers = User::query()
            ->latest()
            ->take(2)
            ->get(['created_at']);

        foreach ($recentUsers as $user) {
            $events[] = [
                'type' => 'user_joined',
                'label' => 'New commander signed up',
                'time' => $user->created_at->diffForHumans(),
                'at' => $user->created_at->timestamp,
            ];
        }

        usort($events, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_map(
            fn (array $e) => ['type' => $e['type'], 'label' => $e['label'], 'time' => $e['time']],
            array_slice($events, 0, 6),
        );
    }
}
