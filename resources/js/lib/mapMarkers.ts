import { isTerrainId } from '@/lib/terrainCatalog';
import type { TerrainId } from '@/lib/terrainCatalog';

export const MAP_MARKER_CAPITAL = 'capital' as const;

export const MAP_MARKER_FLAG = 'flag' as const;

export type MapMarkerType =
    | typeof MAP_MARKER_CAPITAL
    | typeof MAP_MARKER_FLAG
    | 'infantry'
    | 'tank';

/** Visual scale for editor canvas markers vs base layout fractions. */
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
 * Dark outer glow so troop markers read on pale terrain (capitals/flags use {@link strokeMarkerHalo}).
 * `sizeRef` replaces `cellPx * scale` — pass `cellPx * scale` for grid-based callers or the marker
 * radius directly for pixel-centre callers.
 */
function strokeTroopMarkerBackdrop(
    ctx: CanvasRenderingContext2D,
    tracePath: () => void,
    sizeRef: number,
): void {
    const blur = Math.max(3, sizeRef * 0.2);

    ctx.save();
    tracePath();
    ctx.shadowColor = 'rgba(0, 0, 0, 0.5)';
    ctx.shadowBlur = blur;
    ctx.strokeStyle = 'rgba(0, 0, 0, 0.35)';
    ctx.lineWidth = Math.max(2.5, sizeRef * 0.1);
    ctx.lineJoin = 'round';
    ctx.stroke();
    ctx.restore();
}

/** Single thick black rim for infantry / tank glyphs (vs white + thin black on capitals & flags). */
function troopMarkerOutlineWidth(sizeRef: number): number {
    return Math.max(3, sizeRef * 0.078);
}

/**
 * Soft outer glow + bright rim so markers read clearly on busy terrain.
 * `sizeRef` replaces `cellPx * scale`.
 */
function strokeMarkerHalo(
    ctx: CanvasRenderingContext2D,
    tracePath: () => void,
    sizeRef: number,
): void {
    const blur = Math.max(5, sizeRef * 0.34);

    ctx.save();
    tracePath();
    ctx.shadowColor = 'rgba(255, 252, 235, 0.98)';
    ctx.shadowBlur = blur;
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.92)';
    ctx.lineWidth = Math.max(2.5, sizeRef * 0.11);
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

// ---------------------------------------------------------------------------
// Pixel-centre drawing functions — used by the live game canvas and any other
// consumer that already has world-space coordinates.
// ---------------------------------------------------------------------------

/**
 * Capital: filled 5-pointed star centred at (cx, cy).
 * `outerR` controls the star's outer radius in world pixels.
 */
export function drawCapitalAtPixel(
    ctx: CanvasRenderingContext2D,
    cx: number,
    cy: number,
    colorHex: string,
    outerR: number,
): void {
    const innerR = outerR * STAR_INNER_RATIO;
    const sizeRef = outerR / 0.35;

    strokeMarkerHalo(ctx, () => pathStar5(ctx, cx, cy, outerR, innerR), sizeRef);

    pathStar5(ctx, cx, cy, outerR, innerR);
    ctx.fillStyle = colorHex;
    ctx.fill();

    pathStar5(ctx, cx, cy, outerR, innerR);
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.88)';
    ctx.lineWidth = Math.max(1.5, outerR * 0.14);
    ctx.lineJoin = 'round';
    ctx.stroke();

    pathStar5(ctx, cx, cy, outerR, innerR);
    ctx.strokeStyle = 'rgba(12, 12, 16, 0.94)';
    ctx.lineWidth = Math.max(1, outerR * 0.09);
    ctx.stroke();
}

/**
 * Outpost / flag: filled hexagon centred at (cx, cy).
 * `r` is the circumradius of the hexagon in world pixels.
 */
