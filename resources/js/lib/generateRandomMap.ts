import { buildMarkersForGeneratedTerrain } from '@/lib/generateMapMarkers';
import { buildTroopMarkersForGeneratedMap } from '@/lib/generateTroopSpawns';
import {
    DEFAULT_MAP_CELL_COLS,
    DEFAULT_MAP_CELL_ROWS,
    defaultTeamPaletteSlots,
    isAllowedMapGridSize,
} from '@/lib/mapEditorGrid';
import type { MapMarker } from '@/lib/mapEditorGrid';
import type { TerrainId } from '@/lib/terrainCatalog';
import { WATER_TERRAINS } from '@/lib/terrainCatalog';

/** Terrain + optional v2 markers when produced by {@link generateRandomMap}. */
export type GeneratedMapData = {
    version: 1 | 2;
    cellRows: number;
    cellCols: number;
    cells: string[][];
    teamCount?: number;
    markers?: MapMarker[];
    teamPaletteSlots?: number[];
};

export type MapGenerationType = 'mix' | 'islands' | 'desert' | 'mountains';

export const MAP_GENERATION_TYPE_OPTIONS: ReadonlyArray<{
    id: MapGenerationType;
    label: string;
    description: string;
}> = [
    {
        id: 'mix',
        label: 'Mixed',
        description: 'Balanced continents, forests, deserts, and peaks.',
    },
    {
        id: 'islands',
        label: 'Islands',
        description:
            'Two to four large islands in open ocean, with shallow coastal water, beaches, and deep sea beyond.',
    },
    {
        id: 'desert',
        label: 'Desert',
        description: 'Vast dunes with lush rings around scattered oases.',
    },
    {
        id: 'mountains',
        label: 'Mountains',
        description: 'Rugged highlands with valleys linked by mountain passes.',
    },
];

export type MapGenerationOptions = {
    seed?: number;
    type?: MapGenerationType;
    cellRows?: number;
    cellCols?: number;
    /** When set, generator aims for this many teams (clamped to map rules). */
    teamCount?: number;
};

type GenerationProfile = {
    elevationBias: number;
    moistureBias: number;
    edgeFalloffStrength: number;
    noiseScale: number;
    islandMask: boolean;
    carveRivers: boolean;
    aridBiome: boolean;
    mountainBiome: boolean;
};

const GENERATION_PROFILES: Record<MapGenerationType, GenerationProfile> = {
    mix: {
        elevationBias: 0,
        moistureBias: 0,
        edgeFalloffStrength: 0.14,
        noiseScale: 0.048,
        islandMask: false,
        carveRivers: true,
        aridBiome: false,
        mountainBiome: false,
    },
    islands: {
        elevationBias: 0,
        moistureBias: 0.04,
        edgeFalloffStrength: 0,
        noiseScale: 0.052,
        islandMask: true,
        carveRivers: true,
        aridBiome: false,
        mountainBiome: false,
    },
    desert: {
        elevationBias: 0.1,
        moistureBias: -0.36,
        edgeFalloffStrength: 0.1,
        noiseScale: 0.05,
        islandMask: false,
        carveRivers: false,
        aridBiome: true,
        mountainBiome: false,
    },
    mountains: {
        elevationBias: 0.2,
        moistureBias: -0.06,
        edgeFalloffStrength: 0.08,
        noiseScale: 0.044,
        islandMask: false,
        carveRivers: true,
        aridBiome: false,
        mountainBiome: true,
    },
};

