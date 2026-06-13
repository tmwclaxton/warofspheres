<?php

namespace App\Jobs;

use App\Games\Services\GameManager;
use App\Models\Map;
use App\Models\MatchmakingEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** MMR range within which players are considered a fair match. */
const MMR_WINDOW = 200;

class MatchmakingJob implements ShouldQueue
{
    use Queueable;

    public function handle(GameManager $gameManager): void
    {
        DB::transaction(function () use ($gameManager) {
            $queued = MatchmakingEntry::query()
                ->where('status', 'queued')
                ->orderBy('queued_at')
                ->with('user')
                ->lockForUpdate()
                ->get();

            if ($queued->count() < 2) {
                return;
            }

            $map = Map::query()->where('published', true)->inRandomOrder()->first();
            if ($map === null) {
                return;
            }

            // Find the two longest-waiting players within the MMR window.
            $matched = null;
            foreach ($queued as $i => $entryA) {
                foreach ($queued as $j => $entryB) {
                    if ($j <= $i) {
                        continue;
                    }
                    $mmrA = $entryA->user->mmr ?? 1000;
                    $mmrB = $entryB->user->mmr ?? 1000;
                    if (abs($mmrA - $mmrB) <= MMR_WINDOW) {
                        $matched = [$entryA, $entryB];
                        break 2;
                    }
                }
            }

            // Fall back to any pair if no close MMR match found (avoids indefinite queuing).
            if ($matched === null) {
                $matched = [$queued[0], $queued[1]];
            }

            [$entryA, $entryB] = $matched;

            $game = $gameManager->create($entryA->user, $map);
            $gameManager->join($game, $entryB->user);

            MatchmakingEntry::query()
                ->whereIn('id', [$entryA->id, $entryB->id])
                ->update(['status' => 'matched', 'game_id' => $game->id]);
        });
    }
}
