<?php

namespace App\Maps;

use App\Games\Engine\City;
use App\Games\Engine\Environment;
use App\Games\Engine\MarchingSquares;
use App\Games\Engine\Player;
use App\Games\GameConstants;

/**
 * Converts Map Builder v2 JSON into live {@see Environment} marching-square grids, cities, and players.
 *
 * Uses the full editor cellRows×cellCols vertex grid with no cropping.
 */
final class BattlefieldFromMap
{
    /**
     * @param  array<string, mixed>  $mapDataV2
     * @param  array<int, int>  $teamIndicesBySlot  Optional slot→teamIndex mapping from GamePlayer records.
     */
    public static function populateEnvironment(Environment $environment, array $mapDataV2, array $teamIndicesBySlot = []): void
    {
        $cellRows = (int) ($mapDataV2['cellRows'] ?? 0);
        $cellCols = (int) ($mapDataV2['cellCols'] ?? 0);
        $cells = $mapDataV2['cells'] ?? null;
        if (! is_array($cells) || $cellRows < 2 || $cellCols < 2) {
            throw new \InvalidArgumentException('Map data is missing a valid cells grid.');
        }

        $teamCount = (int) ($mapDataV2['teamCount'] ?? 0);
        if ($teamCount < GameConstants::MIN_PLAYERS || $teamCount > GameConstants::MAX_PLAYERS) {
            throw new \InvalidArgumentException('Invalid teamCount on map snapshot.');
        }

        for ($r = 0; $r < $cellRows; $r++) {
            $row = $cells[$r] ?? null;
            if (! is_array($row) || count($row) !== $cellCols) {
                throw new \InvalidArgumentException("Map cells row {$r} must have length {$cellCols}.");
            }
        }

        $environment->configureFromMapVertexGrid($cellRows, $cellCols);

        $markers = $mapDataV2['markers'] ?? [];
        if (! is_array($markers)) {
            throw new \InvalidArgumentException('Map markers must be an array.');
        }

        $maxR = $environment->gridMaxX;
        $maxC = $environment->gridMaxY;

        /** @var array<int, array{0: int, 1: int}> $capitalEditorByTeam */
        $capitalEditorByTeam = [];
        foreach ($markers as $marker) {
            if (! is_array($marker)) {
                continue;
            }
            if (($marker['type'] ?? '') !== MapMarkers::TYPE_CAPITAL) {
                continue;
            }
            $team = (int) ($marker['team'] ?? 0);
            if ($team < 0 || $team >= $teamCount) {
                continue;
            }
            $capitalEditorByTeam[$team] = [(int) ($marker['row'] ?? -1), (int) ($marker['col'] ?? -1)];
        }

        for ($t = 0; $t < $teamCount; $t++) {
            if (! isset($capitalEditorByTeam[$t])) {
                throw new \InvalidArgumentException("Map is missing a capital marker for team slot {$t}.");
            }
            [$r, $c] = $capitalEditorByTeam[$t];
            if ($r < 0 || $r > $maxR || $c < 0 || $c > $maxC) {
                throw new \InvalidArgumentException("Capital for team slot {$t} is outside the map grid.");
            }
        }

        $terrainGrid = MarchingSquares::emptyGrid($maxR, $maxC);
        $forestGrid = MarchingSquares::emptyGrid($maxR, $maxC);

        for ($gx = 0; $gx <= $maxR; $gx++) {
            for ($gy = 0; $gy <= $maxC; $gy++) {
                $terrainId = is_string($cells[$gx][$gy] ?? null) ? $cells[$gx][$gy] : 'plains';
                $terrainGrid[$gx][$gy] = self::editorTerrainToElevation($terrainId);
                $forestGrid[$gx][$gy] = self::editorTerrainToForestOverlay($terrainId);
            }
        }

        /** @var list<array{row: int, col: int, team: int}> $flagSites */
        $flagSites = [];
        /** @var array<int, array{row: int, col: int}> $capitalByTeam */
        $capitalByTeam = [];
        /** @var list<array{type: string, row: int, col: int, team: int}> $troopSites */
        $troopSites = [];

        foreach ($markers as $marker) {
            if (! is_array($marker)) {
                continue;
            }
            $row = (int) ($marker['row'] ?? -1);
            $col = (int) ($marker['col'] ?? -1);
            if ($row < 0 || $row > $maxR || $col < 0 || $col > $maxC) {
                continue;
            }
            $type = $marker['type'] ?? '';
            $team = (int) ($marker['team'] ?? 0);
            if ($type === MapMarkers::TYPE_FLAG) {
                $flagSites[] = ['row' => $row, 'col' => $col, 'team' => $team];
            } elseif ($type === MapMarkers::TYPE_CAPITAL) {
                $capitalByTeam[$team] = ['row' => $row, 'col' => $col];
            } elseif (MapMarkers::isTroopMarkerType($type)) {
                $troopSites[] = ['type' => (string) $type, 'row' => $row, 'col' => $col, 'team' => $team];
            }
        }

        usort($flagSites, fn (array $a, array $b): int => [$a['row'], $a['col']] <=> [$b['row'], $b['col']]);

        /** @var list<City> $cities */
        $cities = [];
        $nextCityId = 1;

        foreach ($flagSites as $f) {
            $cities[] = new City(self::cellVertexToWorld($f['row'], $f['col']), $nextCityId++, MapMarkers::TYPE_FLAG);
        }

        ksort($capitalByTeam);
        for ($t = 0; $t < $teamCount; $t++) {
            if (! isset($capitalByTeam[$t])) {
                throw new \InvalidArgumentException("Missing capital for team slot {$t} on the map.");
            }
            $capitalCell = $capitalByTeam[$t];
            $cities[] = new City(self::cellVertexToWorld($capitalCell['row'], $capitalCell['col']), $nextCityId++, MapMarkers::TYPE_CAPITAL);
        }

        /** @var array<int, City> $capitalCityByTeam */
        $capitalCityByTeam = [];
        $capitalIndex = count($flagSites);
        for ($t = 0; $t < $teamCount; $t++) {
            $capitalCityByTeam[$t] = $cities[$capitalIndex + $t];
        }

        $environment->terrainMarching->setGrid($terrainGrid);
        $environment->forestMarching->setGrid($forestGrid);
        $environment->rebuildDefaultVisionFromTerrain();

        /** @var array<int, Player> $players */
        $players = [];
        $nextTroopId = 1;
        for ($slot = 0; $slot < $teamCount; $slot++) {
            $city = $capitalCityByTeam[$slot];
            $teamIndex = $teamIndicesBySlot[$slot] ?? 0;
            $player = new Player($city->position, GameConstants::COLORS[$slot], $slot, $environment, $nextTroopId++, $teamIndex);
            $city->owner = $player;
            $players[$slot] = $player;
        }

        foreach ($troopSites as $site) {
            $team = $site['team'];
            if ($team < 0 || $team >= $teamCount || ! isset($players[$team])) {
                continue;
            }
            $pos = self::cellVertexToWorld($site['row'], $site['col']);
            $players[$team]->spawnTroop($pos, [], $nextTroopId++, -1, $site['type']);
        }

        /** @var list<Player> $playersList */
        $playersList = [];
        for ($i = 0; $i < $teamCount; $i++) {
            if (! isset($players[$i])) {
                throw new \InvalidArgumentException('Player slots must be contiguous from 0.');
            }
            $playersList[] = $players[$i];
        }

        $environment->setCitiesPlayersAndIds(
            $cities,
            $playersList,
            $nextCityId,
            $nextTroopId,
        );
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function cellVertexToWorld(int $gridX, int $gridY): array
    {
        return [
            $gridX * GameConstants::CELL_SIZE,
            $gridY * GameConstants::CELL_SIZE,
        ];
    }

    private static function editorTerrainToElevation(string $terrainId): float
    {
        return match ($terrainId) {
            'water', 'river', 'deep_water' => GameConstants::TERRAIN_VALUES['water'] - 0.02,
            'swamp' => 0.04,   // Between swamp threshold (0.025) and beach (0.05) → 'swamp'
            'beach' => 0.065,  // Between beach threshold (0.05) and plains (0.1) → 'beach'
            'snow' => 0.32,     // Between snow threshold (0.30) and desert (0.55) → 'snow'
            'desert' => 0.57,   // Between desert threshold (0.55) and hill (0.7) → 'desert'
            'plains', 'meadow', 'forest', 'dense_forest' => GameConstants::TERRAIN_VALUES['plains'] + 0.02,
            'hill' => GameConstants::TERRAIN_VALUES['hill'],
            'mountain' => GameConstants::TERRAIN_VALUES['mountain'] + 0.02,
            default => GameConstants::TERRAIN_VALUES['plains'],
        };
    }

    private static function editorTerrainToForestOverlay(string $terrainId): float
    {
        return match ($terrainId) {
            'dense_forest' => 0.72,
            'forest' => 0.58,
            'meadow' => 0.38,
            'swamp' => 0.42,
            default => 0.08,
        };
    }
}
