<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue';
import type { MapDataPayload } from '@/lib/mapEditorGrid';
import { EDITOR_TERRAIN_COLORS, isTerrainId } from '@/lib/terrainCatalog';

const props = defineProps<{
    data: MapDataPayload;
}>();

const canvasRef = ref<HTMLCanvasElement | null>(null);
let ro: ResizeObserver | null = null;

function paint(): void {
    const el = canvasRef.value;

    if (!el) {
        return;
    }

    const cells = props.data.cells;
    /** Row count (first index); canvas X matches editor `gx`. */
    const rows = cells.length;
    /** Col count (second index); canvas Y matches editor `gy`. */
    const cols = cells[0]?.length ?? 0;

    if (rows === 0 || cols === 0) {
        return;
    }

    const maxW = 320;
    const maxH = 180;
    const cs = Math.max(1, Math.min(Math.floor(maxW / rows), Math.floor(maxH / cols), 10));
    el.width = cs * rows;
    el.height = cs * cols;
    const ctx = el.getContext('2d');

    if (!ctx) {
        return;
    }

    for (let gx = 0; gx < rows; gx++) {
        for (let gy = 0; gy < cols; gy++) {
            const t = cells[gx]?.[gy];
            ctx.fillStyle = typeof t === 'string' && isTerrainId(t) ? EDITOR_TERRAIN_COLORS[t] : '#888888';
            ctx.fillRect(gx * cs, gy * cs, cs, cs);
        }
    }

    const markers = props.data.markers ?? [];

    for (const m of markers) {
        if (m.row < 0 || m.col < 0 || m.row >= rows || m.col >= cols) {
            continue;
        }

        const cx = (m.row + 0.5) * cs;
        const cy = (m.col + 0.5) * cs;
        const rad = Math.max(1.5, cs * 0.28);
        ctx.beginPath();
        ctx.fillStyle =
            m.type === 'capital' ? '#b91c1c' : m.type === 'flag' ? '#ca8a04' : '#4b5563';
        ctx.arc(cx, cy, rad, 0, Math.PI * 2);
        ctx.fill();
    }
}

onMounted(() => {
    paint();
    ro = new ResizeObserver(() => paint());
    const el = canvasRef.value?.parentElement;

    if (el) {
        ro.observe(el);
    }
});

onUnmounted(() => {
    ro?.disconnect();
});

watch(
    () => props.data,
    () => paint(),
    { deep: true },
);
</script>

<template>
    <div class="relative w-full overflow-hidden rounded-md border-2 border-foreground/20 bg-muted/30">
        <canvas
            ref="canvasRef"
            class="mx-auto block h-auto max-h-48 w-auto max-w-full object-contain [image-rendering:pixelated]"
            width="1"
            height="1"
            aria-hidden="true"
        />
    </div>
</template>
