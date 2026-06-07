<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- editor exposes mutable refs shared by map builder */
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { MAP_EDITOR_MAX_ZOOM, MAP_EDITOR_MIN_ZOOM } from '@/composables/useMapEditor';
import type { MapEditorInstance } from '@/composables/useMapEditor';
import { drawCapitalMarker, drawFlagMarker } from '@/lib/mapMarkers';
import { editorBlendedTerrainFillStyle } from '@/lib/terrainRender';

const props = defineProps<{
    editor: MapEditorInstance;
    teamColors: { slot: number; hex: string; label: string }[];
}>();

function hexForTeam(team: number): string {
    return props.teamColors.find((c) => c.slot === team)?.hex ?? '#888888';
}

const canvasRef = ref<HTMLCanvasElement | null>(null);
let painting = false;
let panning = false;
let lastMouse: [number, number] = [0, 0];
let resizeObserver: ResizeObserver | null = null;

/** Coalesces multiple invalidations (Vue watch + pointer events) into one paint per frame. */
let renderRaf = 0;
let renderPending = false;

/** Canvas / void outside the playable grid (letterbox). */
const VIEWPORT_VOID = '#a8ad9a';
/** Workspace behind the grid (infinite-canvas feel). */
const WORKSPACE_FILL = '#c9cfba';

/**
 * Semi-transparent overlay on playable cells only so markers read brighter than terrain.
 */
const MAP_TERRAIN_DIM_ALPHA = 0.08;

/**
 * Exponential wheel zoom: factor = exp(-deltaPx * sensitivity).
 * Higher values feel faster for both mouse wheels and trackpads.
 */
const ZOOM_WHEEL_SENSITIVITY = 0.004;
const TILE_SCALE_TARGET_BAR_PX = 96;

function wheelDeltaPx(e: WheelEvent, viewportHeightPx: number): number {
    if (e.deltaMode === WheelEvent.DOM_DELTA_LINE) {
        return e.deltaY * 16;
    }

    if (e.deltaMode === WheelEvent.DOM_DELTA_PAGE) {
        return e.deltaY * viewportHeightPx;
    }

    return e.deltaY;
}

const viewportSize = ref({ width: 0, height: 0 });

function niceTileCount(raw: number): number {
    if (raw <= 1) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const normalized = raw / magnitude;

    if (normalized < 1.5) {
        return magnitude;
    }

    if (normalized < 3.5) {
        return 2 * magnitude;
    }

    if (normalized < 7.5) {
        return 5 * magnitude;
    }

    return 10 * magnitude;
}

const tileScale = computed(() => {
    const { width, height } = viewportSize.value;
    const zoom = props.editor.zoom.value;
    const cellSize = props.editor.cellSize;

    if (width < 16 || height < 16) {
        return null;
    }

    const rawTiles = TILE_SCALE_TARGET_BAR_PX / zoom / cellSize;
    const tileCount = niceTileCount(Math.max(1, rawTiles));
    const barWidthPx = tileCount * cellSize * zoom;
    const visibleCols = Math.max(1, Math.round(width / zoom / cellSize));
    const visibleRows = Math.max(1, Math.round(height / zoom / cellSize));

    return {
        barWidthPx,
        label: tileCount === 1 ? '1 tile' : `${tileCount} tiles`,
        visibleCols,
        visibleRows,
    };
});

const placementHint = computed(() => {
    const t = props.editor.activeTool.value;

    if (t === 'capital') {
        return 'Click land to place or move this team’s capital.';
    }

    if (t === 'flag') {
        return 'Click land to place a flag for this team.';
    }

    return '';
});

function screenToWorld(sx: number, sy: number): [number, number] {
    const z = props.editor.zoom.value;

    return [sx / z - props.editor.camX.value, sy / z - props.editor.camY.value];
}

function worldToGrid(wx: number, wy: number): [number, number] | null {
    const cs = props.editor.cellSize;
    const gx = Math.floor(wx / cs);
    const gy = Math.floor(wy / cs);
    const nRows = props.editor.gridRows.value;
    const nCols = props.editor.gridCols.value;

    if (gx < 0 || gx >= nRows || gy < 0 || gy >= nCols) {
        return null;
    }

    return [gx, gy];
}

function applyFitToViewport(): void {
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const r = canvas.getBoundingClientRect();

    if (r.width < 16 || r.height < 16) {
        return;
    }

    props.editor.fitMapToView(r.width, r.height);
}

function scheduleDraw(): void {
    if (renderPending) {
        return;
    }

    renderPending = true;
    renderRaf = requestAnimationFrame(() => {
        renderPending = false;
        renderRaf = 0;
        draw();
    });
}

