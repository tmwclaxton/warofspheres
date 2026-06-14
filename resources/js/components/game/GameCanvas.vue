<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useIsDark } from '@/composables/useIsDark';
import {
    drawCapitalAtPixel,
    drawInfantryAtPixel,
    drawOutpostAtPixel,
    drawTankAtPixel,
} from '@/lib/mapMarkers';
import {
    editorBlendedTerrainFillStyle,
    editorTerrainDimOverlayFill,
    ENGINE_FOREST_THRESHOLD,
    engineCellFillStyle,
} from '@/lib/terrainRender';
import { GAME_VIEW_ZOOM_MAX, GAME_VIEW_ZOOM_MIN, useCameraStore } from '@/stores/cameraStore';
import { useDraftStore } from '@/stores/draftStore';
import { useGameStore } from '@/stores/gameStore';

const props = withDefaults(
    defineProps<{
        readOnly?: boolean;
        /** When set, orders trigger an immediate snapshot pull (covers missing Reverb). */
        snapshotFetchUrl?: string;
    }>(),
    { readOnly: false, snapshotFetchUrl: '' },
);


const canvasRef = ref<HTMLCanvasElement | null>(null);
const store = useGameStore();
const camera = useCameraStore();
const drafts = useDraftStore();
const { isDark } = useIsDark();

let dragging = false;
let panning = false;
let lastMouse: [number, number] = [0, 0];
let terrainCanvas: HTMLCanvasElement | null = null;
let resizeObserver: ResizeObserver | null = null;
let rafId: number | null = null;
let needsRedraw = false;
let lastRafTimeMs = 0;

/**
 * Smooth movement: display positions continuously chase server target positions
 * at SMOOTH_SPEED_WU_MS world units per millisecond.
 * Chosen to be slightly faster than the max game speed so troops always catch up
 * before the next snapshot, giving fluid motion at any update frequency.
 */
const SMOOTH_SPEED_WU_MS = 0.028; // ~28 wu/sec display speed (game plains = 22.5 wu/sec)
const troopDisplayPositions = new Map<number, [number, number]>();
const troopTargetPositions = new Map<number, [number, number]>();

/** Lasso selection state. */
let lassoStart: [number, number] | null = null;
let lassoCurrent: [number, number] | null = null;
let lassoActive = false;

/** Touch state for single-finger drafting and two-finger pan/pinch-zoom. */
let touchDrafting = false;
let touchPanning = false;
let lastTouchMid: [number, number] = [0, 0];
let lastTouchDist = 0;

function getTouchCoords(
    canvas: HTMLCanvasElement,
    touch: Touch,
): [number, number] {
    const rect = canvas.getBoundingClientRect();
    return [touch.clientX - rect.left, touch.clientY - rect.top];
}

function touchDistance(t1: Touch, t2: Touch): number {
    const dx = t1.clientX - t2.clientX;
    const dy = t1.clientY - t2.clientY;
    return Math.hypot(dx, dy);
}

function touchMidpoint(
    canvas: HTMLCanvasElement,
    t1: Touch,
    t2: Touch,
): [number, number] {
    const rect = canvas.getBoundingClientRect();
    return [
        (t1.clientX + t2.clientX) / 2 - rect.left,
        (t1.clientY + t2.clientY) / 2 - rect.top,
    ];
}

/** One-shot fit when a match snapshot first fills the store (per game uuid). */
const initialFitDone = ref(false);

function tryInitialCameraFit(): void {
    if (initialFitDone.value || !store.initialized) {
        return;
    }

    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const r = canvas.getBoundingClientRect();

    if (r.width < 16 || r.height < 16) {
        return;
    }

    camera.fitCameraToView(store.world.width, store.world.height, r.width, r.height);
    initialFitDone.value = true;
    bakeTerrain();
    draw();
}

function canvasInk(): string {
    return getComputedStyle(document.documentElement)
        .getPropertyValue('--wod-canvas-ink')
        .trim() || (isDark.value ? '#f7f1e3' : '#1a1a1a');
}

function canvasField(): string {
    return getComputedStyle(document.documentElement)
        .getPropertyValue('--wod-canvas-field')
        .trim() || (isDark.value ? '#2a3520' : '#c8d68a');
}

