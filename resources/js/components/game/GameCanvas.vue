<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useGameStore } from '@/stores/gameStore';

const canvasRef = ref<HTMLCanvasElement | null>(null);
const store = useGameStore();

const TERRAIN_COLORS: Record<string, string> = {
    water: '#4a90d9',
    plains: '#c8d68a',
    forest: '#3d6b45',
    hill: '#b0b0a8',
    mountain: '#5a5a5a',
};

const THRESHOLD = 0.5;
const TERRAIN_VALUES = {
    water: -0.1,
    plains: 0.1,
    hill: 0.7,
    mountain: 0.83,
};

let dragging = false;
let panning = false;
let lastMouse: [number, number] = [0, 0];
let terrainCanvas: HTMLCanvasElement | null = null;

function terrainName(value: number, forest: number): string {
    if (forest > THRESHOLD) {
        return 'forest';
    }

    const entries = Object.entries(TERRAIN_VALUES).reverse();
    for (const [name, threshold] of entries) {
        if (value > threshold) {
            return name;
        }
    }

    return 'plains';
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

    for (let y = 0; y < height; y += cellSize) {
        for (let x = 0; x < width; x += cellSize) {
            const gx = Math.min(store.terrain.length - 1, Math.floor(x / cellSize));
            const gy = Math.min(store.terrain[0].length - 1, Math.floor(y / cellSize));
            const tv = store.terrain[gx][gy];
            const fv = store.forest[gx][gy];
            ctx.fillStyle = TERRAIN_COLORS[terrainName(tv, fv)] ?? '#c8d68a';
            ctx.fillRect(x, y, cellSize, cellSize);
        }
    }
}

function worldToScreen(x: number, y: number): [number, number] {
    return [(x + store.camX) * store.zoom, (y + store.camY) * store.zoom];
}

function screenToWorld(x: number, y: number): [number, number] {
    return [x / store.zoom - store.camX, y / store.zoom - store.camY];
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

    ctx.fillStyle = '#e8dfc8';
    ctx.fillRect(0, 0, rect.width, rect.height);

    ctx.save();
    ctx.scale(store.zoom, store.zoom);
    ctx.translate(store.camX, store.camY);

    if (terrainCanvas) {
        ctx.drawImage(terrainCanvas, 0, 0);
    }

    const state = store.latestState;

    if (state) {
        drawFog(ctx, state.vision);
        drawBorders(ctx, state.border);
    }

    for (const city of state?.cities ?? store.cityPositions.map((p, i) => ({
        position: p,
        id: i,
        ownerColor: null,
        ownerSlot: null,
        path: [],
    }))) {
        drawCity(ctx, city.position, city.ownerColor);
    }

    for (const troop of state?.troops ?? []) {
        drawTroop(ctx, troop.position, troop.color, troop.health);
    }

    for (const draft of store.draftPaths) {
        drawArrowPath(ctx, draft.points);
    }

    if (store.activeDraft) {
        drawArrowPath(ctx, store.activeDraft.points, true);
    }

    ctx.restore();
}

function drawFog(ctx: CanvasRenderingContext2D, vision: number[][]) {
    const { cellSize } = store.world;
    ctx.fillStyle = 'rgba(20, 18, 14, 0.72)';

    for (let gy = 0; gy < vision[0].length - 1; gy++) {
        for (let gx = 0; gx < vision.length - 1; gx++) {
            const v = vision[gx][gy];
            if (v < THRESHOLD) {
                ctx.fillRect(gx * cellSize, gy * cellSize, cellSize, cellSize);
            }
        }
    }
}

function drawBorders(ctx: CanvasRenderingContext2D, border: number[][]) {
    const { cellSize } = store.world;
    ctx.fillStyle = 'rgba(241, 196, 15, 0.15)';

    for (let gy = 0; gy < border[0].length - 1; gy++) {
        for (let gx = 0; gx < border.length - 1; gx++) {
            if (border[gx][gy] > 0.35) {
                ctx.fillRect(gx * cellSize, gy * cellSize, cellSize, cellSize);
            }
        }
    }
}

