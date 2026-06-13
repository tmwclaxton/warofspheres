<?php

namespace App\Games\Services;

use App\Enums\GameStatus;
use App\Games\Engine\City;
use App\Games\Engine\Environment;
use App\Games\GameConstants;
use App\Models\Game;
use App\Models\GameReplaySnapshot;

final class GameTickService
{
    public function tick(Game $game, GameManager $manager): void
    {
        if ($game->status !== GameStatus::Playing) {
            return;
        }

        $state = $manager->getLiveState($game);
        $manager->repairLiveStateEconomy($game, $state);

        $activityDirty = MatchPresenceMonitor::normalizeLastActivity($state);

        if (MatchPresenceMonitor::everyoneIdleForAtLeast($state, GameConstants::MATCH_ALL_PLAYERS_INACTIVE_SECONDS)) {
            $manager->finishWithoutWinner(
                $game,
                GameConstants::ABORTED_MATCH_INACTIVITY,
                'Match ended — all commanders inactive for over two minutes.',
            );

            return;
        }

        if ($activityDirty) {
            $manager->storeLiveState($game, $state);
        }

        $environment = $manager->environmentFromState($state);

        $worldTick = (int) ($state['worldTick'] ?? 0);

        $cityPaths = [];
        foreach ($state['playerCityInputs'] as $inputs) {
            $cityPaths = array_merge($cityPaths, $inputs);
        }
        $state['playerCityInputs'] = array_fill(0, count($state['playerCityInputs']), []);
        $environment->updateCities($cityPaths, $worldTick);

        $troopPaths = [];
        foreach ($state['playerInputs'] as $inputs) {
            $troopPaths = array_merge($troopPaths, $inputs);
        }
        $state['playerInputs'] = array_fill(0, count($state['playerInputs']), []);
        $environment->updateTroops($troopPaths, $worldTick);

        $state['environment'] = $environment->toArray();
        $state['worldTick'] = $worldTick + 1;
        $this->applyEconomyTick($state, $environment);
        $manager->storeLiveState($game, $state);
        $manager->broadcastState($game, $environment, $state);

        // Write a replay snapshot once per second (every TICK_RATE ticks).
        if ($worldTick % GameConstants::TICK_RATE === 0) {
            $this->writeReplaySnapshot($game, $worldTick, $state, $manager);
        }

        $winnerSlot = $environment->winnerSlot();
        if ($winnerSlot !== null) {
            $manager->finish($game, $winnerSlot);
        }
    }

    /**
     * Writes an omniscient (all-visibility) snapshot so the replay viewer sees all units.
     *
     * @param  array<string, mixed>  $state
     */
    private function writeReplaySnapshot(Game $game, int $worldTick, array $state, GameManager $manager): void
    {
        try {
            $environment = $manager->environmentFromState($state);
            // Build spectator view: all troops visible, no fog.
            $allTroops = [];
            foreach ($environment->players as $player) {
                foreach ($player->troops as $troop) {
                    $warmup = $environment->troopWarmupMultiplier($troop, $worldTick);
                    $moraleFac = max(0.25, $troop->morale / 100.0);
                    $allTroops[] = [
                        'position' => $troop->position,
                        'color' => $troop->owner->color,
                        'id' => $troop->id,
                        'ownerSlot' => $troop->owner->slot,
                        'path' => $troop->path,
                        'health' => $troop->health,
                        'morale' => $troop->morale,
                        'type' => $troop->type,
                        'maxHealth' => $troop->maxHealth(),
                        'isShip' => $troop->isShip,
                        'warmupMultiplier' => round($warmup, 3),
                        'combatMultiplier' => round($warmup * $moraleFac, 3),
                    ];
                }
            }

            $allCities = array_map(fn ($c) => [
                'ownerColor' => $c->owner?->color,
                'position' => $c->position,
                'id' => $c->id,
                'path' => $c->path,
                'ownerSlot' => $c->owner?->slot,
                'markerType' => $c->markerType,
                'productionType' => $c->productionType,
                'productionTankRatio' => $c->productionTankRatio,
                'productionSpeedMultiplier' => $c->productionSpeedMultiplier,
            ], $environment->cities);

            $viewState = [
                'vision' => [],
                'border' => [],
                'troops' => $allTroops,
                'cities' => $allCities,
            ];

            $snapshot = ['latestState' => $viewState, 'economy' => $state['economy'] ?? null];

            GameReplaySnapshot::create([
                'game_id' => $game->id,
                'world_tick' => $worldTick,
                'state_json' => GameReplaySnapshot::encodeState($snapshot),
            ]);
        } catch (\Throwable) {
            // Replay writes are best-effort; never let them break the game loop.
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function applyEconomyTick(array &$state, Environment $environment): void
    {
        $economy = $state['economy'] ?? null;
        if (! is_array($economy)) {
            return;
        }

        $playerCount = count($economy);
        for ($slot = 0; $slot < $playerCount; $slot++) {
            if (! isset($economy[$slot]) || ! is_array($economy[$slot])) {
                continue;
            }

            $player = $environment->players[$slot] ?? null;
            if ($player === null) {
                continue;
            }

            $ownedCities = count(array_filter($environment->cities, fn (City $c) => $c->owner === $player));
            $income = $ownedCities * GameConstants::ECONOMY_INCOME_PER_CITY_PER_TICK;
            $credits = (int) ($economy[$slot]['credits'] ?? 0);
            $credits += $income;
            $economy[$slot]['credits'] = $credits;
            $economy[$slot]['incomePerTick'] = $income;
        }

        $state['economy'] = $economy;
    }
}