function terrainCellsMatchGrid(cells: string[][], terrain: number[][]): boolean {
    if (cells.length === 0 || terrain.length === 0) {
        return false;
    }

    const t0 = terrain[0];

    if (!t0?.length) {
        return false;
    }

    return cells.length === terrain.length && cells[0]?.length === t0.length;
}

function bakeTerrain() {
    if (!store.terrain || !store.forest || !terrainCanvas) {
        return;
    }

    const ctx = terrainCanvas.getContext('2d');

    if (!ctx) {
        return;
    }

    const { width, height, cellSize } = store.world;
    terrainCanvas.width = width;
    terrainCanvas.height = height;

    const cells = store.terrainCells;
    const useEditorStyle =
        cells !== null && terrainCellsMatchGrid(cells, store.terrain);

    for (let y = 0; y < height; y += cellSize) {
        for (let x = 0; x < width; x += cellSize) {
            const gx = Math.min(store.terrain.length - 1, Math.floor(x / cellSize));
            const gy = Math.min(store.terrain[0].length - 1, Math.floor(y / cellSize));

            if (useEditorStyle && cells) {
                ctx.fillStyle = editorBlendedTerrainFillStyle(cells, gx, gy);
            } else {
                const tv = store.terrain[gx][gy];
                const fv = store.forest[gx][gy];
                ctx.fillStyle = engineCellFillStyle(tv, fv);
            }

            ctx.fillRect(x, y, cellSize + 1, cellSize + 1);
        }
    }

    if (useEditorStyle) {
        ctx.fillStyle = editorTerrainDimOverlayFill(isDark.value);
        ctx.fillRect(0, 0, width, height);
    }
}

function screenToWorld(x: number, y: number): [number, number] {
    return [x / camera.zoom - camera.camX, y / camera.zoom - camera.camY];
}

function worldToScreen(wx: number, wy: number): [number, number] {
    return [(wx + camera.camX) * camera.zoom, (wy + camera.camY) * camera.zoom];
}

function scheduleRedraw(): void {
    needsRedraw = true;
}

function rafLoop(nowMs: number): void {
    const dt = lastRafTimeMs > 0 ? Math.min(nowMs - lastRafTimeMs, 100) : 0;
    lastRafTimeMs = nowMs;

    // Advance each troop's display position toward its server target.
    let anyMoving = false;
    for (const [id, target] of troopTargetPositions) {
        const display = troopDisplayPositions.get(id);
        if (!display) {
            troopDisplayPositions.set(id, [target[0], target[1]]);
            continue;
        }
        const dx = target[0] - display[0];
        const dy = target[1] - display[1];
        const dist = Math.hypot(dx, dy);
        if (dist > 0.5) {
            anyMoving = true;
            const step = Math.min(dist, SMOOTH_SPEED_WU_MS * dt);
            display[0] += (dx / dist) * step;
            display[1] += (dy / dist) * step;
        }
    }

    if (needsRedraw || anyMoving) {
        needsRedraw = false;
        draw();
    }

    rafId = requestAnimationFrame(rafLoop);
}

