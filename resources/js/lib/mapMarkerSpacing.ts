import { isPlaceableTerrain } from '@/lib/mapMarkers';

export type MarkerSpacingCell = { row: number; col: number };

/** Absolute floor for flag–flag and flag–capital Manhattan clearance (matches generator). */
export const MAP_MARKER_MIN_MANHATTAN_SEP = 6;

/**
 * Caps flag–capital / flag–flag spacing on very small land budgets so procedural maps can still
 * place outposts (troop generator depends on flags for targets and anchor bands).
 */
export function clampMinBetweenFlagsForSmallLand(
    minBetweenFlags: number,
    nLand: number,
    minDim: number,
): number {
    if (nLand >= 120) {
        return minBetweenFlags;
    }

    if (nLand < 70) {
        return Math.max(MAP_MARKER_MIN_MANHATTAN_SEP, Math.min(minBetweenFlags, 6));
    }

    const relaxedCap = Math.max(4, Math.min(10, Math.floor(minDim / 4)));

    return Math.max(MAP_MARKER_MIN_MANHATTAN_SEP, Math.min(minBetweenFlags, relaxedCap));
}

/**
 * Manhattan clearance required from a troop spawn to a capital or flag. Enemy markers use the
 * full map `separation`; same-team markers use a smaller ring so armies can sit on home ground
 * near their posts (matches server {@see App\Maps\MapMarkers::validate}).
 */
export function troopManhattanClearanceToMarker(
    separation: number,
    markerTeam: number,
    troopTeam: number,
    markerKind: 'capital' | 'flag',
): number {
    if (markerTeam !== troopTeam) {
        return separation;
    }

    const cap = markerKind === 'capital' ? 10 : 12;
    const ratio = markerKind === 'capital' ? 0.42 : 0.45;

    return Math.max(
        MAP_MARKER_MIN_MANHATTAN_SEP,
        Math.min(cap, Math.floor(separation * ratio)),
    );
}

export function manhattanDistance(a: MarkerSpacingCell, b: MarkerSpacingCell): number {
    return Math.abs(a.row - b.row) + Math.abs(a.col - b.col);
}

/**
 * Minimum pairwise Manhattan distance between capitals (for density heuristic).
 * When there is only one capital, returns a neutral default.
 */
export function inferCapitalSpacing(capitals: ReadonlyArray<MarkerSpacingCell>): number {
    if (capitals.length < 2) {
        return 4;
    }

    let m = Infinity;

    for (let i = 0; i < capitals.length; i++) {
        for (let j = i + 1; j < capitals.length; j++) {
            m = Math.min(m, manhattanDistance(capitals[i]!, capitals[j]!));
        }
    }

    return Math.max(1, m === Infinity ? 4 : m);
}

/** Count placeable land cells within Manhattan radius `maxR` of `center` (excludes center). */
export function countPlaceableLandInManhattanHalo(
    cells: string[][],
    rows: number,
    cols: number,
    center: MarkerSpacingCell,
    maxR: number,
): number {
    let n = 0;
    const r0 = center.row;
    const c0 = center.col;
    const rMin = Math.max(0, r0 - maxR);
    const rMax = Math.min(rows - 1, r0 + maxR);
    const cMin = Math.max(0, c0 - maxR);
    const cMax = Math.min(cols - 1, c0 + maxR);

    for (let r = rMin; r <= rMax; r++) {
        for (let c = cMin; c <= cMax; c++) {
            if (r === r0 && c === c0) {
                continue;
            }

            if (manhattanDistance(center, { row: r, col: c }) > maxR) {
                continue;
            }

            const terr = cells[r]?.[c];

            if (typeof terr !== 'string' || !isPlaceableTerrain(terr)) {
                continue;
            }

            n += 1;
        }
    }

    return n;
}

export function preliminaryMaxRForMarkerSpacing(rows: number, cols: number): number {
    const minDim = Math.min(rows, cols);

    return Math.min(rows + cols - 2, Math.max(18, Math.floor(minDim * 0.52)));
}

export function minPlaceableHaloAmongCapitals(
    cells: string[][],
    rows: number,
    cols: number,
    capitals: ReadonlyArray<MarkerSpacingCell>,
    preliminaryMaxR: number,
): number {
    if (capitals.length === 0) {
        return Infinity;
    }

    let minHalo = Infinity;

    for (const cap of capitals) {
        const h = countPlaceableLandInManhattanHalo(cells, rows, cols, cap, preliminaryMaxR);
        minHalo = Math.min(minHalo, h);
    }

    return minHalo;
}

export type MarkerSeparationInputs = {
    rows: number;
    cols: number;
    nLand: number;
    teamCount: number;
    flagBudget: number;
    capitalSpacing: number;
    minHaloLandCells: number;
};

/**
 * Minimum Manhattan gap between flags, and between any flag and any capital (capitals act like
 * spacing anchors the same as flags).
 */
export function computeMinManhattanMarkerSeparation(input: MarkerSeparationInputs): number {
    const { rows, cols, nLand, teamCount, flagBudget, capitalSpacing, minHaloLandCells } = input;
    const minDim = Math.min(rows, cols);
    const dCap = Math.max(1, capitalSpacing);

    let minHalo = minHaloLandCells;

    if (!Number.isFinite(minHalo) || minHalo < 6) {
        minHalo = Math.max(12, Math.floor(nLand / Math.max(8, teamCount * 4)));
    }

    const flagsEach = Math.max(1, flagBudget / teamCount);
    const densitySpacing = Math.round(Math.sqrt(Math.max(1, minHalo) / (flagsEach * 0.55)));
    const fromCapitalSep = Math.floor(dCap * 0.52) + 2;
    const maxGap = minDim > 140 ? 36 : 28;

    return Math.max(
        MAP_MARKER_MIN_MANHATTAN_SEP,
        Math.min(maxGap, Math.max(densitySpacing, fromCapitalSep)),
    );
}

export function countPlaceableLandCells(cells: string[][], rows: number, cols: number): number {
    let nLand = 0;

    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            const t = cells[r]?.[c];

            if (typeof t === 'string' && isPlaceableTerrain(t)) {
                nLand += 1;
            }
        }
    }

    return nLand;
}

/**
 * Shared spacing used by random generation, client validation, and editor placement.
 */
export function computeMinSeparationForMapState(options: {
    cells: string[][];
    rows: number;
    cols: number;
    teamCount: number;
    capitalPositions: ReadonlyArray<MarkerSpacingCell>;
    flagBudget: number;
}): number {
    const { cells, rows, cols, teamCount, capitalPositions, flagBudget } = options;
    const nLand = countPlaceableLandCells(cells, rows, cols);
    const preliminaryMaxR = preliminaryMaxRForMarkerSpacing(rows, cols);
    const minHalo = minPlaceableHaloAmongCapitals(cells, rows, cols, capitalPositions, preliminaryMaxR);
    const capitalSpacing = inferCapitalSpacing(capitalPositions);

    return computeMinManhattanMarkerSeparation({
        rows,
        cols,
        nLand,
        teamCount,
        flagBudget,
        capitalSpacing,
        minHaloLandCells: minHalo,
    });
}