function mulberry32(seed: number): () => number {
    let a = seed >>> 0;

    return (): number => {
        a += 0x6d2b79f5;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t ^= t + Math.imul(t ^ (t >>> 7), 61 | t);

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function hash01(ix: number, iy: number, salt: number): number {
    let n = Math.imul(ix, 1597334677) ^ Math.imul(iy, 3812015801) ^ Math.imul(salt, 1944066667);
    n = Math.imul(n ^ (n >>> 16), 2246822507);
    n = Math.imul(n ^ (n >>> 13), 3266489917);

    return ((n ^ (n >>> 16)) >>> 0) / 4294967296;
}

function smoothstep(t: number): number {
    const x = Math.min(1, Math.max(0, t));

    return x * x * (3 - 2 * x);
}

function valueNoise2D(x: number, y: number, salt: number): number {
    const ix = Math.floor(x);
    const iy = Math.floor(y);
    const fx = x - ix;
    const fy = y - iy;
    const u = smoothstep(fx);
    const v = smoothstep(fy);
    const v00 = hash01(ix, iy, salt);
    const v10 = hash01(ix + 1, iy, salt);
    const v01 = hash01(ix, iy + 1, salt);
    const v11 = hash01(ix + 1, iy + 1, salt);
    const a = v00 + (v10 - v00) * u;
    const b = v01 + (v11 - v01) * u;

    return a + (b - a) * v;
}

function fbm2D(x: number, y: number, salt: number, octaves: number): number {
    let sum = 0;
    let amp = 1;
    let freq = 1;
    let norm = 0;
    const lacunarity = 2.05;
    const gain = 0.52;

    for (let o = 0; o < octaves; o++) {
        sum += valueNoise2D(x * freq, y * freq, salt + o * 97) * amp;
        norm += amp;
        amp *= gain;
        freq *= lacunarity;
    }

    return sum / norm;
}

function defaultSeed(): number {
    const t = Date.now() & 0xffffffff;
    const r = (Math.random() * 0xffffffff) >>> 0;

    return (t ^ r) >>> 0;
}

function classify(elevation: number, moisture: number): TerrainId {
    const e = elevation;
    const m = moisture;

    if (e < 0.14) {
        return 'deep_water';
    }

    if (e < 0.24) {
        return 'water';
    }

    if (e < 0.3) {
        if (m > 0.55) {
            return 'swamp';
        }

        return 'beach';
    }

    if (e < 0.38) {
        if (m > 0.58) {
            return 'swamp';
        }

        if (m < 0.32) {
            return 'beach';
        }

        return 'meadow';
    }

    if (e < 0.55) {
        if (m < 0.28) {
            return 'desert';
        }

        if (m > 0.62) {
            return 'forest';
        }

        return 'plains';
    }

    if (e < 0.68) {
        if (m > 0.72) {
            return 'dense_forest';
        }

        return 'forest';
    }

    if (e < 0.82) {
        return 'hill';
    }

    return 'mountain';
}

type GridCell = [number, number];

const PASSABLE_DIRS: GridCell[] = [
    [1, 0],
    [-1, 0],
    [0, 1],
    [0, -1],
];

function mapCellKey(x: number, y: number): string {
    return `${x},${y}`;
}

function parseMapCellKey(key: string): GridCell {
    const parts = key.split(',');
    const x = Number(parts[0]);
    const y = Number(parts[1]);

    return [x, y];
}

function isPassableLand(terrain: string): boolean {
    return terrain !== 'mountain';
}

function collectPassableComponents(
    cells: string[][],
    rows: number,
    cols: number,
): GridCell[][] {
    const components: GridCell[][] = [];
    const visited = new Set<string>();

    for (let x = 0; x < rows; x++) {
        for (let y = 0; y < cols; y++) {
            if (!isPassableLand(cells[x][y])) {
                continue;
            }

            const start = mapCellKey(x, y);

            if (visited.has(start)) {
                continue;
            }

            const patch: GridCell[] = [];
            const queue: GridCell[] = [[x, y]];
            visited.add(start);

            while (queue.length > 0) {
                const [cx, cy] = queue.shift()!;
                patch.push([cx, cy]);

                for (const [dx, dy] of PASSABLE_DIRS) {
                    const nx = cx + dx;
                    const ny = cy + dy;

                    if (nx < 0 || nx >= rows || ny < 0 || ny >= cols) {
                        continue;
                    }

                    if (!isPassableLand(cells[nx][ny])) {
                        continue;
                    }

                    const key = mapCellKey(nx, ny);

                    if (visited.has(key)) {
                        continue;
                    }

                    visited.add(key);
                    queue.push([nx, ny]);
                }
            }

            components.push(patch);
        }
    }

    return components;
}

function bresenhamLineKeys(x0: number, y0: number, x1: number, y1: number): string[] {
    const keys: string[] = [];
    let x = x0;
    let y = y0;
    const dx = Math.abs(x1 - x0);
    const dy = Math.abs(y1 - y0);
    const sx = x0 < x1 ? 1 : -1;
    const sy = y0 < y1 ? 1 : -1;
    let err = dx - dy;

    while (true) {
        keys.push(mapCellKey(x, y));

        if (x === x1 && y === y1) {
            break;
        }

        const e2 = 2 * err;

        if (e2 > -dy) {
            err -= dy;
            x += sx;
        }

        if (e2 < dx) {
            err += dx;
            y += sy;
        }
    }

    return keys;
}

function carvePassCells(
    cells: string[][],
    elev: number[][],
    rows: number,
    cols: number,
    keys: string[],
): void {
    const toCarve = new Set<string>();

    for (const key of keys) {
        const [x, y] = parseMapCellKey(key);

        if (x < 0 || x >= rows || y < 0 || y >= cols) {
            continue;
        }

        if (cells[x][y] === 'mountain') {
            toCarve.add(key);
        }

        for (const [dx, dy] of PASSABLE_DIRS) {
            const nx = x + dx;
            const ny = y + dy;

            if (nx < 0 || nx >= rows || ny < 0 || ny >= cols) {
                continue;
            }

            if (cells[nx][ny] === 'mountain') {
                toCarve.add(mapCellKey(nx, ny));
            }
        }
    }

    for (const key of toCarve) {
        const [x, y] = parseMapCellKey(key);
        cells[x][y] = 'hill';
        elev[x][y] = Math.min(elev[x][y] ?? 0.75, 0.78);
    }
}

function connectComponentToMain(
    cells: string[][],
    elev: number[][],
    rows: number,
    cols: number,
    orphan: GridCell[],
    main: GridCell[],
): void {
    const mainSet = new Set(main.map(([x, y]) => mapCellKey(x, y)));
    const orphanSet = new Set(orphan.map(([x, y]) => mapCellKey(x, y)));
    const parent = new Map<string, GridCell | null>();
    const queue: GridCell[] = [];

    for (const [x, y] of orphan) {
        parent.set(mapCellKey(x, y), null);
        queue.push([x, y]);
    }

    const visited = new Set(parent.keys());
    let endKey: string | null = null;

    while (queue.length > 0 && endKey === null) {
        const [x, y] = queue.shift()!;

        for (const [dx, dy] of PASSABLE_DIRS) {
            const nx = x + dx;
            const ny = y + dy;

            if (nx < 0 || nx >= rows || ny < 0 || ny >= cols) {
                continue;
            }

            const nk = mapCellKey(nx, ny);

            if (visited.has(nk)) {
                continue;
            }

            const terrain = cells[nx][ny];

            if (terrain === 'mountain') {
                visited.add(nk);
                parent.set(nk, [x, y]);
                queue.push([nx, ny]);
                continue;
            }

            if (!isPassableLand(terrain)) {
                continue;
            }

            if (mainSet.has(nk)) {
                endKey = nk;
                parent.set(nk, [x, y]);
                break;
            }

            if (!orphanSet.has(nk)) {
                visited.add(nk);
                parent.set(nk, [x, y]);
                queue.push([nx, ny]);
            }
        }
    }

    if (endKey !== null) {
        const path: string[] = [];
        let cur: string | null = endKey;

        while (cur !== null) {
            const [x, y] = parseMapCellKey(cur);

            if (cells[x][y] === 'mountain') {
                path.push(cur);
            }

            const prev = parent.get(cur) ?? null;
            cur = prev === null ? null : mapCellKey(prev[0], prev[1]);
        }

        carvePassCells(cells, elev, rows, cols, path);

        return;
    }

    let sx = 0;
    let sy = 0;

    for (const [x, y] of orphan) {
        sx += x;
        sy += y;
    }

    sx = Math.round(sx / orphan.length);
    sy = Math.round(sy / orphan.length);
    let mx = 0;
    let my = 0;

    for (const [x, y] of main) {
        mx += x;
        my += y;
    }

    mx = Math.round(mx / main.length);
    my = Math.round(my / main.length);

    carvePassCells(cells, elev, rows, cols, bresenhamLineKeys(sx, sy, mx, my));
}

/** Carve passes through mountain walls so all non-mountain land stays reachable. */
function ensureMountainAccessibility(
    cells: string[][],
    elev: number[][],
    rows: number,
    cols: number,
): void {
    const minOrphanCells = 4;

    for (let attempt = 0; attempt < 48; attempt++) {
        const components = collectPassableComponents(cells, rows, cols);

        if (components.length <= 1) {
            return;
        }

        components.sort((a, b) => b.length - a.length);
        const main = components[0]!;
        let connected = false;

        for (let i = 1; i < components.length; i++) {
            const orphan = components[i]!;

            if (orphan.length < minOrphanCells) {
                continue;
            }

            connectComponentToMain(cells, elev, rows, cols, orphan, main);
            connected = true;
            break;
        }

        if (!connected) {
            for (let i = 1; i < components.length; i++) {
                connectComponentToMain(cells, elev, rows, cols, components[i]!, main);
                break;
            }
        }
    }
}

/** Moisture hotspots for oases — soft falloff so greenery can ring each pool. */
function oasisStrength(gx: number, gy: number, scale: number, ox: number, oy: number): number {
    const wx = (gx + 0.5) * scale * 2.8 + ox;
    const wy = (gy + 0.5) * scale * 2.8 + oy;
    const raw = fbm2D(wx, wy, 1201, 3);

    return smoothstep((raw - 0.64) / 0.2);
}

function classifyDesert(elevation: number, moisture: number, oasis: number): TerrainId {
    if (elevation < 0.14) {
        return 'deep_water';
    }

    if (elevation < 0.24) {
        return 'water';
    }

    if (elevation < 0.3) {
        if (oasis > 0.35) {
            return 'meadow';
        }

        return 'beach';
    }

    if (elevation >= 0.8) {
        return 'mountain';
    }

    if (elevation >= 0.66) {
        return 'hill';
    }

    if (oasis > 0.72) {
        return moisture > 0.38 ? 'forest' : 'meadow';
    }

    if (oasis > 0.48) {
        return 'meadow';
    }

    if (oasis > 0.28) {
        return 'plains';
    }

    if (oasis > 0.14) {
        return 'meadow';
    }

    return 'desert';
}

/** Paint plains and meadow in desert rings around existing oasis cores. */
function spreadOasisGreenery(
    cells: string[][],
    rows: number,
    cols: number,
    scale: number,
    oasisOx: number,
    oasisOy: number,
): void {
    const next = cells.map((row) => [...row]);

    for (let x = 0; x < rows; x++) {
        for (let y = 0; y < cols; y++) {
            if (cells[x][y] !== 'desert') {
                continue;
            }

            let localOasis = oasisStrength(x, y, scale, oasisOx, oasisOy);
            let touchesGreen = false;

            for (const [dx, dy] of PASSABLE_DIRS) {
                const nx = x + dx;
                const ny = y + dy;

                if (nx < 0 || nx >= rows || ny < 0 || ny >= cols) {
                    continue;
                }

                localOasis = Math.max(localOasis, oasisStrength(nx, ny, scale, oasisOx, oasisOy));

                if (DESERT_GREENERY.has(cells[nx][ny] as TerrainId)) {
                    touchesGreen = true;
                }
            }

            if (localOasis > 0.38) {
                next[x][y] = localOasis > 0.55 ? 'meadow' : 'plains';
            } else if (touchesGreen && localOasis > 0.1) {
                next[x][y] = 'plains';
            }
        }
    }

    for (let x = 0; x < rows; x++) {
        for (let y = 0; y < cols; y++) {
            if (next[x][y] !== 'desert') {
                cells[x][y] = next[x][y];
            }
        }
    }
}

const DESERT_GREENERY: ReadonlySet<TerrainId> = new Set([
    'forest',
    'dense_forest',
    'meadow',
    'plains',
    'swamp',
]);

/** Collapse large green patches so arid maps keep only small oasis groves. */
function capDesertGreenery(
    cells: string[][],
    rows: number,
    cols: number,
    maxPatchCells: number,
): void {
    const seen = new Set<string>();

    for (let x = 0; x < rows; x++) {
        for (let y = 0; y < cols; y++) {
            const terrain = cells[x][y] as TerrainId;

            if (!DESERT_GREENERY.has(terrain)) {
                continue;
            }

            const start = `${x},${y}`;

            if (seen.has(start)) {
                continue;
            }

            const patch: string[] = [];
            const queue = [start];
            seen.add(start);

            while (queue.length > 0) {
                const key = queue.pop()!;
                patch.push(key);
                const [cx, cy] = key.split(',').map(Number);

                for (const [dx, dy] of [
                    [1, 0],
                    [-1, 0],
                    [0, 1],
                    [0, -1],
                ] as GridCell[]) {
                    const nx = cx + dx;
                    const ny = cy + dy;

                    if (nx < 0 || nx >= rows || ny < 0 || ny >= cols) {
                        continue;
                    }

                    const next = `${nx},${ny}`;

                    if (seen.has(next)) {
                        continue;
                    }

                    if (!DESERT_GREENERY.has(cells[nx][ny] as TerrainId)) {
                        continue;
                    }

                    seen.add(next);
                    queue.push(next);
                }
            }

            if (patch.length > maxPatchCells) {
                for (const key of patch) {
                    const [cx, cy] = key.split(',').map(Number);
                    cells[cx][cy] = 'desert';
                }
            }
        }
    }
}

function edgeFalloff(gx: number, gy: number, rows: number, cols: number): number {
    const nx = (gx + 0.5) / rows - 0.5;
    const ny = (gy + 0.5) / cols - 0.5;
    const d = Math.sqrt(nx * nx + ny * ny) * 1.35;

    return Math.min(1, d);
}

type ArchipelagoSeed = {
    cx: number;
    cy: number;
    radius: number;
};

/** Place 2–4 well-separated island centers with large radii. */
function buildArchipelagoSeeds(rows: number, cols: number, rng: () => number): ArchipelagoSeed[] {
    const targetCount = 2 + Math.floor(rng() * 3);
    const minDim = Math.min(rows, cols);
    const margin = minDim * 0.18;
    const seeds: ArchipelagoSeed[] = [];

    for (let attempt = 0; attempt < 360 && seeds.length < targetCount; attempt++) {
        const radius = minDim * (0.16 + rng() * 0.07);
        const cx = margin + rng() * (rows - 2 * margin);
        const cy = margin + rng() * (cols - 2 * margin);
        const strait = Math.max(minDim * 0.03, minDim * 0.08 - Math.floor(attempt / 100) * 0.01);

        let spaced = true;

        for (const existing of seeds) {
            const dx = cx - existing.cx;
            const dy = cy - existing.cy;
            const dist = Math.sqrt(dx * dx + dy * dy);
            const minGap = radius + existing.radius + strait;

            if (dist < minGap) {
                spaced = false;
                break;
            }
        }

        if (spaced) {
            seeds.push({ cx, cy, radius });
        }
    }

    const fallbackSlots: GridCell[] = [
        [0.3, 0.35],
        [0.7, 0.62],
        [0.34, 0.72],
        [0.68, 0.28],
    ];

    for (const [nx, ny] of fallbackSlots) {
        if (seeds.length >= targetCount) {
            break;
        }

        const radius = minDim * 0.19;
        const cx = nx * rows;
        const cy = ny * cols;
        let spaced = true;

        for (const existing of seeds) {
            const dx = cx - existing.cx;
            const dy = cy - existing.cy;
            const dist = Math.sqrt(dx * dx + dy * dy);

            if (dist < radius + existing.radius + minDim * 0.04) {
                spaced = false;
                break;
            }
        }

        if (spaced) {
            seeds.push({ cx, cy, radius });
        }
    }

    const forcedPairs: GridCell[] = [
        [0.27, 0.34],
        [0.73, 0.66],
        [0.28, 0.72],
        [0.72, 0.3],
    ];

    for (const [nx, ny] of forcedPairs) {
        if (seeds.length >= Math.max(2, targetCount)) {
            break;
        }

        const radius = minDim * 0.19;
        const cx = nx * rows;
        const cy = ny * cols;
        let spaced = true;

        for (const existing of seeds) {
            const dx = cx - existing.cx;
            const dy = cy - existing.cy;
            const dist = Math.sqrt(dx * dx + dy * dy);

            if (dist < radius + existing.radius + minDim * 0.03) {
                spaced = false;
                break;
            }
        }

        if (spaced) {
            seeds.push({ cx, cy, radius });
        }
    }

    return seeds.slice(0, targetCount);
}

/**
 * After noise + smoothing: open ocean stays deep_water, but cells touching land become
 * water, with one extra ring of water before deep ocean. Shore land tiles can become beach
 * (forests/swamps only sometimes, for variety).
 */
function applyArchipelagoCoastalLayers(
    cells: string[][],
    rows: number,
    cols: number,
    rng: () => number,
): void {
    const hasLandNeighbor4 = (x: number, y: number): boolean => {
        for (const [dx, dy] of PASSABLE_DIRS) {
            const nx = x + dx;
            const ny = y + dy;

            if (nx < 0 || nx >= rows || ny < 0 || ny >= cols) {
                continue;
            }

            const t = cells[nx][ny];

            if (!isOpenOceanTerrain(t)) {
                return true;
            }
        }

        return false;
    };

    const hasOpenOceanNeighbor4 = (x: number, y: number): boolean => {
        for (const [dx, dy] of PASSABLE_DIRS) {
            const nx = x + dx;
            const ny = y + dy;

            if (nx < 0 || nx >= rows || ny < 0 || ny >= cols) {
                continue;
            }

            if (isOpenOceanTerrain(cells[nx][ny])) {
                return true;
            }
        }

        return false;
    };

    for (let x = 0; x < rows; x++) {
        for (let y = 0; y < cols; y++) {
            const t = cells[x][y];

            if (!isOpenOceanTerrain(t)) {
                continue;
            }

            if (hasLandNeighbor4(x, y)) {
                cells[x][y] = 'water';
            }
        }
    }

    for (let pass = 0; pass < 2; pass++) {
        const snap = cells.map((row) => [...row]);

        for (let x = 0; x < rows; x++) {
            for (let y = 0; y < cols; y++) {
                if (snap[x][y] !== 'deep_water') {
                    continue;
                }

                let touchesNormalWater = false;

                for (const [dx, dy] of PASSABLE_DIRS) {
                    const nx = x + dx;
                    const ny = y + dy;

                    if (nx < 0 || nx >= rows || ny < 0 || ny >= cols) {
                        continue;
                    }

                    if (snap[nx][ny] === 'water') {
                        touchesNormalWater = true;

                        break;
                    }
                }

                if (touchesNormalWater) {
                    cells[x][y] = 'water';
                }
            }
        }
    }

    for (let x = 0; x < rows; x++) {
        for (let y = 0; y < cols; y++) {
            const t = cells[x][y];

            if (isOpenOceanTerrain(t) || t === 'river') {
                continue;
            }

            if (t === 'mountain' || t === 'hill') {
                continue;
            }

            if (!hasOpenOceanNeighbor4(x, y)) {
                continue;
            }

            const preferBeach =
                t === 'plains'
                || t === 'meadow'
                || t === 'desert'
                || t === 'beach'
                || ((t === 'forest' || t === 'dense_forest' || t === 'swamp') && rng() < 0.42);

            if (preferBeach) {
                cells[x][y] = 'beach';
            }
        }
    }
}

/** Land mask from a few large seeded islands with irregular, non-circular coastlines. */
function islandLandFactor(
    gx: number,
    gy: number,
    rows: number,
    cols: number,
    seeds: ArchipelagoSeed[],
    ox: number,
    oy: number,
    scale: number,
): number {
    let nearest: ArchipelagoSeed | null = null;
    let nearestDist = Infinity;
    let nearestDx = 0;
    let nearestDy = 0;

    for (const seed of seeds) {
        const dx = gx - seed.cx;
        const dy = gy - seed.cy;
        const dist = Math.sqrt(dx * dx + dy * dy);

        if (dist < nearestDist) {
            nearestDist = dist;
            nearest = seed;
            nearestDx = dx;
            nearestDy = dy;
        }
    }

    if (nearest === null) {
        return 0;
    }

    const seedSalt = nearest.cx * 0.13 + nearest.cy * 0.19;
    const wx = (gx + 0.5) * scale * 1.25 + ox + seedSalt;
    const wy = (gy + 0.5) * scale * 1.25 + oy - seedSalt * 0.7;

    const warpX = (fbm2D(wx, wy, 901, 3) - 0.5) * 20;
    const warpY = (fbm2D(wx + 113, wy - 67, 902, 3) - 0.5) * 20;
    const warpedDist = Math.hypot(nearestDx + warpX, nearestDy + warpY);

    const angle = Math.atan2(nearestDy, nearestDx);
    const awx = Math.cos(angle) * 3.4 + seedSalt * 0.04;
    const awy = Math.sin(angle) * 3.4 - seedSalt * 0.03;
    const angularMod = (fbm2D(awx, awy, 601, 3) - 0.5) * 0.46;

    const coastNoise = (fbm2D(wx * 2.3, wy * 2.3, 701, 4) - 0.5) * 0.3;
    const inletNoise = (fbm2D(wx * 4.2 + 29, wy * 4.2 - 11, 811, 2) - 0.5) * 0.18;

    const effectiveRadius = nearest.radius * (1 + angularMod + coastNoise + inletNoise);
    const t = 1 - warpedDist / effectiveRadius;
    const land = smoothstep(t / 0.11);

    return Math.min(1, Math.max(0, land));
}

/** Drop stray single-cell land specks left by noisy coastlines. */
function pruneTinyLandIsles(cells: string[][], rows: number, cols: number, minSize: number): void {
    const land = new Set<string>();

    for (let x = 0; x < rows; x++) {
        for (let y = 0; y < cols; y++) {
            const t = cells[x][y];

            if (t !== 'water' && t !== 'deep_water') {
                land.add(`${x},${y}`);
            }
        }
    }

    const seen = new Set<string>();

    for (const start of land) {
        if (seen.has(start)) {
            continue;
        }

        const component: string[] = [];
        const queue = [start];
        seen.add(start);

        while (queue.length > 0) {
            const key = queue.pop()!;
            component.push(key);
            const [x, y] = key.split(',').map(Number);

            for (const [dx, dy] of [
                [1, 0],
                [-1, 0],
                [0, 1],
                [0, -1],
            ] as GridCell[]) {
                const next = `${x + dx},${y + dy}`;

                if (land.has(next) && !seen.has(next)) {
                    seen.add(next);
                    queue.push(next);
                }
            }
        }

        if (component.length < minSize) {
            for (const key of component) {
                const [x, y] = key.split(',').map(Number);
                cells[x][y] = 'deep_water';
            }
        }
    }
}

const DIR8: GridCell[] = [
    [1, 0],
    [-1, 0],
    [0, 1],
    [0, -1],
    [1, 1],
    [1, -1],
    [-1, 1],
    [-1, -1],
];

function shufflePairs(pairs: GridCell[], rng: () => number): void {
    for (let i = pairs.length - 1; i > 0; i--) {
        const j = Math.floor(rng() * (i + 1));
        const t = pairs[i]!;
        pairs[i] = pairs[j]!;
        pairs[j] = t;
    }
}

function isOpenOceanTerrain(terrain: string): boolean {
    return terrain === 'water' || terrain === 'deep_water';
}

/** Paints `river` on land; skips open ocean and existing water/river tiles. */
function paintRiverCell(cells: string[][], rows: number, cols: number, cx: number, cy: number): void {
    if (cx < 0 || cx >= rows || cy < 0 || cy >= cols) {
        return;
    }

    const here = cells[cx][cy];

    if (isOpenOceanTerrain(here)) {
        return;
    }

    if (!WATER_TERRAINS.has(here as TerrainId)) {
        cells[cx][cy] = 'river';
    }
}

/** Widen the river path (Chebyshev disk around each center cell on the path). */
function stampThickRiver(
    cells: string[][],
    rows: number,
    cols: number,
    cx: number,
    cy: number,
    chebyshevRadius: number,
): void {
    for (let dx = -chebyshevRadius; dx <= chebyshevRadius; dx++) {
        for (let dy = -chebyshevRadius; dy <= chebyshevRadius; dy++) {
            if (Math.max(Math.abs(dx), Math.abs(dy)) > chebyshevRadius) {
                continue;
            }

            paintRiverCell(cells, rows, cols, cx + dx, cy + dy);
        }
    }
}

/**
 * Carve downhill river paths from high ground toward sea, using the same elevation field as noise gen.
 */
function carveDescendingRivers(
    elev: number[][],
    cells: string[][],
    rows: number,
    cols: number,
    rng: () => number,
): void {
    const minDim = Math.min(rows, cols);
    const riverCount = Math.max(2, Math.min(14, Math.floor((rows * cols) / 280)));
    const minSourceElev = 0.76;
    const maxSteps = rows + cols + 40;
    const minSpacing = Math.max(4, Math.floor(minDim / 9));
    /** 1 ≈ 3 cells wide; 2 ≈ 5 cells wide (Chebyshev stamp along the path). */
    const riverChebyshevRadius = 1;

    const candidates: GridCell[] = [];

    for (let x = 0; x < rows; x++) {
        for (let y = 0; y < cols; y++) {
            if (elev[x][y] >= minSourceElev && !WATER_TERRAINS.has(cells[x][y] as TerrainId)) {
                candidates.push([x, y]);
            }
        }
    }

    if (candidates.length === 0) {
        return;
    }

    shufflePairs(candidates, rng);

    const sources: GridCell[] = [];

    for (const [sx, sy] of candidates) {
        if (sources.length >= riverCount) {
            break;
        }

        let spaced = true;

        for (const [ox, oy] of sources) {
            if (Math.abs(sx - ox) + Math.abs(sy - oy) < minSpacing) {
                spaced = false;
                break;
            }
        }

        if (spaced) {
            sources.push([sx, sy]);
        }
    }

    for (const start of sources) {
        let [x, y] = start;
        const seen = new Set<string>();

        for (let step = 0; step < maxSteps; step++) {
            if (isOpenOceanTerrain(cells[x][y])) {
                break;
            }

            const key = `${x},${y}`;

            if (seen.has(key)) {
                break;
            }

            seen.add(key);

            stampThickRiver(cells, rows, cols, x, y, riverChebyshevRadius);

            let nextX = -1;
            let nextY = -1;
            let nextEl = Infinity;
            const curEl = elev[x][y];
            let towardOcean = false;

            for (const [dx, dy] of DIR8) {
                const nx = x + dx;
                const ny = y + dy;

                if (nx < 0 || nx >= rows || ny < 0 || ny >= cols) {
                    continue;
                }

                if (isOpenOceanTerrain(cells[nx][ny])) {
                    towardOcean = true;
                    nextX = nx;
                    nextY = ny;
                    nextEl = -1;
                    break;
                }

                const el = elev[nx][ny];

                if (el < curEl && el < nextEl) {
                    nextEl = el;
                    nextX = nx;
                    nextY = ny;
                }
            }

            if (towardOcean) {
                break;
            }

            if (nextX < 0) {
                let bx = -1;
                let by = -1;
                let be = Infinity;

                for (const [dx, dy] of DIR8) {
                    const nx = x + dx;
                    const ny = y + dy;

                    if (nx < 0 || nx >= rows || ny < 0 || ny >= cols) {
                        continue;
                    }

                    const el = elev[nx][ny];

                    if (el < be) {
                        be = el;
                        bx = nx;
                        by = ny;
                    }
                }

                if (bx < 0) {
                    break;
                }

                x = bx;
                y = by;
            } else {
                x = nextX;
                y = nextY;
            }
        }
    }
}

function smoothGrid(grid: TerrainId[][], rows: number, cols: number, passes: number): void {
    const counts: Record<string, number> = {};
    const order: TerrainId[] = [
        'mountain',
        'hill',
        'dense_forest',
        'forest',
        'desert',
        'swamp',
        'meadow',
        'plains',
        'beach',
        'river',
        'water',
        'deep_water',
    ];

    for (let p = 0; p < passes; p++) {
        const next = grid.map((row) => [...row]);

        for (let x = 0; x < rows; x++) {
            for (let y = 0; y < cols; y++) {
                const cur = grid[x][y];

                if (WATER_TERRAINS.has(cur)) {
                    next[x][y] = cur;

                    continue;
                }

                if (cur === 'mountain') {
                    let mn = 0;
                    let total = 0;

                    for (let dx = -1; dx <= 1; dx++) {
                        for (let dy = -1; dy <= 1; dy++) {
                            const nx = x + dx;
                            const ny = y + dy;

                            if (nx < 0 || nx >= rows || ny < 0 || ny >= cols) {
                                continue;
                            }

                            total++;

                            if (grid[nx][ny] === 'mountain') {
                                mn++;
                            }
                        }
                    }

                    if (mn < 3 && total >= 6) {
                        next[x][y] = 'hill';
                    } else {
                        next[x][y] = cur;
                    }

                    continue;
                }

                for (const k of order) {
                    counts[k] = 0;
                }

                for (let dx = -1; dx <= 1; dx++) {
                    for (let dy = -1; dy <= 1; dy++) {
                        const nx = x + dx;
                        const ny = y + dy;

                        if (nx < 0 || nx >= rows || ny < 0 || ny >= cols) {
                            continue;
                        }

                        const t = grid[nx][ny];
                        counts[t] = (counts[t] ?? 0) + 1;
                    }
                }

                let best: TerrainId = cur;
                let bestC = 0;

                for (const k of order) {
                    const c = counts[k] ?? 0;

                    if (c > bestC) {
                        bestC = c;
                        best = k;
                    }
                }

                if (bestC >= 6 && !WATER_TERRAINS.has(best)) {
                    next[x][y] = best;
                } else {
                    next[x][y] = cur;
                }
            }
        }

        for (let x = 0; x < rows; x++) {
            for (let y = 0; y < cols; y++) {
                grid[x][y] = next[x][y];
            }
        }
    }
}

/**
 * Procedural terrain for the map editor. Deterministic when `seed` is provided.
 */
export function generateRandomMap(options: MapGenerationOptions = {}): GeneratedMapData {
    const cellRows = options.cellRows ?? DEFAULT_MAP_CELL_ROWS;
    const cellCols = options.cellCols ?? DEFAULT_MAP_CELL_COLS;
    const type = options.type ?? 'mix';
    const profile = GENERATION_PROFILES[type];

    if (!isAllowedMapGridSize(cellRows, cellCols)) {
        throw new RangeError(`Invalid map size ${cellRows}×${cellCols} for generation.`);
    }

    const s =
        options.seed === undefined || !Number.isFinite(options.seed)
            ? defaultSeed()
            : (options.seed >>> 0);
    const rng = mulberry32(s ^ 0x9e3779b9);

    const rows = cellRows;
    const cols = cellCols;
    const cells: string[][] = [];
    const elev: number[][] = [];

    /** Lower = slower noise variation across cells = larger biome patches. */
    const scale = profile.noiseScale;
    /** Moisture field uses a different effective scale so it stays decorrelated from elevation. */
    const moistureScaleFactor = 1.65;
    const elevationOctaves = 3;
    const moistureOctaves = 3;
    const ox = rng() * 2000 + 100;
    const oy = rng() * 2000 + 100;
    const ox2 = rng() * 2000 + 300;
    const oy2 = rng() * 2000 + 400;
    const islandOx = rng() * 2000 + 900;
    const islandOy = rng() * 2000 + 1100;
    const oasisOx = profile.aridBiome ? rng() * 2000 + 500 : 0;
    const oasisOy = profile.aridBiome ? rng() * 2000 + 700 : 0;
    const archipelagoSeeds = profile.islandMask ? buildArchipelagoSeeds(rows, cols, rng) : [];

    for (let x = 0; x < rows; x++) {
        cells[x] = [];
        elev[x] = [];

        for (let y = 0; y < cols; y++) {
            const wx = (x + 0.5) * scale + ox;
            const wy = (y + 0.5) * scale + oy;
            const wx2 = (x + 0.5) * scale * moistureScaleFactor + ox2;
            const wy2 = (y + 0.5) * scale * moistureScaleFactor + oy2;

            let elevation = fbm2D(wx, wy, 11, elevationOctaves);
            let moisture = fbm2D(wx2, wy2, 503, moistureOctaves);

            const edge = edgeFalloff(x, y, rows, cols);
            elevation -= edge * profile.edgeFalloffStrength;
            moisture += edge * 0.1;
            elevation += profile.elevationBias;
            moisture += profile.moistureBias;

            if (profile.islandMask) {
                const land = islandLandFactor(
                    x,
                    y,
                    rows,
                    cols,
                    archipelagoSeeds,
                    islandOx,
                    islandOy,
                    scale,
                );
                elevation = land * (0.28 + elevation * 0.68);
                moisture = (0.38 + moisture * 0.42) * (0.55 + land * 0.45);
            }

            elevation = Math.min(1, Math.max(0, elevation));
            moisture = Math.min(1, Math.max(0, moisture));

            elev[x][y] = elevation;

            if (profile.aridBiome) {
                const oasis = oasisStrength(x, y, scale, oasisOx, oasisOy);
                cells[x][y] = classifyDesert(elevation, moisture, oasis);
            } else {
                cells[x][y] = classify(elevation, moisture);
            }
        }
    }

    if (profile.islandMask) {
        const minDim = Math.min(rows, cols);

        if (minDim >= 100) {
            pruneTinyLandIsles(
                cells,
                rows,
                cols,
                Math.max(60, Math.floor(minDim * 0.12)),
            );
        }
    }

    if (profile.carveRivers) {
        carveDescendingRivers(elev, cells, rows, cols, rng);
    }

    if (profile.aridBiome) {
        spreadOasisGreenery(cells, rows, cols, scale, oasisOx, oasisOy);
        capDesertGreenery(
            cells,
            rows,
            cols,
            Math.max(140, Math.floor(Math.min(rows, cols) * 0.55)),
        );
    } else {
        smoothGrid(cells as TerrainId[][], rows, cols, 1);
    }

    if (profile.islandMask) {
        applyArchipelagoCoastalLayers(cells, rows, cols, rng);
    }

    if (profile.mountainBiome) {
        ensureMountainAccessibility(cells, elev, rows, cols);
    }

    const minDim = Math.min(rows, cols);
    const markerRng = mulberry32((s ^ 0xca501abe) >>> 0);
    const requestedTeamCount =
        options.teamCount !== undefined && Number.isFinite(options.teamCount)
            ? Math.round(options.teamCount)
            : undefined;
    const { teamCount, markers } = buildMarkersForGeneratedTerrain(
        cells,
        rows,
        cols,
        markerRng,
        requestedTeamCount,
    );
    const troopSpawns = buildTroopMarkersForGeneratedMap(cells, rows, cols, markers, teamCount, markerRng, {
        islandLike: profile.islandMask && minDim >= 96,
    });

    if (
        troopSpawns.length === 0
        && teamCount >= 2
        && profile.islandMask
        && minDim < 120
        && type === 'islands'
    ) {
        return generateRandomMap({
            ...options,
            cellRows,
            cellCols,
            type: 'mix',
            seed: (s ^ 0x0badf00d) >>> 0,
        });
    }

    const allMarkers = [...markers, ...troopSpawns];

    return {
        version: 2,
        cellRows: rows,
        cellCols: cols,
        cells,
        teamCount,
        markers: allMarkers,
        teamPaletteSlots: defaultTeamPaletteSlots(teamCount),
    };
}
