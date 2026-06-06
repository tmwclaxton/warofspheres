import {
    DEFAULT_MAP_CELL_COLS,
    DEFAULT_MAP_CELL_ROWS,
    isAllowedMapGridSize,
} from '@/lib/mapEditorGrid';
import type { TerrainId } from '@/lib/terrainCatalog';
import { WATER_TERRAINS } from '@/lib/terrainCatalog';

/** Matches MapDataPayload from the editor / API. */
export type GeneratedMapData = {
    version: number;
    cellRows: number;
    cellCols: number;
    cells: string[][];
    bridges: boolean[][];
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

function edgeFalloff(gx: number, gy: number, rows: number, cols: number): number {
    const nx = (gx + 0.5) / rows - 0.5;
    const ny = (gy + 0.5) / cols - 0.5;
    const d = Math.sqrt(nx * nx + ny * ny) * 1.35;

    return Math.min(1, d);
}

type GridCell = [number, number];

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
export function generateRandomMap(
    seed?: number,
    cellRows: number = DEFAULT_MAP_CELL_ROWS,
    cellCols: number = DEFAULT_MAP_CELL_COLS,
): GeneratedMapData {
    if (!isAllowedMapGridSize(cellRows, cellCols)) {
        throw new RangeError(`Invalid map size ${cellRows}×${cellCols} for generation.`);
    }
    const s = seed === undefined || !Number.isFinite(seed) ? defaultSeed() : (seed >>> 0);
    const rng = mulberry32(s ^ 0x9e3779b9);

    const rows = cellRows;
    const cols = cellCols;
    const cells: string[][] = [];
    const bridges: boolean[][] = [];
    const elev: number[][] = [];

    /** Lower = slower noise variation across cells = larger biome patches. */
    const scale = 0.048;
    /** Moisture field uses a different effective scale so it stays decorrelated from elevation. */
    const moistureScaleFactor = 1.65;
    const elevationOctaves = 3;
    const moistureOctaves = 3;
    const ox = rng() * 2000 + 100;
    const oy = rng() * 2000 + 100;
    const ox2 = rng() * 2000 + 300;
    const oy2 = rng() * 2000 + 400;

    for (let x = 0; x < rows; x++) {
        cells[x] = [];
        bridges[x] = [];
        elev[x] = [];
        for (let y = 0; y < cols; y++) {
            const wx = (x + 0.5) * scale + ox;
            const wy = (y + 0.5) * scale + oy;
            const wx2 = (x + 0.5) * scale * moistureScaleFactor + ox2;
            const wy2 = (y + 0.5) * scale * moistureScaleFactor + oy2;

            let elevation = fbm2D(wx, wy, 11, elevationOctaves);
            let moisture = fbm2D(wx2, wy2, 503, moistureOctaves);

            const edge = edgeFalloff(x, y, rows, cols);
            elevation -= edge * 0.14;
            moisture += edge * 0.1;
            elevation = Math.min(1, Math.max(0, elevation));
            moisture = Math.min(1, Math.max(0, moisture));

            elev[x][y] = elevation;
            cells[x][y] = classify(elevation, moisture);
            bridges[x][y] = false;
        }
    }

    carveDescendingRivers(elev, cells, rows, cols, rng);

    smoothGrid(cells as TerrainId[][], rows, cols, 1);

    return {
        version: 1,
        cellRows: rows,
        cellCols: cols,
        cells,
        bridges,
    };
}