function draw() {
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const ctx = canvas.getContext('2d');

    if (!ctx) {
        return;
    }

    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * devicePixelRatio;
    canvas.height = rect.height * devicePixelRatio;
    ctx.scale(devicePixelRatio, devicePixelRatio);

    ctx.fillStyle = canvasField();
    ctx.fillRect(0, 0, rect.width, rect.height);

    ctx.save();
    ctx.scale(camera.zoom, camera.zoom);
    ctx.translate(camera.camX, camera.camY);

    if (terrainCanvas) {
        ctx.imageSmoothingEnabled = false;
        ctx.drawImage(terrainCanvas, 0, 0);
    }

    const state = store.latestState;

    if (state) {
        drawTerritory(ctx, state.territory, state.playerColors); // battle lines on top
    }

    for (const city of state?.cities ?? store.cityPositions.map((p, i) => ({
        position: p,
        id: i,
        ownerColor: null,
        ownerSlot: null,
        path: [],
        markerType: null as string | null,
    }))) {
        drawCity(ctx, city.position, city.ownerColor, city.markerType);
    }

    for (const troop of state?.troops ?? []) {
        const isSelected = drafts.selectedTroopIds.includes(troop.id);
        const display = troopDisplayPositions.get(troop.id) ?? troop.position;
        drawTroop(ctx, { ...troop, position: display }, isSelected);
    }

    for (const draft of drafts.draftPaths) {
        drawArrowPath(ctx, draft.points);
    }

    if (drafts.activeDraft) {
        drawArrowPath(ctx, drafts.activeDraft.points, true);
    }

    ctx.restore();

    // Lasso rectangle overlay (screen-space, outside world transform).
    if (lassoActive && lassoStart && lassoCurrent) {
        const [x1, y1] = lassoStart;
        const [x2, y2] = lassoCurrent;
        ctx.save();
        ctx.strokeStyle = 'rgba(255,255,255,0.8)';
        ctx.lineWidth = 1.5;
        ctx.setLineDash([5, 3]);
        ctx.fillStyle = 'rgba(255,255,255,0.08)';
        ctx.fillRect(x1, y1, x2 - x1, y2 - y1);
        ctx.strokeRect(x1, y1, x2 - x1, y2 - y1);
        ctx.setLineDash([]);
        ctx.restore();
    }
}

/**
 * Fog of war — dims cells outside the viewing player's vision.
 *
 * Drawn BEFORE territory lines so the battle-front stays readable even at
 * the edge of visibility.  Uses a medium-opacity fill adapted to dark/light
 * mode so the terrain is still faintly perceptible rather than pitch-black.
 */
function drawFog(
    ctx: CanvasRenderingContext2D,
    vision: number[][] | undefined,
    territory: number[][] | undefined,
) {
    if (!vision?.length || !vision[0]?.length) return;

    const { cellSize } = store.world;
    const w = vision.length - 1;
    const h = vision[0].length - 1;
    const mySlot = store.slot;

    ctx.save();
    ctx.fillStyle = isDark.value
        ? 'rgba(10, 8, 5, 0.68)'
        : 'rgba(180, 168, 150, 0.58)';

    for (let gx = 0; gx < w; gx++) {
        for (let gy = 0; gy < h; gy++) {
            // Only fog cells that are in enemy territory — own backfield stays clear.
            const owner = territory?.[gx]?.[gy] ?? -1;
            if (owner !== mySlot && vision[gx][gy] < ENGINE_FOREST_THRESHOLD) {
                ctx.fillRect(gx * cellSize, gy * cellSize, cellSize, cellSize);
            }
        }
    }

    ctx.restore();
}

/**
 * Draws smooth territory-boundary lines across the whole map, with each
 * player's borders rendered in their own player color.
 *
 * Every cell is owned by whoever has the highest border influence
 * (no threshold — the whole map is always fully divided).  Only the
 * boundary lines are drawn; no fill, so the terrain stays fully visible.
 *
 * Algorithm
 * ---------
 * 1. For each cell, collect boundary edges (shared sides with differently-owned
 *    neighbours) and assign each edge to the owning player (the cell we are
 *    currently iterating).  This keeps each edge in exactly one player's set.
 * 2. Per player: build a corner adjacency graph, then walk it greedily —
 *    preferring straight continuation at junctions — to assemble edges into
 *    the longest possible polylines.
 * 3. Render each player's polylines using quadratic-Bézier midpoint smoothing
 *    in that player's color:  straight stretches stay perfectly straight;
 *    direction-changes become smooth arcs — the "marker-pen on a map" aesthetic.
 */
