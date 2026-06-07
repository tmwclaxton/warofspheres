<?php

namespace App\Maps;

use App\Games\GameConstants;

/**
 * Map editor markers (capitals, flags) and validation for {@see MapEditorGrid} v2 payloads.
 */
final class MapMarkers
{
    public const string TYPE_CAPITAL = 'capital';

    public const string TYPE_FLAG = 'flag';

    private const int MIN_MARKER_MANHATTAN_SEP = 6;

    /** @var list<string> */
    public const array NON_PLACEABLE_TERRAIN = ['water', 'deep_water', 'river', 'hill', 'mountain'];

    /** @var list<string> */
    private const array HYDRAULIC_WATER_TERRAIN = ['water', 'deep_water', 'river'];

    private const int MIN_CHEBYSHEV_FROM_WATER = 2;

    /** @var list<string> */
    private const array FACTION_LABELS = ['red', 'blue', 'orange', 'purple', 'green', 'cyan'];

    public static function isPlaceableTerrain(string $terrain): bool
    {
        return ! in_array($terrain, self::NON_PLACEABLE_TERRAIN, true);
    }

    /**
     * @param  array<int, array<int, string>>  $cells
     */
    private static function markerHasHydraulicWaterBuffer(array $cells, int $cellRows, int $cellCols, int $row, int $col): bool
    {
        $ext = self::MIN_CHEBYSHEV_FROM_WATER - 1;

        for ($dr = -$ext; $dr <= $ext; $dr++) {
            for ($dc = -$ext; $dc <= $ext; $dc++) {
                $r = $row + $dr;
                $c = $col + $dc;

                if ($r < 0 || $r >= $cellRows || $c < 0 || $c >= $cellCols) {
                    continue;
                }

                $t = $cells[$r][$c] ?? null;

                if (is_string($t) && in_array($t, self::HYDRAULIC_WATER_TERRAIN, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return list<array{slot: int, hex: string, label: string}>
     */
    public static function teamColorsForClient(): array
    {
        $out = [];
        for ($slot = 0; $slot < GameConstants::MAX_PLAYERS; $slot++) {
            $out[] = [
                'slot' => $slot,
                'hex' => GameConstants::colorHex($slot),
                'label' => self::FACTION_LABELS[$slot] ?? "slot{$slot}",
            ];
        }

        return $out;
    }

    /**
     * @return list<int> One faction palette index per logical team `0 .. $teamCount - 1`
     */
    private static function normalizeTeamPaletteSlots(int $teamCount, mixed $raw): array
    {
        $max = GameConstants::MAX_PLAYERS;
        if ($teamCount < GameConstants::MIN_PLAYERS || $teamCount > $max) {
            return range(0, max(0, GameConstants::MIN_PLAYERS - 1));
        }
        if (! is_array($raw) || count($raw) !== $teamCount) {
            return range(0, $teamCount - 1);
        }
        $out = [];
        foreach ($raw as $v) {
            if (! is_int($v) && ! (is_numeric($v) && (string) (int) $v === (string) $v)) {
                return range(0, $teamCount - 1);
            }
            $iv = (int) $v;
            if ($iv < 0 || $iv >= $max) {
                return range(0, $teamCount - 1);
            }
            $out[] = $iv;
        }
        if (count(array_unique($out)) !== count($out)) {
            return range(0, $teamCount - 1);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function validateTeamPaletteSlotsInput(int $teamCount, mixed $raw): array
    {
        $errors = [];
        if ($raw === null) {
            return $errors;
        }
        if (! is_array($raw)) {
            $errors[] = 'teamPaletteSlots must be an array.';

            return $errors;
        }
        if (count($raw) !== $teamCount) {
            $errors[] = "teamPaletteSlots must have length {$teamCount}.";

            return $errors;
        }
        $max = GameConstants::MAX_PLAYERS;
        $seen = [];
        foreach ($raw as $i => $v) {
            if (! is_int($v) && ! (is_numeric($v) && (string) (int) $v === (string) $v)) {
                $errors[] = "teamPaletteSlots[{$i}] must be an integer.";

                return $errors;
            }
            $iv = (int) $v;
            if ($iv < 0 || $iv >= $max) {
                $errors[] = 'teamPaletteSlots entries must be between 0 and '.($max - 1).'.';

                return $errors;
            }
            if (isset($seen[$iv])) {
                $errors[] = 'teamPaletteSlots must not assign the same faction colour to two teams.';

                return $errors;
            }
            $seen[$iv] = true;
        }

        return $errors;
    }

    /**
     * Human label for a logical team index using the palette slot map.
     *
     * @param  list<int>  $palette
     */
    private static function factionLabelFromPalette(array $palette, int $teamIndex): string
    {
        $slot = $palette[$teamIndex] ?? $teamIndex;

        return self::FACTION_LABELS[$slot] ?? "team {$teamIndex}";
    }

    /**
     * Validate v2 marker rules. Assumes terrain grid is already valid.
     *
     * @param  array<string, mixed>  $data  Full map data (version 2)
     * @return list<string> Human-readable errors (empty if valid)
     */
    public static function validate(array $data): array
    {
        $errors = [];

        $teamCount = $data['teamCount'] ?? null;
        if (! is_int($teamCount) && ! (is_numeric($teamCount) && (string) (int) $teamCount === (string) $teamCount)) {
            $errors[] = 'teamCount must be an integer.';

            return $errors;
        }
        $teamCount = (int) $teamCount;
        if ($teamCount < GameConstants::MIN_PLAYERS || $teamCount > GameConstants::MAX_PLAYERS) {
            $errors[] = 'teamCount must be between '.GameConstants::MIN_PLAYERS.' and '.GameConstants::MAX_PLAYERS.'.';

            return $errors;
        }

        $markers = $data['markers'] ?? null;
        if (! is_array($markers)) {
            $errors[] = 'markers must be an array.';

            return $errors;
        }

        $cellRows = (int) ($data['cellRows'] ?? 0);
        $cellCols = (int) ($data['cellCols'] ?? 0);
        $cells = $data['cells'] ?? null;
        if (! is_array($cells)) {
            $errors[] = 'cells must be an array for marker validation.';

            return $errors;
        }

        foreach (self::validateTeamPaletteSlotsInput($teamCount, $data['teamPaletteSlots'] ?? null) as $msg) {
            $errors[] = $msg;
        }
        if ($errors !== []) {
            return $errors;
        }

        /** @var list<int> $palette */
        $palette = self::normalizeTeamPaletteSlots($teamCount, $data['teamPaletteSlots'] ?? null);

        $occupied = [];
        $capitalsByTeam = [];
        $capitalCoords = [];
        /** @var list<array{index: int, row: int, col: int}> */
        $validFlags = [];
        /** @var list<int> */
        $flagCounts = array_fill(0, $teamCount, 0);

        foreach ($markers as $index => $marker) {
            if (! is_array($marker)) {
                $errors[] = "markers[{$index}] must be an object.";

                continue;
            }

            $type = $marker['type'] ?? null;
            if ($type !== self::TYPE_CAPITAL && $type !== self::TYPE_FLAG) {
                $errors[] = "markers[{$index}].type must be \"capital\" or \"flag\".";

                continue;
            }

            $team = $marker['team'] ?? null;
            if (! is_int($team) && ! (is_numeric($team) && (string) (int) $team === (string) $team)) {
                $errors[] = "markers[{$index}].team must be an integer.";

                continue;
            }
            $team = (int) $team;
            if ($team < 0 || $team >= GameConstants::MAX_PLAYERS) {
                $errors[] = "markers[{$index}].team must be between 0 and ".(GameConstants::MAX_PLAYERS - 1).'.';

                continue;
            }
            if ($team >= $teamCount) {
                $errors[] = "markers[{$index}].team must be less than teamCount ({$teamCount}).";

                continue;
            }

            $row = $marker['row'] ?? null;
            $col = $marker['col'] ?? null;
            if (! is_int($row) && ! (is_numeric($row) && (string) (int) $row === (string) $row)) {
                $errors[] = "markers[{$index}].row must be an integer.";

                continue;
            }
            if (! is_int($col) && ! (is_numeric($col) && (string) (int) $col === (string) $col)) {
                $errors[] = "markers[{$index}].col must be an integer.";

                continue;
            }
            $row = (int) $row;
            $col = (int) $col;

            if ($row < 0 || $row >= $cellRows || $col < 0 || $col >= $cellCols) {
                $errors[] = "markers[{$index}] is out of bounds for the terrain grid.";

                continue;
            }

            $key = "{$row},{$col}";
            if (isset($occupied[$key])) {
                $errors[] = 'Only one marker is allowed per cell.';

                continue;
            }
            $occupied[$key] = true;

            $terrain = $cells[$row][$col] ?? null;
            if (! is_string($terrain) || ! TerrainCatalog::isValid($terrain)) {
                $errors[] = "markers[{$index}] sits on invalid terrain.";

                continue;
            }
            if (! self::isPlaceableTerrain($terrain)) {
                $errors[] = "markers[{$index}] cannot be placed on {$terrain}.";

                continue;
            }
            if (($type === self::TYPE_CAPITAL || $type === self::TYPE_FLAG) && ! self::markerHasHydraulicWaterBuffer($cells, $cellRows, $cellCols, $row, $col)) {
                $errors[] = "markers[{$index}] is too close to water or a river.";

                continue;
            }

            if ($type === self::TYPE_CAPITAL) {
                if (isset($capitalsByTeam[$team])) {
                    $errors[] = self::factionLabelFromPalette($palette, $team).' has more than one capital.';

                    continue;
                }
                $capitalsByTeam[$team] = true;
                $capitalCoords[] = ['row' => $row, 'col' => $col];
            } elseif ($type === self::TYPE_FLAG) {
                $validFlags[] = ['index' => $index, 'row' => $row, 'col' => $col];
                $flagCounts[$team]++;
            }
        }

        $flagBudget = max(count($validFlags), $teamCount * 2, 1);
        $nLand = self::countPlaceableLandCells($cells, $cellRows, $cellCols);
        $preliminaryMaxR = self::preliminaryMaxRForMarkerSpacing($cellRows, $cellCols);
        $minHalo = self::minPlaceableHaloAmongCapitals($cells, $cellRows, $cellCols, $capitalCoords, $preliminaryMaxR);
        $capitalSpacing = self::inferCapitalSpacing($capitalCoords);
        $sep = self::computeMinManhattanMarkerSeparation(
            $cellRows,
            $cellCols,
            $nLand,
            $teamCount,
            $flagBudget,
            $capitalSpacing,
            $minHalo,
        );

        $nf = count($validFlags);
        for ($i = 0; $i < $nf; $i++) {
            $a = $validFlags[$i];
            foreach ($capitalCoords as $cap) {
                $d = abs($a['row'] - $cap['row']) + abs($a['col'] - $cap['col']);
                if ($d < $sep) {
                    $errors[] = "markers[{$a['index']}] is too close to a capital.";
                }
            }
            for ($j = $i + 1; $j < $nf; $j++) {
                $b = $validFlags[$j];
                $d = abs($a['row'] - $b['row']) + abs($a['col'] - $b['col']);
                if ($d < $sep) {
                    $errors[] = "markers[{$a['index']}] is too close to another flag.";
                }
            }
        }

        $minFlags = min($flagCounts);
        $maxFlags = max($flagCounts);
        if ($minFlags !== $maxFlags) {
            $parts = [];
            for ($t = 0; $t < $teamCount; $t++) {
                $parts[] = self::factionLabelFromPalette($palette, $t).': '.$flagCounts[$t];
            }
            $errors[] = 'Each team must have the same number of flags (current counts: '.implode(', ', $parts).').';
        }

        $missingCapitals = [];
        for ($t = 0; $t < $teamCount; $t++) {
            if (! isset($capitalsByTeam[$t])) {
                $missingCapitals[] = self::factionLabelFromPalette($palette, $t);
            }
        }
        if ($missingCapitals !== []) {
            $errors[] = 'Each team needs exactly one capital; missing capital for: '.implode(', ', $missingCapitals).'.';
        }

        if ($missingCapitals === [] && count($capitalCoords) === $teamCount) {
            $markerSites = [];
            foreach ($capitalCoords as $c) {
                $markerSites[] = $c;
            }
            foreach ($validFlags as $f) {
                $markerSites[] = ['row' => $f['row'], 'col' => $f['col']];
            }
            if (! self::allMarkerSitesMutuallyAccessible($cells, $cellRows, $cellCols, $markerSites)) {
                $errors[] = 'Capitals and flags must all lie in one connected region: you cannot seal a team behind an unbroken wall of mountains.';
            }
        }

        return $errors;
    }

    /**
     * Structural checks only for persisting map drafts (no spacing, water buffer, capital/flag
     * counts, or connectivity rules — those apply when placing markers in the editor or when
     * generating maps).
     *
     * @param  array<string, mixed>  $data  Full map data (version 2)
     * @return list<string> Human-readable errors (empty if valid)
     */
    public static function validatePersistable(array $data): array
    {
        $errors = [];

        $teamCount = $data['teamCount'] ?? null;
        if (! is_int($teamCount) && ! (is_numeric($teamCount) && (string) (int) $teamCount === (string) $teamCount)) {
            $errors[] = 'teamCount must be an integer.';

            return $errors;
        }
        $teamCount = (int) $teamCount;
        if ($teamCount < GameConstants::MIN_PLAYERS || $teamCount > GameConstants::MAX_PLAYERS) {
            $errors[] = 'teamCount must be between '.GameConstants::MIN_PLAYERS.' and '.GameConstants::MAX_PLAYERS.'.';

            return $errors;
        }

        $markers = $data['markers'] ?? null;
        if (! is_array($markers)) {
            $errors[] = 'markers must be an array.';

            return $errors;
        }

        $cellRows = (int) ($data['cellRows'] ?? 0);
        $cellCols = (int) ($data['cellCols'] ?? 0);
        $cells = $data['cells'] ?? null;
        if (! is_array($cells)) {
            $errors[] = 'cells must be an array for marker validation.';

            return $errors;
        }

        foreach (self::validateTeamPaletteSlotsInput($teamCount, $data['teamPaletteSlots'] ?? null) as $msg) {
            $errors[] = $msg;
        }
        if ($errors !== []) {
            return $errors;
        }

        $occupied = [];

        foreach ($markers as $index => $marker) {
            if (! is_array($marker)) {
                $errors[] = "markers[{$index}] must be an object.";

                continue;
            }

            $type = $marker['type'] ?? null;
            if ($type !== self::TYPE_CAPITAL && $type !== self::TYPE_FLAG) {
                $errors[] = "markers[{$index}].type must be \"capital\" or \"flag\".";

                continue;
            }

            $team = $marker['team'] ?? null;
            if (! is_int($team) && ! (is_numeric($team) && (string) (int) $team === (string) $team)) {
                $errors[] = "markers[{$index}].team must be an integer.";

                continue;
            }
            $team = (int) $team;
            if ($team < 0 || $team >= GameConstants::MAX_PLAYERS) {
                $errors[] = "markers[{$index}].team must be between 0 and ".(GameConstants::MAX_PLAYERS - 1).'.';

                continue;
            }
            if ($team >= $teamCount) {
                $errors[] = "markers[{$index}].team must be less than teamCount ({$teamCount}).";

                continue;
            }

            $row = $marker['row'] ?? null;
            $col = $marker['col'] ?? null;
            if (! is_int($row) && ! (is_numeric($row) && (string) (int) $row === (string) $row)) {
                $errors[] = "markers[{$index}].row must be an integer.";

                continue;
            }
            if (! is_int($col) && ! (is_numeric($col) && (string) (int) $col === (string) $col)) {
                $errors[] = "markers[{$index}].col must be an integer.";

                continue;
            }
            $row = (int) $row;
            $col = (int) $col;

            if ($row < 0 || $row >= $cellRows || $col < 0 || $col >= $cellCols) {
                $errors[] = "markers[{$index}] is out of bounds for the terrain grid.";

                continue;
            }

            $key = "{$row},{$col}";
            if (isset($occupied[$key])) {
                $errors[] = 'Only one marker is allowed per cell.';

                continue;
            }
            $occupied[$key] = true;

            $terrain = $cells[$row][$col] ?? null;
            if (! is_string($terrain) || ! TerrainCatalog::isValid($terrain)) {
                $errors[] = "markers[{$index}] sits on invalid terrain.";

                continue;
            }
        }

        return $errors;
    }

    /**
     * @param  list<array{row: int, col: int}>  $sites
     */
    private static function allMarkerSitesMutuallyAccessible(
        array $cells,
        int $rows,
        int $cols,
        array $sites,
    ): bool {
        if ($sites === []) {
            return true;
        }
        $start = $sites[0];
        $t0 = $cells[$start['row']][$start['col']] ?? null;
        if (! is_string($t0) || ! self::markerPassableForAccessibility($t0)) {
            return false;
        }
        $visited = [];
        $queue = [$start];
        $visited["{$start['row']},{$start['col']}"] = true;
        $dirs = [[1, 0], [-1, 0], [0, 1], [0, -1]];

        while ($queue !== []) {
            $c = array_shift($queue);
            foreach ($dirs as [$dx, $dy]) {
                $r = $c['row'] + $dx;
                $cc = $c['col'] + $dy;
                if ($r < 0 || $r >= $rows || $cc < 0 || $cc >= $cols) {
                    continue;
                }
                $terr = $cells[$r][$cc] ?? null;
                if (! is_string($terr) || ! self::markerPassableForAccessibility($terr)) {
                    continue;
                }
                $k = "{$r},{$cc}";
                if (isset($visited[$k])) {
                    continue;
                }
                $visited[$k] = true;
                $queue[] = ['row' => $r, 'col' => $cc];
            }
        }

        foreach ($sites as $s) {
            $k = "{$s['row']},{$s['col']}";
            if (! isset($visited[$k])) {
                return false;
            }
        }

        return true;
    }

    private static function markerPassableForAccessibility(string $terrain): bool
    {
        return $terrain !== 'mountain';
    }

    /**
     * @param  list<array{row: int, col: int}>  $coords
     */
    private static function inferCapitalSpacing(array $coords): int
    {
        $n = count($coords);
        if ($n < 2) {
            return 4;
        }
        $m = PHP_INT_MAX;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $d = abs($coords[$i]['row'] - $coords[$j]['row']) + abs($coords[$i]['col'] - $coords[$j]['col']);
                $m = min($m, $d);
            }
        }

        return max(1, $m === PHP_INT_MAX ? 4 : $m);
    }

    /**
     * @param  array<int, array<int, string>>  $cells
     */
    private static function countPlaceableLandCells(array $cells, int $rows, int $cols): int
    {
        $n = 0;
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $t = $cells[$r][$c] ?? null;
                if (is_string($t) && self::isPlaceableTerrain($t)) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /**
     * @param  array<int, array<int, string>>  $cells
     * @param  array{row: int, col: int}  $center
     */
    private static function countPlaceableLandInManhattanHalo(
        array $cells,
        int $rows,
        int $cols,
        array $center,
        int $maxR,
    ): int {
        $n = 0;
        $r0 = $center['row'];
        $c0 = $center['col'];
        $rMin = max(0, $r0 - $maxR);
        $rMax = min($rows - 1, $r0 + $maxR);
        $cMin = max(0, $c0 - $maxR);
        $cMax = min($cols - 1, $c0 + $maxR);

        for ($r = $rMin; $r <= $rMax; $r++) {
            for ($c = $cMin; $c <= $cMax; $c++) {
                if ($r === $r0 && $c === $c0) {
                    continue;
                }
                if (abs($r - $r0) + abs($c - $c0) > $maxR) {
                    continue;
                }
                $terr = $cells[$r][$c] ?? null;
                if (! is_string($terr) || ! self::isPlaceableTerrain($terr)) {
                    continue;
                }
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array<int, array<int, string>>  $cells
     * @param  list<array{row: int, col: int}>  $capitals
     */
    private static function minPlaceableHaloAmongCapitals(
        array $cells,
        int $rows,
        int $cols,
        array $capitals,
        int $preliminaryMaxR,
    ): int {
        if ($capitals === []) {
            return PHP_INT_MAX;
        }
        $minHalo = PHP_INT_MAX;
        foreach ($capitals as $cap) {
            $h = self::countPlaceableLandInManhattanHalo($cells, $rows, $cols, $cap, $preliminaryMaxR);
            $minHalo = min($minHalo, $h);
        }

        return $minHalo;
    }

    private static function preliminaryMaxRForMarkerSpacing(int $rows, int $cols): int
    {
        $minDim = min($rows, $cols);

        return min($rows + $cols - 2, max(18, (int) floor($minDim * 0.52)));
    }

    private static function computeMinManhattanMarkerSeparation(
        int $rows,
        int $cols,
        int $nLand,
        int $teamCount,
        int $flagBudget,
        int $capitalSpacing,
        int $minHaloLandCells,
    ): int {
        $minDim = min($rows, $cols);
        $dCap = max(1, $capitalSpacing);
        $minHalo = $minHaloLandCells;
        if ($minHalo < 6) {
            $minHalo = max(12, (int) floor($nLand / max(8, $teamCount * 4)));
        }
        $flagsEach = max(1, $flagBudget / $teamCount);
        $densitySpacing = (int) round(sqrt(max(1, $minHalo) / ($flagsEach * 0.55)));
        $fromCapitalSep = (int) floor($dCap * 0.52) + 2;
        $maxGap = $minDim > 140 ? 36 : 28;

        return max(self::MIN_MARKER_MANHATTAN_SEP, min($maxGap, max($densitySpacing, $fromCapitalSep)));
    }
}
