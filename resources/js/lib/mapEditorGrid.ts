import { computeMinSeparationForMapState, manhattanDistance } from '@/lib/mapMarkerSpacing';
import { isFarEnoughFromHydraulicWaterForMapMarker, isPlaceableTerrain } from '@/lib/mapMarkers';
import { isTerrainId } from '@/lib/terrainCatalog';

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

/** Matches App\Games\GameConstants::MIN_PLAYERS / MAX_PLAYERS for map team count. */
export const MAP_MIN_TEAMS = 2;

export const MAP_MAX_TEAMS = 6;

const FACTION_LABELS = ['red', 'blue', 'orange', 'purple', 'green', 'cyan'] as const;

const ORTHO_DIRS: ReadonlyArray<readonly [number, number]> = [
    [1, 0],
    [-1, 0],
    [0, 1],
    [0, -1],
];

/**
 * Matches random-map passability: units cannot occupy mountains but may traverse other terrains
 * (including water) orthogonally.
 */
function isPassableTerrainForMapAccessibility(terrain: string): boolean {
    return terrain !== 'mountain';
}

/**
 * BFS from the first site: every marker cell must be reachable using orthogonal moves without
 * crossing mountain cells.
 */
function allMarkerSitesMutuallyAccessible(
    cells: string[][],
    rows: number,
    cols: number,
    sites: ReadonlyArray<{ row: number; col: number }>,
): boolean {
    if (sites.length === 0) {
        return true;
    }

    const start = sites[0]!;
    const terrainStart = cells[start.row]?.[start.col];

    if (typeof terrainStart !== 'string' || !isPassableTerrainForMapAccessibility(terrainStart)) {
        return false;
    }

    const visited = new Set<string>();
    const queue: { row: number; col: number }[] = [{ row: start.row, col: start.col }];
    visited.add(`${start.row},${start.col}`);

    while (queue.length > 0) {
        const c = queue.shift()!;

        for (const [dx, dy] of ORTHO_DIRS) {
            const r = c.row + dx;
            const col = c.col + dy;

            if (r < 0 || r >= rows || col < 0 || col >= cols) {
                continue;
            }

            const t = cells[r]?.[col];

            if (typeof t !== 'string' || !isPassableTerrainForMapAccessibility(t)) {
                continue;
            }

            const k = `${r},${col}`;

            if (visited.has(k)) {
                continue;
            }

            visited.add(k);
            queue.push({ row: r, col });
        }
    }

    for (const s of sites) {
        if (!visited.has(`${s.row},${s.col}`)) {
            return false;
        }
    }

    return true;
}

/** Editor canvas cell size in CSS px (3× the in-game cell size of 20px for a larger editing view). */
export const MAP_EDITOR_CELL_PX = 60;

export type MapMarker = {
    type: 'capital' | 'flag';
    team: number;
    row: number;
    col: number;
};

export type MapDataPayload = {
    version: number;
    cellRows: number;
    cellCols: number;
    cells: string[][];
    teamCount?: number;
    markers?: MapMarker[];
    /**
     * One entry per logical team index `0 .. teamCount - 1`: which palette slot (faction colour)
     * that team uses. Survives team removal so e.g. “blue” markers stay blue after “red” is deleted.
     */
    teamPaletteSlots?: number[];
};

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

/** True when declared dimensions match rectangular `cells`. */
export function validateMapGridData(data: {
    cellRows: number;
    cellCols: number;
    cells: string[][];
}): boolean {
    if (!isAllowedMapGridSize(data.cellRows, data.cellCols)) {
        return false;
    }

    if (data.cells.length !== data.cellRows) {
        return false;
    }

    for (let r = 0; r < data.cellRows; r++) {
        const rowC = data.cells[r];

        if (!Array.isArray(rowC)) {
            return false;
        }

        if (rowC.length !== data.cellCols) {
            return false;
        }
    }

    return true;
}

function cloneMarker(m: MapMarker): MapMarker {
    return { type: m.type, team: m.team, row: m.row, col: m.col };
}

/** Identity mapping: logical team `i` uses palette slot `i`. */
export function defaultTeamPaletteSlots(teamCount: number): number[] {
    return Array.from({ length: teamCount }, (_, i) => i);
}

/**
 * Normalizes {@link MapDataPayload.teamPaletteSlots}: length === teamCount, unique ints in
 * `[0, MAP_MAX_TEAMS - 1]`. Falls back to identity when missing or invalid.
 */