function drawCity(ctx: CanvasRenderingContext2D, position: [number, number], color: number[] | null) {
    const [x, y] = position;
    ctx.fillStyle = color ? rgb(color) : '#f1c40f';
    ctx.beginPath();
    for (let i = 0; i < 5; i++) {
        const angle = (Math.PI * 2 * i) / 5 - Math.PI / 2;
        const px = x + Math.cos(angle) * 8;
        const py = y + Math.sin(angle) * 8;
        if (i === 0) {
            ctx.moveTo(px, py);
        } else {
            ctx.lineTo(px, py);
        }
    }
    ctx.closePath();
    ctx.fill();
    ctx.strokeStyle = '#1a1a1a';
    ctx.lineWidth = 1;
    ctx.stroke();
}

function drawTroop(ctx: CanvasRenderingContext2D, position: [number, number], color: number[], health: number) {
    const [x, y] = position;
    ctx.fillStyle = rgb(color);
    ctx.beginPath();
    ctx.arc(x, y, 5, 0, Math.PI * 2);
    ctx.fill();
    ctx.strokeStyle = '#1a1a1a';
    ctx.lineWidth = 1;
    ctx.stroke();

    if (health < 100) {
        ctx.fillStyle = '#1a1a1a';
        ctx.fillRect(x - 8, y - 12, 16, 3);
        ctx.fillStyle = '#27ae60';
        ctx.fillRect(x - 8, y - 12, (16 * health) / 100, 3);
    }
}

function drawArrowPath(ctx: CanvasRenderingContext2D, points: [number, number][], dashed = false) {
    if (points.length < 2) {
        return;
    }

    ctx.strokeStyle = '#1a1a1a';
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

    for (const troop of state.troops) {
        if (troop.ownerSlot !== store.slot) {
            continue;
        }
        const dx = troop.position[0] - world[0];
        const dy = troop.position[1] - world[1];
        if (Math.hypot(dx, dy) < 12) {
            return { id: troop.id, kind: 'troop' };
        }
    }

    for (const city of state.cities) {
        if (city.ownerSlot !== store.slot) {
            continue;
        }
        const dx = city.position[0] - world[0];
        const dy = city.position[1] - world[1];
        if (Math.hypot(dx, dy) < 14) {
            return { id: city.id, kind: 'city' };
        }
    }

    return null;
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

    const world = screenToWorld(sx, sy);
    const entity = findEntity(world);
    if (entity) {
        dragging = true;
        store.beginPath(entity.id, entity.kind, world);
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
        store.camX += (sx - lastMouse[0]) / store.zoom;
        store.camY += (sy - lastMouse[1]) / store.zoom;
        lastMouse = [sx, sy];
        draw();
        return;
    }

    if (dragging) {
        store.extendPath(screenToWorld(sx, sy));
        draw();
    }
}

function onMouseUp() {
    if (dragging) {
        store.finishPath();
        dragging = false;
        draw();
    }
    panning = false;
}

function onWheel(e: WheelEvent) {
    e.preventDefault();
    const factor = e.deltaY > 0 ? 0.9 : 1.1;
    store.zoom = Math.min(3, Math.max(0.4, store.zoom * factor));
    draw();
}

function onKeyDown(e: KeyboardEvent) {
    if (e.code === 'Space') {
        e.preventDefault();
        store.submitOrders(store.gameUuid);
    }
    if (e.key.toLowerCase() === 'c') {
        store.clearDrafts();
        draw();
    }
    if (e.key.toLowerCase() === 'p') {
        store.togglePause(store.gameUuid);
    }
}

onMounted(() => {
    terrainCanvas = document.createElement('canvas');
    window.addEventListener('keydown', onKeyDown);
    draw();
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeyDown);
});

watch(
    () => [store.terrain, store.forest, store.latestState, store.draftPaths, store.activeDraft],
    () => {
        bakeTerrain();
        draw();
    },
    { deep: true },
);
</script>

<template>
    <canvas
        ref="canvasRef"
        class="h-full w-full cursor-crosshair touch-none"
        @contextmenu.prevent
        @mousedown="onMouseDown"
        @mousemove="onMouseMove"
        @mouseup="onMouseUp"
        @mouseleave="onMouseUp"
        @wheel="onWheel"
    />
</template>
