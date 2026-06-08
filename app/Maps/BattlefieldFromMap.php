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
 * Large editor maps are center-cropped to {@see MapEditorGrid::LIVE_BATTLEFIELD_CELL_ROWS}×{@see MapEditorGrid::LIVE_BATTLEFIELD_CELL_COLS}
 * (same vertex resolution as {@see GameConstants::ROWS}+1 / {@see GameConstants::COLS}+1).
 */
final class BattlefieldFromMap
{
    /**
     * @param  array<string, mixed>  $mapDataV2
     */
    public static function populateEnvironment(Environment $environment, array $mapDataV2): void
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

        $targetRows = MapEditorGrid::LIVE_BATTLEFIELD_CELL_ROWS;
        $targetCols = MapEditorGrid::LIVE_BATTLEFIELD_CELL_COLS;

        $offRow = max(0, (int) floor(($cellRows - $targetRows) / 2));
        $offCol = max(0, (int) floor(($cellCols - $targetCols) / 2));

        if ($cellRows < $targetRows || $cellCols < $targetCols) {
            throw new \InvalidArgumentException("Map grid {$cellRows}×{$cellCols} is smaller than the live battlefield ({$targetRows}×{$targetCols}).");
        }

        $terrainGrid = MarchingSquares::emptyGrid();
        $forestGrid = MarchingSquares::emptyGrid();

        for ($gx = 0; $gx <= GameConstants::ROWS; $gx++) {
            for ($gy = 0; $gy <= GameConstants::COLS; $gy++) {
                $er = $offRow + $gx;
                $ec = $offCol + $gy;
                $terrainId = is_string($cells[$er][$ec] ?? null) ? $cells[$er][$ec] : 'plains';
                $terrainGrid[$gx][$gy] = self::editorTerrainToElevation($terrainId);
                $forestGrid[$gx][$gy] = self::editorTerrainToForestOverlay($terrainId);
            }
        }

        $markers = $mapDataV2['markers'] ?? [];
        if (! is_array($markers)) {
            throw new \InvalidArgumentException('Map markers must be an array.');
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
            $adjRow = $row - $offRow;
            $adjCol = $col - $offCol;
            if ($adjRow < 0 || $adjRow > GameConstants::ROWS || $adjCol < 0 || $adjCol > GameConstants::COLS) {
                continue;
            }
            $type = $marker['type'] ?? '';
            $team = (int) ($marker['team'] ?? 0);
            if ($type === MapMarkers::TYPE_FLAG) {
                $flagSites[] = ['row' => $adjRow, 'col' => $adjCol, 'team' => $team];
            } elseif ($type === MapMarkers::TYPE_CAPITAL) {
                $capitalByTeam[$team] = ['row' => $adjRow, 'col' => $adjCol];
            } elseif (MapMarkers::isTroopMarkerType($type)) {
                $troopSites[] = ['type' => (string) $type, 'row' => $adjRow, 'col' => $adjCol, 'team' => $team];
            }
        }

        usort($flagSites, fn (array $a, array $b): int => [$a['row'], $a['col']] <=> [$b['row'], $b['col']]);

        /** @var list<City> $cities */
        $cities = [];
        $nextCityId = 1;

        foreach ($flagSites as $f) {
            $cities[] = new City(self::cellVertexToWorld($f['row'], $f['col']), $nextCityId++);
        }

        ksort($capitalByTeam);
        for ($t = 0; $t < $teamCount; $t++) {
            if (! isset($capitalByTeam[$t])) {
                throw new \InvalidArgumentException("Missing capital for team slot {$t} after cropping.");
            }
            $c = $capitalByTeam[$t];
            $cities[] = new City(self::cellVertexToWorld($c['row'], $c['col']), $nextCityId++);
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
            $player = new Player($city->position, GameConstants::COLORS[$slot], $slot, $environment, $nextTroopId++);
            $city->owner = $player;
            $players[$slot] = $player;
        }

        foreach ($troopSites as $site) {
            $team = $site['team'];
            if ($team < 0 || $team >= $teamCount || ! isset($players[$team])) {
                continue;
            }
            $pos = self::cellVertexToWorld($site['row'], $site['col']);
            $players[$team]->spawnTroop($pos, [], $nextTroopId++);
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
            'swamp' => 0.04,
            'plains', 'meadow', 'desert', 'beach', 'forest', 'dense_forest' => GameConstants::TERRAIN_VALUES['plains'] + 0.02,
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