function drawTerritory(
    ctx: CanvasRenderingContext2D,
    territory: number[][] | undefined,
    playerColors: Record<number, number[]> | undefined,
) {
    if (!territory?.length || !territory[0]?.length || !playerColors) {
        return;
    }

    const { cellSize } = store.world;
    const w = territory.length;
    const h = territory[0].length;

    const cH = h + 1; // stride for corner-index encoding

    function ci(cx: number, cy: number): number {
        return cx * cH + cy;
    }

    // ── 1. Build per-player boundary-edge adjacency graphs ──────────────────
    // Each boundary edge is assigned to the player whose cell we are currently
    // iterating, so every edge belongs to exactly one player's graph.
    const playerAdj = new Map<number, Map<number, Set<number>>>();

    function playerLink(slot: number, cx1: number, cy1: number, cx2: number, cy2: number): void {
        if (!playerAdj.has(slot)) playerAdj.set(slot, new Map());
        const adj = playerAdj.get(slot)!;
        const a = ci(cx1, cy1);
        const b = ci(cx2, cy2);
        if (!adj.has(a)) adj.set(a, new Set());
        if (!adj.has(b)) adj.set(b, new Set());
        adj.get(a)!.add(b);
        adj.get(b)!.add(a);
    }

    for (let gx = 0; gx < w; gx++) {
        for (let gy = 0; gy < h; gy++) {
            const owner = territory[gx][gy];
            // horizontal edge: boundary below this cell — assigned to current cell's owner
            if (gy + 1 < h && territory[gx][gy + 1] !== owner) {
                playerLink(owner, gx, gy + 1, gx + 1, gy + 1);
            }
            // vertical edge: boundary to the right — assigned to current cell's owner
            if (gx + 1 < w && territory[gx + 1][gy] !== owner) {
                playerLink(owner, gx + 1, gy, gx + 1, gy + 1);
            }
        }
    }

    // ── 2 & 3. Per player: trace polylines and draw in player color ──────────
    function ek(a: number, b: number): string {
        return a < b ? `${a}|${b}` : `${b}|${a}`;
    }

    function cpx(idx: number): [number, number] {
        const cx = Math.floor(idx / cH);
        const cy = idx % cH;
        return [cx * cellSize, cy * cellSize];
    }

    ctx.save();
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.globalAlpha = 0.75;
    ctx.shadowColor = 'rgba(0,0,0,0.3)';
    ctx.shadowBlur = 3;

    for (const [slot, adj] of playerAdj) {
        const color = playerColors[slot];
        if (!color) continue;
        ctx.strokeStyle = `rgba(${color[0]},${color[1]},${color[2]},1)`;

        const usedEdges = new Set<string>();

        // Prefer to start from endpoints (odd-degree corners) so closed loops
        // are handled after all open paths are exhausted.
        const starts: number[] = [];
        for (const [c, nbrs] of adj) {
            if (nbrs.size % 2 !== 0) starts.push(c);
        }
        for (const [c] of adj) {
            starts.push(c);
        }

        for (const seed of starts) {
            const seedNbrs = adj.get(seed);
            if (!seedNbrs) continue;

            for (const firstNbr of seedNbrs) {
                const eKey = ek(seed, firstNbr);
                if (usedEdges.has(eKey)) continue;

                // Walk greedily, preferring straight continuation at junctions.
                const poly: [number, number][] = [];
                poly.push(cpx(seed));

                let prev = seed;
                let cur = firstNbr;
                usedEdges.add(eKey);

                while (true) {
                    poly.push(cpx(cur));
                    const nbrs = adj.get(cur);
                    if (!nbrs) break;

                    const [px0, py0] = cpx(prev);
                    const [cx0, cy0] = cpx(cur);
                    const dx = cx0 - px0;
                    const dy = cy0 - py0;

                    let bestNext = -1;
                    let bestDot = -Infinity;

                    for (const n of nbrs) {
                        if (usedEdges.has(ek(cur, n))) continue;
                        const [nx, ny] = cpx(n);
                        const ndx = nx - cx0;
                        const ndy = ny - cy0;
                        const dot = dx * ndx + dy * ndy;
                        if (dot > bestDot) {
                            bestDot = dot;
                            bestNext = n;
                        }
                    }

                    if (bestNext === -1) break;

                    usedEdges.add(ek(cur, bestNext));
                    prev = cur;
                    cur = bestNext;
                }

                if (poly.length < 2) continue;

                ctx.beginPath();
                if (poly.length === 2) {
                    ctx.moveTo(poly[0][0], poly[0][1]);
                    ctx.lineTo(poly[1][0], poly[1][1]);
                } else {
                    // Midpoint-smoothing: straight sections stay straight;
                    // direction-changes become smooth quadratic Bézier arcs.
                    const mx0 = (poly[0][0] + poly[1][0]) / 2;
                    const my0 = (poly[0][1] + poly[1][1]) / 2;
                    ctx.moveTo(mx0, my0);
                    for (let i = 1; i < poly.length - 1; i++) {
                        const mx = (poly[i][0] + poly[i + 1][0]) / 2;
                        const my = (poly[i][1] + poly[i + 1][1]) / 2;
                        ctx.quadraticCurveTo(poly[i][0], poly[i][1], mx, my);
                    }
                    ctx.lineTo(poly[poly.length - 1][0], poly[poly.length - 1][1]);
                }
                ctx.stroke();
            }
        }
    }

    ctx.restore();
}

