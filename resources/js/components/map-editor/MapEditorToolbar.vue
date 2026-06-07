<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- editor exposes mutable refs shared by map builder */
import {
    Circle,
    Eraser,
    Flag,
    Hand,
    Landmark,
    PaintBucket,
    Paintbrush,
    RectangleHorizontal,
} from 'lucide-vue-next';
import type { LucideIcon } from 'lucide-vue-next';
import { watch } from 'vue';
import type { MapEditorInstance, MapEditorTool } from '@/composables/useMapEditor';
import { cn } from '@/lib/utils';

const props = defineProps<{
    editor: MapEditorInstance;
    readOnly?: boolean;
}>();

watch(
    () => props.readOnly,
    (ro) => {
        if (ro) {
            props.editor.activeTool.value = 'pan';
        }
    },
    { immediate: true },
);

const tools: { id: MapEditorTool; label: string; icon: LucideIcon }[] = [
    { id: 'brush', label: 'Brush', icon: Paintbrush },
    { id: 'eraser', label: 'Eraser', icon: Eraser },
    { id: 'fill', label: 'Fill', icon: PaintBucket },
    { id: 'capital', label: 'Capital', icon: Landmark },
    { id: 'flag', label: 'Flag', icon: Flag },
    { id: 'infantry', label: 'Infantry spawn', icon: Circle },
    { id: 'tank', label: 'Tank spawn', icon: RectangleHorizontal },
    { id: 'pan', label: 'Pan', icon: Hand },
];

function setTool(id: MapEditorTool): void {
    if (props.readOnly && id !== 'pan') {
        return;
    }

    props.editor.activeTool.value = id;
}
</script>

<template>
    <div
        class="flex w-fit shrink-0 flex-col items-center gap-1 wod-surface px-1.5 py-1.5"
        role="toolbar"
        aria-label="Map tools"
    >
        <button
            v-for="tool in tools"
            :key="tool.id"
            type="button"
            :disabled="readOnly && tool.id !== 'pan'"
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
    </div>
</template>