export function drawOutpostAtPixel(
    ctx: CanvasRenderingContext2D,
    cx: number,
    cy: number,
    colorHex: string,
    r: number,
): void {
    const sizeRef = r / 0.27;

    strokeMarkerHalo(ctx, () => pathHexagonPointy(ctx, cx, cy, r), sizeRef);

    pathHexagonPointy(ctx, cx, cy, r);
    ctx.fillStyle = colorHex;
    ctx.fill();

    pathHexagonPointy(ctx, cx, cy, r);
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.88)';
    ctx.lineWidth = Math.max(1.5, r * 0.18);
    ctx.lineJoin = 'round';
    ctx.stroke();

    pathHexagonPointy(ctx, cx, cy, r);
    ctx.strokeStyle = 'rgba(12, 12, 16, 0.94)';
    ctx.lineWidth = Math.max(1, r * 0.11);
    ctx.stroke();
}

/**
 * Infantry: filled circle centred at (cx, cy).
 * `r` is the radius in world pixels.
 */
export function drawInfantryAtPixel(
    ctx: CanvasRenderingContext2D,
    cx: number,
    cy: number,
    colorHex: string,
    r: number,
): void {
    const sizeRef = r / 0.22;

    strokeTroopMarkerBackdrop(
        ctx,
        () => {
            ctx.beginPath();
            ctx.arc(cx, cy, r, 0, Math.PI * 2);
        },
        sizeRef,
    );

    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.fillStyle = colorHex;
    ctx.fill();

    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(0, 0, 0, 0.92)';
    ctx.lineWidth = troopMarkerOutlineWidth(sizeRef);
    ctx.lineJoin = 'round';
    ctx.stroke();
}

/**
 * Tank: filled circle with a centred rectangle, centred at (cx, cy).
 * `r` is the outer circle radius in world pixels.
 */
export function drawTankAtPixel(
    ctx: CanvasRenderingContext2D,
    cx: number,
    cy: number,
    colorHex: string,
    r: number,
): void {
    const sizeRef = r / 0.24;
    const outlineW = troopMarkerOutlineWidth(sizeRef);

    strokeTroopMarkerBackdrop(
        ctx,
        () => {
            ctx.beginPath();
            ctx.arc(cx, cy, r, 0, Math.PI * 2);
        },
        sizeRef,
    );

    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.fillStyle = colorHex;
    ctx.fill();

    const rw = r * 0.85;
    const rh = r * 0.38;
    ctx.fillStyle = 'rgba(255, 252, 235, 0.92)';
    ctx.fillRect(cx - rw / 2, cy - rh / 2, rw, rh);

    ctx.strokeStyle = 'rgba(0, 0, 0, 0.92)';
    ctx.lineWidth = outlineW * 0.85;
    ctx.lineJoin = 'round';
    ctx.strokeRect(cx - rw / 2, cy - rh / 2, rw, rh);

    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.lineWidth = outlineW;
    ctx.stroke();
}

// ---------------------------------------------------------------------------
// Grid-based drawing functions — used by the map editor canvas.
// These are thin wrappers that convert (grid col, grid row, cellPx) to a
// pixel-centre + radius and delegate to the pixel-centre functions above.
// ---------------------------------------------------------------------------

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
    drawCapitalAtPixel(ctx, cx, cy, colorHex, cellPx * 0.35 * s);
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
    drawOutpostAtPixel(ctx, cx, cy, colorHex, cellPx * 0.27 * s);
}

export function drawInfantryMarker(
    ctx: CanvasRenderingContext2D,
    gx: number,
    gy: number,
    colorHex: string,
    cellPx: number,
): void {
    const s = MAP_MARKER_EDITOR_DRAW_SCALE;
    const cx = gx * cellPx + cellPx / 2;
    const cy = gy * cellPx + cellPx / 2;
    drawInfantryAtPixel(ctx, cx, cy, colorHex, cellPx * 0.22 * s);
}

export function drawTankMarker(
    ctx: CanvasRenderingContext2D,
    gx: number,
    gy: number,
    colorHex: string,
    cellPx: number,
): void {
    const s = MAP_MARKER_EDITOR_DRAW_SCALE;
    const cx = gx * cellPx + cellPx / 2;
    const cy = gy * cellPx + cellPx / 2;
    drawTankAtPixel(ctx, cx, cy, colorHex, cellPx * 0.24 * s);
}