function drawCity(
    ctx: CanvasRenderingContext2D,
    position: [number, number],
    color: number[] | null,
    markerType?: string | null,
) {
    const [x, y] = position;
    const fill = color ? rgb(color) : '#f1c40f';

    if (markerType === 'capital') {
        drawCapitalAtPixel(ctx, x, y, fill, 15);
    } else {
        drawOutpostAtPixel(ctx, x, y, fill, 13);
    }
}

type TroopDraw = {
    position: [number, number];
    color: number[];
    health: number;
    morale?: number;
    type?: 'infantry' | 'tank';
    maxHealth?: number;
    isShip?: boolean;
};


/** Radii that match the shared mapMarkers pixel-centre functions. */
const TROOP_R_INFANTRY = 9;
const TROOP_R_TANK = 12;
const TROOP_R_SHIP = 11;

function drawTroop(ctx: CanvasRenderingContext2D, troop: TroopDraw, selected = false) {
    const [x, y] = troop.position;
    const morale = troop.morale ?? 100;
    const isTank = troop.type === 'tank';
    const isShip = troop.isShip === true;
    const maxHp = troop.maxHealth ?? (isTank ? 200 : 100);
    const ink = canvasInk();
    const fillColor = rgb(troop.color);
    const unitR = isTank ? TROOP_R_TANK : isShip ? TROOP_R_SHIP : TROOP_R_INFANTRY;

    if (isShip) {
        ctx.fillStyle = fillColor;
        // Hull (ellipse)
        ctx.beginPath();
        ctx.ellipse(x, y + 1, 10, 6, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = ink;
        ctx.lineWidth = 1.5;
        ctx.stroke();
        // Mast
        ctx.beginPath();
        ctx.moveTo(x, y + 1);
        ctx.lineTo(x, y - 10);
        ctx.strokeStyle = ink;
        ctx.lineWidth = 1.5;
        ctx.stroke();
        // Sail
        ctx.beginPath();
        ctx.moveTo(x, y - 9);
        ctx.lineTo(x + 7, y - 4);
        ctx.lineTo(x, y);
        ctx.closePath();
        ctx.globalAlpha = 0.75;
        ctx.fillStyle = fillColor;
        ctx.fill();
        ctx.globalAlpha = 1;
    } else if (isTank) {
        drawTankAtPixel(ctx, x, y, fillColor, TROOP_R_TANK);
    } else {
        drawInfantryAtPixel(ctx, x, y, fillColor, TROOP_R_INFANTRY);
    }

    if (selected) {
        ctx.strokeStyle = 'rgba(255,255,255,0.9)';
        ctx.lineWidth = 2.5;
        ctx.setLineDash([4, 3]);
        ctx.beginPath();
        ctx.arc(x, y, unitR + 4, 0, Math.PI * 2);
        ctx.stroke();
        ctx.setLineDash([]);
    }

    // Health bar (shown only when damaged)
    if (troop.health < maxHp) {
        const barY = y - unitR - 8;
        ctx.fillStyle = 'rgba(0,0,0,0.45)';
        ctx.fillRect(x - 9, barY, 18, 3);
        ctx.fillStyle = '#2ecc71';
        ctx.fillRect(x - 9, barY, (18 * troop.health) / maxHp, 3);
    }

    // Morale bar (shown only when depleted)
    if (morale < 99) {
        const barY = y - unitR - 4;
        ctx.fillStyle = 'rgba(0,0,0,0.45)';
        ctx.fillRect(x - 9, barY, 18, 2);
        ctx.fillStyle = '#9b59b6';
        ctx.fillRect(x - 9, barY, (18 * morale) / 100, 2);
    }
}

function drawArrowPath(ctx: CanvasRenderingContext2D, points: [number, number][], dashed = false) {
    if (points.length < 2) {
        return;
    }

    ctx.strokeStyle = canvasInk();
    ctx.lineWidth = 2;
    ctx.setLineDash(dashed ? [6, 4] : []);

    ctx.beginPath();
    ctx.moveTo(points[0][0], points[0][1]);

    for (let i = 1; i < points.length; i++) {
        ctx.lineTo(points[i][0], points[i][1]);
    }

    ctx.stroke();
    ctx.setLineDash([]);

    const last = points.at(-1)!;
    const prev = points.at(-2)!;
    const angle = Math.atan2(last[1] - prev[1], last[0] - prev[0]);
    ctx.beginPath();
    ctx.moveTo(last[0], last[1]);
    ctx.lineTo(last[0] - Math.cos(angle - 0.4) * 10, last[1] - Math.sin(angle - 0.4) * 10);
    ctx.moveTo(last[0], last[1]);
    ctx.lineTo(last[0] - Math.cos(angle + 0.4) * 10, last[1] - Math.sin(angle + 0.4) * 10);
    ctx.stroke();
}

function rgb(color: number[]): string {
    return `rgb(${color[0]}, ${color[1]}, ${color[2]})`;
}

function findEntity(world: [number, number]): { id: number; kind: 'troop' | 'city' } | null {
    const state = store.latestState;

    if (!state) {
        return null;
    }

    /** World radius so the pick target is at least ~22 CSS px (fixed radii vanish when zoomed out). */
    const z = camera.zoom;
    const troopPickR = Math.max(12, 22 / z);
    const cityPickR = Math.max(14, 22 / z);

    type PickHit = { id: number; kind: 'troop' | 'city'; dist: number };

    const hits: PickHit[] = [];

    for (const troop of state.troops) {
        if (troop.ownerSlot !== store.slot) {
            continue;
        }

        const dx = troop.position[0] - world[0];
        const dy = troop.position[1] - world[1];
        const dist = Math.hypot(dx, dy);

        if (dist < troopPickR) {
            hits.push({ id: troop.id, kind: 'troop', dist });
        }
    }

    for (const city of state.cities) {
        if (city.ownerSlot !== store.slot) {
            continue;
        }

        const dx = city.position[0] - world[0];
        const dy = city.position[1] - world[1];
        const dist = Math.hypot(dx, dy);

        if (dist < cityPickR) {
            hits.push({ id: city.id, kind: 'city', dist });
        }
    }

    if (hits.length === 0) {
        return null;
    }

    hits.sort((a, b) => a.dist - b.dist);

    const best = hits[0];

    return { id: best.id, kind: best.kind };
}

function onMouseDown(e: MouseEvent) {
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const rect = canvas.getBoundingClientRect();
    const sx = e.clientX - rect.left;
    const sy = e.clientY - rect.top;
    lastMouse = [sx, sy];

    if (e.button === 2) {
        panning = true;

        return;
    }

    if (props.readOnly) {
        return;
    }

    const world = screenToWorld(sx, sy);
    const entity = findEntity(world);

    if (entity) {
        // If a lasso selection is active and the user starts a path from any entity,
        // begin group drafts for all selected troops.
        if (entity.kind === 'troop' && drafts.selectedTroopIds.length > 1) {
            dragging = true;
            for (const id of drafts.selectedTroopIds) {
                drafts.beginPath(id, 'troop', world);
            }
        } else {
            drafts.clearSelection();
            dragging = true;
            drafts.beginPath(entity.id, entity.kind, world);
        }
    } else {
        // No entity hit — start lasso selection (clears existing selection first).
        drafts.clearSelection();
        lassoStart = [sx, sy];
        lassoCurrent = [sx, sy];
        lassoActive = true;
    }
}

function onMouseMove(e: MouseEvent) {
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const rect = canvas.getBoundingClientRect();
    const sx = e.clientX - rect.left;
    const sy = e.clientY - rect.top;

    if (panning) {
        camera.camX += (sx - lastMouse[0]) / camera.zoom;
        camera.camY += (sy - lastMouse[1]) / camera.zoom;
        lastMouse = [sx, sy];
        scheduleRedraw();

        return;
    }

    if (lassoActive) {
        lassoCurrent = [sx, sy];
        scheduleRedraw();

        return;
    }

    if (dragging) {
        drafts.extendPath(screenToWorld(sx, sy));
        scheduleRedraw();
    }
}

function onMouseUp() {
    if (lassoActive && lassoStart && lassoCurrent) {
        lassoActive = false;

        // Find own troops inside the lasso rectangle (in screen space).
        const x1 = Math.min(lassoStart[0], lassoCurrent[0]);
        const x2 = Math.max(lassoStart[0], lassoCurrent[0]);
        const y1 = Math.min(lassoStart[1], lassoCurrent[1]);
        const y2 = Math.max(lassoStart[1], lassoCurrent[1]);

        const state = store.latestState;
        if (state && (x2 - x1 > 4 || y2 - y1 > 4)) {
            const selected = state.troops
                .filter((t) => {
                    if (t.ownerSlot !== store.slot) return false;
                    const [wx, wy] = worldToScreen(t.position[0], t.position[1]);
                    return wx >= x1 && wx <= x2 && wy >= y1 && wy <= y2;
                })
                .map((t) => t.id);
            drafts.setSelection(selected);
        }

        lassoStart = null;
        lassoCurrent = null;
        scheduleRedraw();
    }

    if (dragging) {
        drafts.finishPath();
        dragging = false;
        scheduleRedraw();
    }

    panning = false;
}

function onWheel(e: WheelEvent) {
    e.preventDefault();

    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const rect = canvas.getBoundingClientRect();
    const sx = e.clientX - rect.left;
    const sy = e.clientY - rect.top;
    const [wx, wy] = screenToWorld(sx, sy);
    const factor = e.deltaY > 0 ? 0.9 : 1.1;
    const prevZoom = camera.zoom;
    const nextZoom = Math.min(GAME_VIEW_ZOOM_MAX, Math.max(GAME_VIEW_ZOOM_MIN, prevZoom * factor));

    if (nextZoom === prevZoom) {
        return;
    }

    camera.zoom = nextZoom;
    camera.camX = sx / nextZoom - wx;
    camera.camY = sy / nextZoom - wy;
    scheduleRedraw();
}

function onKeyDown(e: KeyboardEvent) {
    if (props.readOnly) {
        return;
    }

    if (e.code === 'Space') {
        e.preventDefault();
        const url = props.snapshotFetchUrl?.trim() ?? '';

        store.submitOrders(
            store.gameUuid,
            url.length > 0 ? { snapshotFetchUrl: url } : undefined,
        );
    }

    if (e.key.toLowerCase() === 'c') {
        drafts.clearDrafts();
        draw();
    }

    if (e.key.toLowerCase() === 's') {
        e.preventDefault();
        // Halt all own troops: submit empty-path orders for every own troop.
        store.stopAllTroops(store.gameUuid, props.snapshotFetchUrl?.trim() || undefined);
    }
}

function onTouchStart(e: TouchEvent) {
    e.preventDefault();
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    if (e.touches.length === 1) {
        const [sx, sy] = getTouchCoords(canvas, e.touches[0]);
        lastMouse = [sx, sy];

        if (props.readOnly) {
            touchPanning = true;
            touchDrafting = false;
            return;
        }

        const world = screenToWorld(sx, sy);
        const entity = findEntity(world);

        if (entity) {
            touchDrafting = true;
            touchPanning = false;
            drafts.beginPath(entity.id, entity.kind, world);
        } else {
            touchPanning = true;
            touchDrafting = false;
        }
    } else if (e.touches.length === 2) {
        touchDrafting = false;
        touchPanning = false;

        if (drafts.activeDraft) {
            drafts.finishPath();
        }

        lastTouchMid = touchMidpoint(canvas, e.touches[0], e.touches[1]);
        lastTouchDist = touchDistance(e.touches[0], e.touches[1]);
    }
}

function onTouchMove(e: TouchEvent) {
    e.preventDefault();
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    if (e.touches.length === 1) {
        const [sx, sy] = getTouchCoords(canvas, e.touches[0]);

        if (touchPanning) {
            camera.camX += (sx - lastMouse[0]) / camera.zoom;
            camera.camY += (sy - lastMouse[1]) / camera.zoom;
            lastMouse = [sx, sy];
            scheduleRedraw();
        } else if (touchDrafting) {
            lastMouse = [sx, sy];
            drafts.extendPath(screenToWorld(sx, sy));
            scheduleRedraw();
        }
    } else if (e.touches.length === 2) {
        const newMid = touchMidpoint(canvas, e.touches[0], e.touches[1]);
        const newDist = touchDistance(e.touches[0], e.touches[1]);

        camera.camX += (newMid[0] - lastTouchMid[0]) / camera.zoom;
        camera.camY += (newMid[1] - lastTouchMid[1]) / camera.zoom;

        if (lastTouchDist > 0 && newDist > 0) {
            const factor = newDist / lastTouchDist;
            const [wx, wy] = screenToWorld(newMid[0], newMid[1]);
            const prevZoom = camera.zoom;
            const nextZoom = Math.min(
                GAME_VIEW_ZOOM_MAX,
                Math.max(GAME_VIEW_ZOOM_MIN, prevZoom * factor),
            );

            if (nextZoom !== prevZoom) {
                camera.zoom = nextZoom;
                camera.camX = newMid[0] / nextZoom - wx;
                camera.camY = newMid[1] / nextZoom - wy;
            }
        }

        lastTouchMid = newMid;
        lastTouchDist = newDist;
        scheduleRedraw();
    }
}

function onTouchEnd(e: TouchEvent) {
    e.preventDefault();

    if (e.touches.length === 0) {
        if (touchDrafting) {
            drafts.finishPath();
            touchDrafting = false;
            scheduleRedraw();
        }

        touchPanning = false;
        lastTouchDist = 0;
    } else if (e.touches.length === 1) {
        lastTouchDist = 0;
        touchPanning = false;
        touchDrafting = false;

        const canvas = canvasRef.value;
        if (canvas) {
            lastMouse = getTouchCoords(canvas, e.touches[0]);
        }
    }
}

onMounted(() => {
    terrainCanvas = document.createElement('canvas');
    window.addEventListener('keydown', onKeyDown);

    const canvas = canvasRef.value;

    if (canvas) {
        resizeObserver = new ResizeObserver(() => {
            tryInitialCameraFit();
            scheduleRedraw();
        });
        resizeObserver.observe(canvas);
    }

    tryInitialCameraFit();
    needsRedraw = true;
    rafId = requestAnimationFrame(rafLoop);});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeyDown);
    resizeObserver?.disconnect();
    resizeObserver = null;

    if (rafId !== null) {
        cancelAnimationFrame(rafId);
        rafId = null;
    }
});

