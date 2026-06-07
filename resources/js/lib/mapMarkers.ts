import { isTerrainId, type TerrainId } from '@/lib/terrainCatalog';

export const MAP_MARKER_CAPITAL = 'capital' as const;

export const MAP_MARKER_FLAG = 'flag' as const;

export type MapMarkerType = typeof MAP_MARKER_CAPITAL | typeof MAP_MARKER_FLAG;

/** Visual scale for editor canvas markers (capital / flag) vs base layout fractions. */
export const MAP_MARKER_EDITOR_DRAW_SCALE = 3;

/** Inner radius of a 5-point star as a fraction of outer radius (classic “w” shape). */
const STAR_INNER_RATIO = 0.38;

/**
 * Builds a closed 5-pointed star path (point facing up).
 */
function pathStar5(
    ctx: CanvasRenderingContext2D,
    cx: number,
    cy: number,
    outerR: number,
    innerR: number,
): void {
    ctx.beginPath();

    for (let i = 0; i < 10; i++) {
        const r = i % 2 === 0 ? outerR : innerR;
        const a = (Math.PI * i) / 5 - Math.PI / 2;
        const x = cx + Math.cos(a) * r;
        const y = cy + Math.sin(a) * r;

        if (i === 0) {
            ctx.moveTo(x, y);
        } else {
            ctx.lineTo(x, y);
        }
    }

    ctx.closePath();
}

/**
 * Builds a closed regular hexagon path (vertex at top, flat sides left/right).
 */
function pathHexagonPointy(ctx: CanvasRenderingContext2D, cx: number, cy: number, r: number): void {
    ctx.beginPath();

    for (let i = 0; i < 6; i++) {
        const a = (Math.PI / 3) * i - Math.PI / 2;
        const x = cx + Math.cos(a) * r;
        const y = cy + Math.sin(a) * r;

        if (i === 0) {
            ctx.moveTo(x, y);
        } else {
            ctx.lineTo(x, y);
        }
    }

    ctx.closePath();
}

/**
 * Soft outer glow + bright rim so markers read clearly on busy terrain.
 */
function strokeMarkerHalo(
    ctx: CanvasRenderingContext2D,
    tracePath: () => void,
    cellPx: number,
    scale: number,
): void {
    const blur = Math.max(5, cellPx * 0.34 * scale);

    ctx.save();
    tracePath();
    ctx.shadowColor = 'rgba(255, 252, 235, 0.98)';
    ctx.shadowBlur = blur;
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.92)';
    ctx.lineWidth = Math.max(2.5, cellPx * 0.11 * scale);
    ctx.lineJoin = 'round';
    ctx.stroke();
    ctx.restore();
}

/** Terrains where capitals and flags may not be placed (matches App\Maps\MapMarkers::NON_PLACEABLE_TERRAIN). */
const MARKER_NON_PLACEABLE_TERRAINS: ReadonlySet<TerrainId> = new Set([
    'water',
    'deep_water',
    'river',
    'hill',
    'mountain',
]);

export function isPlaceableTerrain(terrainId: string): boolean {
    return isTerrainId(terrainId) && !MARKER_NON_PLACEABLE_TERRAINS.has(terrainId);
}

/**
 * Open water / river tiles used for visual clearance (marker art is drawn larger than a cell).
 * Matches {@see App\Maps\MapMarkers::HYDRAULIC_WATER_TERRAIN}.
 */
const HYDRAULIC_WATER_TERRAINS: ReadonlySet<TerrainId> = new Set(['water', 'deep_water', 'river']);

/** Minimum Chebyshev distance from hydraulic water so marker glyphs do not overlap water tiles. */
export const MAP_MARKER_MIN_CHEBYSHEV_FROM_WATER = 2;

export function isHydraulicWaterTerrain(terrainId: string): boolean {
    return isTerrainId(terrainId) && HYDRAULIC_WATER_TERRAINS.has(terrainId);
}

/**
 * True when every hydraulic-water cell is at least `minChebyshev` away (Chebyshev / king moves).
 */
export function isFarEnoughFromHydraulicWaterForMapMarker(
    cells: string[][],
    rows: number,
    cols: number,
    row: number,
    col: number,
    minChebyshev: number = MAP_MARKER_MIN_CHEBYSHEV_FROM_WATER,
): boolean {
    const ext = minChebyshev - 1;

    for (let dr = -ext; dr <= ext; dr++) {
        for (let dc = -ext; dc <= ext; dc++) {
            const r = row + dr;
            const c = col + dc;

            if (r < 0 || r >= rows || c < 0 || c >= cols) {
                continue;
            }

            const terr = cells[r]?.[c];

            if (typeof terr === 'string' && isHydraulicWaterTerrain(terr)) {
                return false;
            }
        }
    }

    return true;
}

export function drawCapitalMarker(
    ctx: CanvasRenderingContext2D,
    gx: number,
    gy: number,
    colorHex: string,
    cellPx: number,
): void {
    const s = MAP_MARKER_EDITOR_DRAW_SCALE;
    const cx = gx * cellPx + cellPx / 2;
    const cy = gy * cellPx + cellPx / 2;
    const outerR = cellPx * 0.35 * s;
    const innerR = outerR * STAR_INNER_RATIO;

    strokeMarkerHalo(ctx, () => pathStar5(ctx, cx, cy, outerR, innerR), cellPx, s);

    pathStar5(ctx, cx, cy, outerR, innerR);
    ctx.fillStyle = colorHex;
    ctx.fill();

    pathStar5(ctx, cx, cy, outerR, innerR);
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.88)';
    ctx.lineWidth = Math.max(1.5, cellPx * 0.05 * s);
    ctx.lineJoin = 'round';
    ctx.stroke();

    pathStar5(ctx, cx, cy, outerR, innerR);
    ctx.strokeStyle = 'rgba(12, 12, 16, 0.94)';
    ctx.lineWidth = Math.max(1, cellPx * 0.032 * s);
    ctx.stroke();
}

export function drawFlagMarker(
    ctx: CanvasRenderingContext2D,
    gx: number,
    gy: number,
    colorHex: string,
    cellPx: number,
): void {
    const s = MAP_MARKER_EDITOR_DRAW_SCALE;
    const cx = gx * cellPx + cellPx / 2;
    const cy = gy * cellPx + cellPx / 2;
    const r = cellPx * 0.27 * s;

    strokeMarkerHalo(ctx, () => pathHexagonPointy(ctx, cx, cy, r), cellPx, s);

    pathHexagonPointy(ctx, cx, cy, r);
    ctx.fillStyle = colorHex;
    ctx.fill();

    pathHexagonPointy(ctx, cx, cy, r);
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.88)';
    ctx.lineWidth = Math.max(1.5, cellPx * 0.048 * s);
    ctx.lineJoin = 'round';
    ctx.stroke();

    pathHexagonPointy(ctx, cx, cy, r);
    ctx.strokeStyle = 'rgba(12, 12, 16, 0.94)';
    ctx.lineWidth = Math.max(1, cellPx * 0.03 * s);
    ctx.stroke();
}
