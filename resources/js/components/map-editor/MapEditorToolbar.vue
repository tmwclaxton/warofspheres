<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- editor exposes mutable refs shared by map builder */
import {
    Eraser,
    Flag,
    Hand,
    Landmark,
    Minus,
    PaintBucket,
    Paintbrush,
    Plus,
} from 'lucide-vue-next';
import type { LucideIcon } from 'lucide-vue-next';
import { computed } from 'vue';
import { MAP_EDITOR_BRUSH_SIZES } from '@/composables/useMapEditor';
import type { MapEditorBrushSize, MapEditorInstance, MapEditorTool } from '@/composables/useMapEditor';
import { cn } from '@/lib/utils';

const props = defineProps<{
    editor: MapEditorInstance;
}>();

const tools: { id: MapEditorTool; label: string; icon: LucideIcon }[] = [
    { id: 'brush', label: 'Brush', icon: Paintbrush },
    { id: 'eraser', label: 'Eraser', icon: Eraser },
    { id: 'fill', label: 'Fill', icon: PaintBucket },
    { id: 'capital', label: 'Capital', icon: Landmark },
    { id: 'flag', label: 'Flag', icon: Flag },
    { id: 'pan', label: 'Pan', icon: Hand },
];

const usesBrushSize = computed(
    () =>
        props.editor.activeTool.value === 'brush' || props.editor.activeTool.value === 'eraser',
);

const brushSizeIndex = computed(() => {
    const radius = props.editor.brushRadius.value as MapEditorBrushSize;

    return MAP_EDITOR_BRUSH_SIZES.indexOf(radius);
});

const canShrinkBrush = computed(() => brushSizeIndex.value > 0);
const canGrowBrush = computed(
    () => brushSizeIndex.value >= 0 && brushSizeIndex.value < MAP_EDITOR_BRUSH_SIZES.length - 1,
);

function setTool(id: MapEditorTool): void {
    props.editor.activeTool.value = id;
}

function selectBrushSize(size: MapEditorBrushSize): void {
    props.editor.setBrushRadius(size);
}
</script>

<template>
    <div
        class="flex w-14 shrink-0 flex-col gap-1 rounded-lg border-2 border-foreground bg-wod-paper p-1 shadow-sm"
        role="toolbar"
        aria-label="Map tools"
    >
        <button
            v-for="tool in tools"
            :key="tool.id"
            type="button"
            :class="
                cn(
                    'flex size-9 items-center justify-center rounded-md border border-transparent text-foreground transition-colors hover:bg-muted/80',
                    props.editor.activeTool.value === tool.id &&
                        'border-foreground bg-muted shadow-inner',
                )
            "
            :aria-pressed="props.editor.activeTool.value === tool.id"
            :aria-label="tool.label"
            :title="tool.label"
            @click="setTool(tool.id)"
        >
            <component :is="tool.icon" class="size-4" stroke-width="2" />
        </button>

        <template v-if="usesBrushSize">
            <div class="my-0.5 h-px bg-foreground/15" aria-hidden="true" />
            <p
                class="px-0.5 text-center text-[9px] font-bold uppercase tracking-wide text-muted-foreground"
            >
                Size
            </p>
            <div class="flex flex-col items-center gap-0.5" role="group" aria-label="Brush size">
                <button
                    type="button"
                    class="flex size-7 items-center justify-center rounded-md border border-transparent text-foreground transition-colors hover:bg-muted/80 disabled:pointer-events-none disabled:opacity-35"
                    :disabled="!canGrowBrush"
                    aria-label="Increase brush size"
                    title="Increase brush size"
                    @click="editor.bumpBrush(1)"
                >
                    <Plus class="size-3.5" stroke-width="2.5" />
                </button>
                <span
                    class="flex size-9 flex-col items-center justify-center gap-1 rounded-md border border-foreground/15 bg-muted/40 font-mono text-xs font-semibold tabular-nums"
                    :title="`Brush radius: ${editor.brushRadius} tiles`"
                >
                    <span
                        class="rounded-full border border-foreground/50 bg-foreground/20"
                        :style="{
                            width: `${6 + editor.brushRadius * 3}px`,
                            height: `${6 + editor.brushRadius * 3}px`,
                        }"
                        aria-hidden="true"
                    />
                    {{ editor.brushRadius }}
                </span>
                <button
                    type="button"
                    class="flex size-7 items-center justify-center rounded-md border border-transparent text-foreground transition-colors hover:bg-muted/80 disabled:pointer-events-none disabled:opacity-35"
                    :disabled="!canShrinkBrush"
                    aria-label="Decrease brush size"
                    title="Decrease brush size"
                    @click="editor.bumpBrush(-1)"
                >
                    <Minus class="size-3.5" stroke-width="2.5" />
                </button>
            </div>
            <div class="flex flex-col gap-0.5 px-0.5" role="group" aria-label="Brush size presets">
                <button
                    v-for="size in MAP_EDITOR_BRUSH_SIZES"
                    :key="size"
                    type="button"
                    :class="
                        cn(
                            'rounded-md border px-1 py-0.5 font-mono text-[10px] font-semibold tabular-nums transition-colors',
                            editor.brushRadius === size
                                ? 'border-foreground bg-muted shadow-inner'
                                : 'border-transparent text-muted-foreground hover:border-muted-foreground/30 hover:bg-muted/50 hover:text-foreground',
                        )
                    "
                    :aria-pressed="editor.brushRadius === size"
                    :aria-label="`Brush size ${size}`"
                    @click="selectBrushSize(size)"
                >
                    {{ size }}
                </button>
            </div>
        </template>
    </div>
</template>
