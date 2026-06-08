<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/Overview', [
            'stats' => $this->placeholderStats(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function placeholderStats(): array
    {
        return [
            'summary' => [
                'totalUsers' => 1_284,
                'activeGames' => 17,
                'matchesToday' => 42,
                'publishedMaps' => 86,
            ],
            'games' => [
                'lobby' => 12,
                'playing' => 17,
                'finished' => 3_418,
            ],
            'maps' => [
                'total' => 214,
                'published' => 86,
                'draft' => 128,
                'forks' => 37,
                'votes' => 1_902,
            ],
            'engagement' => [
                'avgPlayersPerGame' => 3.4,
                'avgGameDurationMinutes' => 28,
                'returningPlayersPercent' => 61,
                'newUsersThisWeek' => 94,
            ],
            'topMaps' => [
                ['name' => 'Iron Pass', 'plays' => 312, 'votes' => 148],
                ['name' => 'Coastal Siege', 'plays' => 276, 'votes' => 121],
                ['name' => 'Twin Rivers', 'plays' => 241, 'votes' => 97],
                ['name' => 'Highland Grid', 'plays' => 198, 'votes' => 84],
                ['name' => 'Desert Fork', 'plays' => 165, 'votes' => 72],
            ],
            'recentActivity' => [
                ['type' => 'game_started', 'label' => 'Match ABC123 started', 'time' => '2 minutes ago'],
                ['type' => 'map_published', 'label' => 'Map "Northern Reach" published', 'time' => '14 minutes ago'],
                ['type' => 'user_joined', 'label' => 'New commander signed up', 'time' => '27 minutes ago'],
                ['type' => 'game_finished', 'label' => 'Match XYZ789 finished', 'time' => '41 minutes ago'],
                ['type' => 'map_forked', 'label' => 'Iron Pass forked by a player', 'time' => '1 hour ago'],
                ['type' => 'lobby_created', 'label' => 'Lobby QWE456 opened', 'time' => '1 hour ago'],
            ],
        ];
    }
}