function cancelScheduledDraw(): void {
    if (renderRaf !== 0) {
        cancelAnimationFrame(renderRaf);
        renderRaf = 0;
    }

    renderPending = false;
}

function draw(): void {
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const ctx = canvas.getContext('2d', { alpha: false });

    if (!ctx) {
        return;
    }

    const rect = canvas.getBoundingClientRect();
    viewportSize.value = { width: rect.width, height: rect.height };
    const dpr = window.devicePixelRatio || 1;
    const nextW = Math.max(1, Math.round(rect.width * dpr));
    const nextH = Math.max(1, Math.round(rect.height * dpr));

    if (canvas.width !== nextW || canvas.height !== nextH) {
        canvas.width = nextW;
        canvas.height = nextH;
    }

    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.imageSmoothingEnabled = false;

    const cs = props.editor.cellSize;
    const cells = props.editor.cells.value;
    const ww = props.editor.worldWidth.value;
    const wh = props.editor.worldHeight.value;
    const nRows = props.editor.gridRows.value;
    const nCols = props.editor.gridCols.value;

    ctx.fillStyle = VIEWPORT_VOID;
    ctx.fillRect(0, 0, rect.width, rect.height);

    ctx.save();
    ctx.scale(props.editor.zoom.value, props.editor.zoom.value);
    ctx.translate(props.editor.camX.value, props.editor.camY.value);

    const margin = Math.max(ww, wh) * 1.25;
    ctx.fillStyle = WORKSPACE_FILL;
    ctx.fillRect(-margin, -margin, ww + 2 * margin, wh + 2 * margin);

    for (let gy = 0; gy < nCols; gy++) {
        for (let gx = 0; gx < nRows; gx++) {
            const px = gx * cs;
            const py = gy * cs;
            ctx.fillStyle = editorBlendedTerrainFillStyle(cells, gx, gy);
            // Overlap by 1px so scaled/zoomed tiles do not leave workspace gaps between cells.
            ctx.fillRect(px, py, cs + 1, cs + 1);
        }
    }

    ctx.fillStyle = `rgba(0, 0, 0, ${MAP_TERRAIN_DIM_ALPHA})`;
    ctx.fillRect(0, 0, ww, wh);

    for (const m of props.editor.markers.value) {
        const hex = hexForTeam(m.team);

        if (m.type === 'capital') {
            drawCapitalMarker(ctx, m.row, m.col, hex, cs);
        } else {
            drawFlagMarker(ctx, m.row, m.col, hex, cs);
        }
    }

    ctx.restore();
}

function onPointerDown(e: PointerEvent): void {
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const rect = canvas.getBoundingClientRect();
    const sx = e.clientX - rect.left;
    const sy = e.clientY - rect.top;
    lastMouse = [sx, sy];

    if (e.button === 2 || props.editor.activeTool.value === 'pan') {
        panning = true;
        canvas.setPointerCapture(e.pointerId);

        return;
    }

    if (e.button !== 0) {
        return;
    }

    const world = screenToWorld(sx, sy);
    const grid = worldToGrid(world[0], world[1]);

    if (!grid) {
        return;
    }

    const [gx, gy] = grid;

    if (props.editor.activeTool.value === 'fill') {
        props.editor.clickTool(gx, gy);
        scheduleDraw();

        return;
    }

    if (props.editor.activeTool.value === 'capital' || props.editor.activeTool.value === 'flag') {
        props.editor.placementClick(gx, gy);
        scheduleDraw();

        return;
    }

    painting = true;
    props.editor.beginStroke();
    props.editor.strokePaint(gx, gy);
    canvas.setPointerCapture(e.pointerId);
    scheduleDraw();
}

function onPointerMove(e: PointerEvent): void {
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const rect = canvas.getBoundingClientRect();
    const sx = e.clientX - rect.left;
    const sy = e.clientY - rect.top;

    if (panning) {
        props.editor.camX.value += (sx - lastMouse[0]) / props.editor.zoom.value;
        props.editor.camY.value += (sy - lastMouse[1]) / props.editor.zoom.value;
        lastMouse = [sx, sy];
        scheduleDraw();

        return;
    }

    if (!painting) {
        return;
    }

    const world = screenToWorld(sx, sy);
    const grid = worldToGrid(world[0], world[1]);

    if (grid) {
        props.editor.strokePaint(grid[0], grid[1]);
        scheduleDraw();
    }
}

