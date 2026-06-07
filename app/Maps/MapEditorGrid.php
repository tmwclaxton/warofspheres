<?php

namespace App\Maps;

use App\Games\GameConstants;

/**
 * Editor cell grid matches marching-squares vertex grid (see MarchingSquares::emptyGrid).
 */
final class MapEditorGrid
{
    /** Live battlefield vertex grid (GameConstants rows+1 / cols+1). */
    public const int LIVE_BATTLEFIELD_CELL_ROWS = GameConstants::ROWS + 1;

    public const int LIVE_BATTLEFIELD_CELL_COLS = GameConstants::COLS + 1;

    /**
     * Default new-map vertex grid in the builder: 3× the live battlefield so the canvas starts larger.
     */
    public const int CELL_ROWS = self::LIVE_BATTLEFIELD_CELL_ROWS * 3;

    public const int CELL_COLS = self::LIVE_BATTLEFIELD_CELL_COLS * 3;

    public const int MIN_CELL_ROWS = 4;

    public const int MAX_CELL_ROWS = 256;

    public const int MIN_CELL_COLS = 4;

    public const int MAX_CELL_COLS = 256;

    public static function dimensionsAreAllowed(int $cellRows, int $cellCols): bool
    {
        return $cellRows >= self::MIN_CELL_ROWS
            && $cellRows <= self::MAX_CELL_ROWS
            && $cellCols >= self::MIN_CELL_COLS
            && $cellCols <= self::MAX_CELL_COLS;
    }

    /**
     * Empty v2 plains grid with {@see GameConstants::MIN_PLAYERS} teams and no markers (user places capitals).
     *
     * @return array{version: int, cellRows: int, cellCols: int, cells: list<list<string>>, teamCount: int, markers: list<array{type: string, team: int, row: int, col: int}>, teamPaletteSlots: list<int>} marker type: capital|flag|infantry|tank
     */
    public static function emptyData(?int $cellRows = null, ?int $cellCols = null): array
    {
        $rows = $cellRows ?? self::CELL_ROWS;
        $cols = $cellCols ?? self::CELL_COLS;
        if (! self::dimensionsAreAllowed($rows, $cols)) {
            throw new \InvalidArgumentException("Map dimensions {$rows}×{$cols} are not allowed.");
        }

        $cells = [];

        for ($r = 0; $r < $rows; $r++) {
            $cells[$r] = array_fill(0, $cols, 'plains');
        }

        $minTeams = GameConstants::MIN_PLAYERS;

        return [
            'version' => 2,
            'cellRows' => $rows,
            'cellCols' => $cols,
            'cells' => $cells,
            'teamCount' => $minTeams,
            'markers' => [],
            'teamPaletteSlots' => range(0, $minTeams - 1),
        ];
    }
}