export function normalizeTeamPaletteSlots(teamCount: number, raw: unknown): number[] {
    const identity = (): number[] => defaultTeamPaletteSlots(teamCount);

    if (!Number.isInteger(teamCount) || teamCount < MAP_MIN_TEAMS || teamCount > MAP_MAX_TEAMS) {
        return defaultTeamPaletteSlots(MAP_MIN_TEAMS);
    }

    if (!Array.isArray(raw) || raw.length !== teamCount) {
        return identity();
    }

    const out: number[] = [];

    for (let i = 0; i < teamCount; i++) {
        const v = Math.trunc(Number(raw[i]));

        if (!Number.isInteger(v) || v < 0 || v >= MAP_MAX_TEAMS) {
            return identity();
        }

        out.push(v);
    }

    if (new Set(out).size !== out.length) {
        return identity();
    }

    return out;
}

/**
 * Upgrade v1 terrain-only payloads for the editor (v2 in memory).
 */
export function normalizeMapPayload(raw: MapDataPayload): MapDataPayload {
    const v = raw.version ?? 1;

    if (v === 1) {
        return {
            version: 2,
            cellRows: raw.cellRows,
            cellCols: raw.cellCols,
            cells: raw.cells,
            teamCount: MAP_MIN_TEAMS,
            markers: [],
            teamPaletteSlots: defaultTeamPaletteSlots(MAP_MIN_TEAMS),
        };
    }

    const teamCount =
        typeof raw.teamCount === 'number' && Number.isInteger(raw.teamCount)
            ? raw.teamCount
            : MAP_MIN_TEAMS;

    return {
        version: 2,
        cellRows: raw.cellRows,
        cellCols: raw.cellCols,
        cells: raw.cells,
        teamCount,
        markers: Array.isArray(raw.markers) ? raw.markers.map(cloneMarker) : [],
        teamPaletteSlots: normalizeTeamPaletteSlots(teamCount, raw.teamPaletteSlots),
    };
}

export type EmptyMapPayload = MapDataPayload;

/**
 * Empty plains map (matches MapEditorGrid::emptyData()). Capitals are placed by the user.
 */
export function emptyMapPayload(cellRows: number, cellCols: number): EmptyMapPayload {
    if (!isAllowedMapGridSize(cellRows, cellCols)) {
        throw new RangeError(
            `Map size ${cellRows}×${cellCols} is not allowed (${MAP_GRID_MIN_CELL_ROWS}–${MAP_GRID_MAX_CELL_ROWS} rows, ${MAP_GRID_MIN_CELL_COLS}–${MAP_GRID_MAX_CELL_COLS} cols).`,
        );
    }

    const cells: string[][] = [];

    for (let x = 0; x < cellRows; x++) {
        cells[x] = Array.from({ length: cellCols }, () => 'plains');
    }

    return {
        version: 2,
        cellRows,
        cellCols,
        cells,
        teamCount: MAP_MIN_TEAMS,
        markers: [],
        teamPaletteSlots: defaultTeamPaletteSlots(MAP_MIN_TEAMS),
    };
}

/**
 * When `teamPaletteSlots` is present it must be valid. Omitted keys are accepted for legacy data.
 */
export function validateTeamPaletteSlotsArray(teamCount: number, raw: unknown): string[] {
    const errors: string[] = [];

    if (raw === undefined || raw === null) {
        return errors;
    }

    if (!Array.isArray(raw)) {
        errors.push('teamPaletteSlots must be an array.');

        return errors;
    }

    if (raw.length !== teamCount) {
        errors.push(`teamPaletteSlots must have length ${teamCount} (same as teamCount).`);

        return errors;
    }

    const out: number[] = [];

    for (let i = 0; i < teamCount; i++) {
        const v = Math.trunc(Number(raw[i]));

        if (!Number.isInteger(v) || v < 0 || v >= MAP_MAX_TEAMS) {
            errors.push(
                `teamPaletteSlots[${i}] must be an integer between 0 and ${MAP_MAX_TEAMS - 1}.`,
            );

            return errors;
        }

        out.push(v);
    }

    if (new Set(out).size !== out.length) {
        errors.push('teamPaletteSlots must use each palette colour at most once (no duplicates).');

        return errors;
    }

    return errors;
}

/**
 * Client-side marker validation (mirrors App\Maps\MapMarkers::validate for editor placement;
 * save uses MapMarkers::validatePersistable only).
 */