function onPointerUp(e: PointerEvent): void {
    const canvas = canvasRef.value;

    if (canvas?.hasPointerCapture(e.pointerId)) {
        canvas.releasePointerCapture(e.pointerId);
    }

    if (painting) {
        props.editor.endStroke();
    }

    painting = false;
    panning = false;
}

function onWheel(e: WheelEvent): void {
    e.preventDefault();
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const rect = canvas.getBoundingClientRect();
    const sx = e.clientX - rect.left;
    const sy = e.clientY - rect.top;
    const oldZoom = props.editor.zoom.value;
    const deltaPx = wheelDeltaPx(e, rect.height);

    if (deltaPx === 0) {
        return;
    }

    const factor = Math.exp(-deltaPx * ZOOM_WHEEL_SENSITIVITY);
    const newZoom = Math.min(
        MAP_EDITOR_MAX_ZOOM,
        Math.max(MAP_EDITOR_MIN_ZOOM, oldZoom * factor),
    );

    if (newZoom === oldZoom) {
        return;
    }

    props.editor.zoom.value = newZoom;
    props.editor.camX.value += sx * (1 / newZoom - 1 / oldZoom);
    props.editor.camY.value += sy * (1 / newZoom - 1 / oldZoom);
    scheduleDraw();
}

function onKeyDown(e: KeyboardEvent): void {
    const target = e.target as Node | null;

    if (
        target instanceof HTMLInputElement
        || target instanceof HTMLTextAreaElement
        || target instanceof HTMLSelectElement
        || (target instanceof HTMLElement && target.isContentEditable)
    ) {
        return;
    }

    if ((e.ctrlKey || e.metaKey) && e.key === 'z') {
        e.preventDefault();

        if (e.shiftKey) {
            props.editor.redo();
        } else {
            props.editor.undo();
        }

        scheduleDraw();
    }

    if (e.key === '[') {
        e.preventDefault();
        props.editor.bumpBrush(-1);
    }

    if (e.key === ']') {
        e.preventDefault();
        props.editor.bumpBrush(1);
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeyDown);
    resizeObserver = new ResizeObserver(() => {
        scheduleDraw();
    });

    if (canvasRef.value) {
        resizeObserver.observe(canvasRef.value);
    }

    void nextTick(() => {
        applyFitToViewport();
        draw();
    });
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeyDown);
    resizeObserver?.disconnect();
    resizeObserver = null;
    cancelScheduledDraw();
});

watch(
    () => props.editor.mapViewNonce.value,
    () => {
        void nextTick(() => {
            applyFitToViewport();
            scheduleDraw();
        });
    },
    { flush: 'post' },
);

watch(
    () => [
        props.editor.terrainEpoch.value,
        props.editor.markersEpoch.value,
        props.editor.zoom.value,
        props.editor.camX.value,
        props.editor.camY.value,
    ],
    () => scheduleDraw(),
);
</script>

<template>
    <div class="relative h-full min-h-0 w-full min-w-0">
        <div
            v-if="placementHint"
            role="status"
            aria-live="polite"
            class="pointer-events-none absolute top-3 left-1/2 z-10 max-w-[min(36rem,calc(100%-2rem))] -translate-x-1/2 rounded-md border-2 border-foreground/40 bg-[#a8ad9a] px-4 py-2 text-center text-xs font-medium leading-snug text-foreground shadow-sm"
        >
            {{ placementHint }}
        </div>
        <canvas
            ref="canvasRef"
            class="h-full min-h-0 w-full min-w-0 cursor-crosshair touch-none rounded-lg border-2 border-foreground bg-wod-paper"
            @contextmenu.prevent
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
            @wheel="onWheel"
        />
        <div
            v-if="tileScale"
            class="pointer-events-none absolute bottom-3 left-3 rounded-md border-2 border-foreground bg-wod-paper/95 px-2.5 py-2 shadow-sm"
        >
            <div
                class="relative h-1.5 rounded-sm bg-foreground/15"
                :style="{ width: `${Math.ceil(tileScale.barWidthPx)}px` }"
            >
                <span class="absolute top-0 left-0 h-2.5 w-0.5 -translate-y-0.5 bg-foreground" />
                <span
                    class="absolute top-0 right-0 h-2.5 w-0.5 -translate-y-0.5 bg-foreground"
                />
            </div>
            <p class="mt-1.5 font-mono text-[11px] font-semibold leading-none text-foreground">
                {{ tileScale.label }}
            </p>
            <p class="mt-1 font-mono text-[10px] leading-none text-muted-foreground">
                {{ tileScale.visibleCols }}×{{ tileScale.visibleRows }} visible
            </p>
        </div>
    </div>
</template>
