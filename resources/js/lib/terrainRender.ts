import {
    EDITOR_TERRAIN_COLORS,
    TERRAIN_IDS,
    
    isTerrainId
} from '@/lib/terrainCatalog';
import type {TerrainId} from '@/lib/terrainCatalog';

function hexToRgbTuple(hex: string): readonly [number, number, number] {
    const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex.trim());

    if (!m) {
        return [200, 214, 138];
    }

    return [Number.parseInt(m[1], 16), Number.parseInt(m[2], 16), Number.parseInt(m[3], 16)] as const;
}

/** Editor terrain RGB - built once from {@link EDITOR_TERRAIN_COLORS} for fast canvas fills. */
export const EDITOR_TERRAIN_RGB: Record<TerrainId, readonly [number, number, number]> = Object.fromEntries(
    TERRAIN_IDS.map((id) => [id, hexToRgbTuple(EDITOR_TERRAIN_COLORS[id])] as const),
) as Record<TerrainId, readonly [number, number, number]>;

const PLAINS_RGB = EDITOR_TERRAIN_RGB.plains;

/** Engine marching-squares classification (matches GameCanvas / Environment). */
export const ENGINE_TERRAIN_VALUES: Record<string, number> = {
    water: -0.1,
    plains: 0.1,
    hill: 0.7,
    mountain: 0.83,
};

export const ENGINE_TERRAIN_COLORS: Record<string, string> = {
    water: '#4a90d9',
    plains: '#c8d68a',
    forest: '#3d6b45',
    hill: '#d4d4d4',
    mountain: '#5a5a5a',
};

export const ENGINE_FOREST_THRESHOLD = 0.5;

export function engineTerrainName(
    value: number,
    forest: number,
    forestThreshold: number = ENGINE_FOREST_THRESHOLD,
): string {
    if (forest > forestThreshold) {
        return 'forest';
    }

    const entries = Object.entries(ENGINE_TERRAIN_VALUES).reverse();

    for (const [name, threshold] of entries) {
        if (value > threshold) {
            return name;
        }
    }

    return 'plains';
}

export function engineCellFillStyle(
    terrainValue: number,
    forestValue: number,
): string {
    const name = engineTerrainName(terrainValue, forestValue);

    return ENGINE_TERRAIN_COLORS[name] ?? '#c8d68a';
}

export function editorTerrainFillStyle(terrain: string): string {
    if (!isTerrainId(terrain)) {
        return EDITOR_TERRAIN_COLORS.plains;
    }

    return EDITOR_TERRAIN_COLORS[terrain as TerrainId];
}

export function editorTerrainRgb(terrain: string): readonly [number, number, number] {
    return isTerrainId(terrain) ? EDITOR_TERRAIN_RGB[terrain] : PLAINS_RGB;
}

function clamp255(n: number): number {
    return Math.max(0, Math.min(255, Math.round(n)));
}

function rgbToHex(r: number, g: number, b: number): string {
    return `#${clamp255(r).toString(16).padStart(2, '0')}${clamp255(g).toString(16).padStart(2, '0')}${clamp255(b).toString(16).padStart(2, '0')}`;
}

/** Light mode dim alpha — shared with map editor and in-game terrain bake. */
export const EDITOR_TERRAIN_DIM_ALPHA_LIGHT = 0.08;

/**
 * Semi-transparent overlay on the playable grid (matches map editor dim pass).
 */
export function editorTerrainDimOverlayFill(isDark: boolean): string {
    return isDark
        ? 'rgba(0, 0, 0, 0.22)'
        : `rgba(0, 0, 0, ${EDITOR_TERRAIN_DIM_ALPHA_LIGHT})`;
}


/**
 * Softens biome boundaries in the map editor by mixing the cell color with neighbor terrain colors.
 */
export function editorBlendedTerrainFillStyle(
    cells: string[][],
    gx: number,
    gy: number,
    neighborBlend = 0.34,
): string {
    const rows = cells.length;
    const cols = cells[0]?.length ?? 0;
    const center = editorTerrainRgb(cells[gx][gy]);

    let sr = 0;
    let sg = 0;
    let sb = 0;
    let n = 0;
    const dirs = [
        [1, 0],
        [-1, 0],
        [0, 1],
        [0, -1],
        [1, 1],
        [1, -1],
        [-1, 1],
        [-1, -1],
    ];

    for (const [dx, dy] of dirs) {
        const x = gx + dx;
        const y = gy + dy;

        if (x < 0 || x >= rows || y < 0 || y >= cols) {
            continue;
        }

        const rgb = editorTerrainRgb(cells[x][y]);
        sr += rgb[0];
        sg += rgb[1];
        sb += rgb[2];
        n++;
    }

    if (n === 0) {
        return rgbToHex(center[0], center[1], center[2]);
    }

    const t = neighborBlend;
    const ar = sr / n;
    const ag = sg / n;
    const ab = sb / n;

    return rgbToHex(
        (1 - t) * center[0] + t * ar,
        (1 - t) * center[1] + t * ag,
        (1 - t) * center[2] + t * ab,
    );
}