watch(
    () => store.gameUuid,
    () => {
        initialFitDone.value = false;
        nextTick(() => tryInitialCameraFit());
    },
);

watch(
    () => [store.initialized, store.world.width, store.world.height],
    () => {
        nextTick(() => tryInitialCameraFit());
    },
);

watch(
    () => [store.terrain, store.forest, store.terrainCells],
    () => {
        bakeTerrain();
        scheduleRedraw();
    },
    { deep: true },
);

watch(
    () => [store.latestState, drafts.draftPaths, drafts.activeDraft],
    ([newState]) => {
        const troops = (newState as typeof store.latestState)?.troops ?? [];
        for (const t of troops) {
            troopTargetPositions.set(t.id, t.position);
            // Snap display to target on first appearance
            if (!troopDisplayPositions.has(t.id)) {
                troopDisplayPositions.set(t.id, [t.position[0], t.position[1]]);
            }
        }
        scheduleRedraw();
    },
    { deep: true },
);

watch(isDark, () => {
    bakeTerrain();
    scheduleRedraw();
});
</script>

<template>
    <canvas
        ref="canvasRef"
        class="h-full w-full cursor-crosshair"
        @contextmenu.prevent
        @mousedown="onMouseDown"
        @mousemove="onMouseMove"
        @mouseup="onMouseUp"
        @mouseleave="onMouseUp"
        @wheel.prevent="onWheel"
        @touchstart.prevent="onTouchStart"
        @touchmove.prevent="onTouchMove"
        @touchend.prevent="onTouchEnd"
        @touchcancel.prevent="onTouchEnd"
    />
</template>
