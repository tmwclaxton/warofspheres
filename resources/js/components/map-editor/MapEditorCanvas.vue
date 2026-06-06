<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { MAP_EDITOR_MAX_ZOOM, MAP_EDITOR_MIN_ZOOM, type MapEditorInstance } from '@/composables/useMapEditor';
import { drawBridgeOverlay, editorBlendedTerrainFillStyle } from '@/lib/terrainRender';

const props = defineProps<{
    editor: MapEditorInstance;
}>();

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
    const bridges = props.editor.bridges.value;
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
            ctx.fillRect(px, py, cs, cs);
            if (bridges[gx][gy]) {
                drawBridgeOverlay(ctx, px, py, cs);
            }
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

    if (props.editor.activeTool.value === 'fill' || props.editor.activeTool.value === 'bridge') {
        props.editor.clickTool(gx, gy);
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
    const factor = e.deltaY > 0 ? 0.92 : 1.08;
    props.editor.zoom.value = Math.min(
        MAP_EDITOR_MAX_ZOOM,
        Math.max(MAP_EDITOR_MIN_ZOOM, props.editor.zoom.value * factor),
    );
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
        props.editor.zoom.value,
        props.editor.camX.value,
        props.editor.camY.value,
    ],
    () => scheduleDraw(),
);
</script>

<template>
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
</template>
