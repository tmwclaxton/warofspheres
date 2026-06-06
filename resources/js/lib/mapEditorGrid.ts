/** Live battlefield vertex grid (App\Games\GameConstants rows+1 / cols+1). */
export const LIVE_BATTLEFIELD_CELL_ROWS = 65;

export const LIVE_BATTLEFIELD_CELL_COLS = 36;

/**
 * Default new-map vertex grid in the builder: 3× the live battlefield (matches MapEditorGrid::CELL_*).
 */
export const DEFAULT_MAP_CELL_ROWS = LIVE_BATTLEFIELD_CELL_ROWS * 3;

export const DEFAULT_MAP_CELL_COLS = LIVE_BATTLEFIELD_CELL_COLS * 3;

/** @deprecated Use DEFAULT_MAP_CELL_ROWS */
export const MAP_CELL_ROWS = DEFAULT_MAP_CELL_ROWS;

/** @deprecated Use DEFAULT_MAP_CELL_COLS */
export const MAP_CELL_COLS = DEFAULT_MAP_CELL_COLS;

export const MAP_GRID_MIN_CELL_ROWS = 4;

export const MAP_GRID_MAX_CELL_ROWS = 256;

export const MAP_GRID_MIN_CELL_COLS = 4;

export const MAP_GRID_MAX_CELL_COLS = 256;

/** Editor canvas cell size in CSS px (3× the in-game cell size of 20px for a larger editing view). */
export const MAP_EDITOR_CELL_PX = 60;

export function isAllowedMapGridSize(cellRows: number, cellCols: number): boolean {
    return (
        Number.isInteger(cellRows)
        && Number.isInteger(cellCols)
        && cellRows >= MAP_GRID_MIN_CELL_ROWS
        && cellRows <= MAP_GRID_MAX_CELL_ROWS
        && cellCols >= MAP_GRID_MIN_CELL_COLS
        && cellCols <= MAP_GRID_MAX_CELL_COLS
    );
}

/** True when declared dimensions match rectangular `cells` and `bridges`. */
export function validateMapGridData(data: {
    cellRows: number;
    cellCols: number;
    cells: string[][];
    bridges: boolean[][];
}): boolean {
    if (!isAllowedMapGridSize(data.cellRows, data.cellCols)) {
        return false;
    }
    if (data.cells.length !== data.cellRows || data.bridges.length !== data.cellRows) {
        return false;
    }
    for (let r = 0; r < data.cellRows; r++) {
        const rowC = data.cells[r];
        const rowB = data.bridges[r];
        if (!Array.isArray(rowC) || !Array.isArray(rowB)) {
            return false;
        }
        if (rowC.length !== data.cellCols || rowB.length !== data.cellCols) {
            return false;
        }
    }

    return true;
}

export type EmptyMapPayload = {
    version: number;
    cellRows: number;
    cellCols: number;
    cells: string[][];
    bridges: boolean[][];
};

/**
 * Empty plains map with the given vertex dimensions (must pass {@link isAllowedMapGridSize}).
 */
export function emptyMapPayload(cellRows: number, cellCols: number): EmptyMapPayload {
    if (!isAllowedMapGridSize(cellRows, cellCols)) {
        throw new RangeError(
            `Map size ${cellRows}×${cellCols} is not allowed (${MAP_GRID_MIN_CELL_ROWS}–${MAP_GRID_MAX_CELL_ROWS} rows, ${MAP_GRID_MIN_CELL_COLS}–${MAP_GRID_MAX_CELL_COLS} cols).`,
        );
    }
    const cells: string[][] = [];
    const bridges: boolean[][] = [];
    for (let x = 0; x < cellRows; x++) {
        cells[x] = Array.from({ length: cellCols }, () => 'plains');
        bridges[x] = Array.from({ length: cellCols }, () => false);
    }

    return {
        version: 1,
        cellRows,
        cellCols,
        cells,
        bridges,
    };
}