export function validateMapMarkers(data: MapDataPayload): string[] {
    const errors: string[] = [];
    const teamCount = data.teamCount ?? MAP_MIN_TEAMS;

    if (!Number.isInteger(teamCount) || teamCount < MAP_MIN_TEAMS || teamCount > MAP_MAX_TEAMS) {
        errors.push(`teamCount must be between ${MAP_MIN_TEAMS} and ${MAP_MAX_TEAMS}.`);

        return errors;
    }

    errors.push(...validateTeamPaletteSlotsArray(teamCount, data.teamPaletteSlots));

    if (errors.length > 0) {
        return errors;
    }

    const teamPaletteSlots = normalizeTeamPaletteSlots(teamCount, data.teamPaletteSlots);
    const labelForTeam = (t: number): string =>
        FACTION_LABELS[teamPaletteSlots[t] ?? t] ?? `team ${t}`;

    const markers = data.markers ?? [];

    if (!Array.isArray(markers)) {
        errors.push('markers must be an array.');

        return errors;
    }

    const cellRows = data.cellRows;
    const cellCols = data.cellCols;
    const cells = data.cells;
    const occupied = new Set<string>();
    const capitalsByTeam = new Map<number, true>();
    const capitalPositions: { row: number; col: number }[] = [];
    const validFlagPositions: { index: number; row: number; col: number }[] = [];
    const flagCounts = new Array(teamCount).fill(0);

    for (let index = 0; index < markers.length; index++) {
        const marker = markers[index];

        if (!marker || typeof marker !== 'object') {
            errors.push(`markers[${index}] must be an object.`);

            continue;
        }

        const { type, team, row, col } = marker;

        if (type !== 'capital' && type !== 'flag') {
            errors.push(`markers[${index}].type must be "capital" or "flag".`);

            continue;
        }

        if (!Number.isInteger(team) || team < 0 || team >= MAP_MAX_TEAMS) {
            errors.push(`markers[${index}].team must be between 0 and ${MAP_MAX_TEAMS - 1}.`);

            continue;
        }

        if (team >= teamCount) {
            errors.push(`markers[${index}].team must be less than teamCount (${teamCount}).`);

            continue;
        }

        if (!Number.isInteger(row) || !Number.isInteger(col)) {
            errors.push(`markers[${index}].row and col must be integers.`);

            continue;
        }

        if (row < 0 || row >= cellRows || col < 0 || col >= cellCols) {
            errors.push(`markers[${index}] is out of bounds for the terrain grid.`);

            continue;
        }

        const key = `${row},${col}`;

        if (occupied.has(key)) {
            errors.push('Only one marker is allowed per cell.');

            continue;
        }

        occupied.add(key);

        const terrain = cells[row]?.[col];

        if (typeof terrain !== 'string' || !isTerrainId(terrain)) {
            errors.push(`markers[${index}] sits on invalid terrain.`);

            continue;
        }

        if (!isPlaceableTerrain(terrain)) {
            errors.push(`markers[${index}] cannot be placed on ${terrain}.`);

            continue;
        }

        if (
            (type === 'capital' || type === 'flag') &&
            !isFarEnoughFromHydraulicWaterForMapMarker(cells, cellRows, cellCols, row, col)
        ) {
            errors.push(`markers[${index}] is too close to water or a river.`);

            continue;
        }

        if (type === 'capital') {
            if (capitalsByTeam.has(team)) {
                errors.push(`${labelForTeam(team)} has more than one capital.`);

                continue;
            }

            capitalsByTeam.set(team, true);
            capitalPositions.push({ row, col });
        } else if (type === 'flag') {
            validFlagPositions.push({ index, row, col });
            flagCounts[team] += 1;
        }
    }

    const flagBudget = Math.max(validFlagPositions.length, teamCount * 2, 1);
    const sep = computeMinSeparationForMapState({
        cells,
        rows: cellRows,
        cols: cellCols,
        teamCount,
        capitalPositions,
        flagBudget,
    });

    for (let i = 0; i < validFlagPositions.length; i++) {
        const a = validFlagPositions[i]!;

        for (const cap of capitalPositions) {
            if (manhattanDistance({ row: a.row, col: a.col }, cap) < sep) {
                errors.push(`markers[${a.index}] is too close to a capital.`);
            }
        }

        for (let j = i + 1; j < validFlagPositions.length; j++) {
            const b = validFlagPositions[j]!;

            if (manhattanDistance({ row: a.row, col: a.col }, { row: b.row, col: b.col }) < sep) {
                errors.push(`markers[${a.index}] is too close to another flag.`);
            }
        }
    }

    const minFlags = Math.min(...flagCounts);
    const maxFlags = Math.max(...flagCounts);

    if (minFlags !== maxFlags) {
        const parts = flagCounts.map((n, t) => `${labelForTeam(t)}: ${n}`).join(', ');
        errors.push(`Each team must have the same number of flags (current counts: ${parts}).`);
    }

    const missing: string[] = [];

    for (let t = 0; t < teamCount; t++) {
        if (!capitalsByTeam.has(t)) {
            missing.push(labelForTeam(t));
        }
    }

    if (missing.length > 0) {
        errors.push(`Each team needs exactly one capital; missing capital for: ${missing.join(', ')}.`);
    }

    if (missing.length === 0 && capitalPositions.length === teamCount) {
        const sites = [
            ...capitalPositions,
            ...validFlagPositions.map((f) => ({ row: f.row, col: f.col })),
        ];

        if (!allMarkerSitesMutuallyAccessible(cells, cellRows, cellCols, sites)) {
            errors.push(
                'Capitals and flags must all lie in one connected region: you cannot seal a team behind an unbroken wall of mountains.',
            );
        }
    }

    return errors;
}
