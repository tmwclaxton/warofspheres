<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- editor exposes mutable refs shared by map builder */
import type { MapEditorInstance } from '@/composables/useMapEditor';
import type { TerrainId } from '@/lib/terrainCatalog';
import { cn } from '@/lib/utils';

export type TerrainTypeRow = {
    id: string;
    label: string;
    color: string;
    isWater: boolean;
};

const props = defineProps<{
    editor: MapEditorInstance;
    terrainTypes: TerrainTypeRow[];
}>();

function selectTerrain(id: string): void {
    if (!props.terrainTypes.some((t) => t.id === id)) {
        return;
    }

    props.editor.selectedTerrain.value = id as TerrainId;

    if (
        props.editor.activeTool.value === 'pan'
        || props.editor.activeTool.value === 'capital'
        || props.editor.activeTool.value === 'flag'
        || props.editor.activeTool.value === 'infantry'
        || props.editor.activeTool.value === 'tank'
    ) {
        props.editor.activeTool.value = 'brush';
    }
}
</script>

<template>
    <div
        class="wod-panel flex min-w-0 shrink-0 flex-col gap-2 rounded-lg border-2 border-foreground p-2.5 sm:p-3"
    >
        <p class="font-display text-xs font-bold uppercase tracking-wide text-foreground sm:text-sm">
            Terrain
        </p>
        <div
            class="flex max-w-full min-w-0 flex-nowrap gap-2 overflow-x-auto pb-0.5 [-ms-overflow-style:none] [scrollbar-width:thin] [&::-webkit-scrollbar]:h-1.5"
        >
            <button
                v-for="t in terrainTypes"
                :key="t.id"
                type="button"
                :class="
                    cn(
                        'flex min-w-[4.75rem] shrink-0 flex-col items-center gap-1 rounded-md border-2 px-1.5 py-2 text-xs font-semibold leading-snug text-foreground transition-shadow sm:min-w-[5.5rem] sm:gap-1.5 sm:px-2 sm:py-2.5 sm:text-sm',
                        editor.selectedTerrain.value === t.id
                            ? 'border-foreground ring-2 ring-foreground/25'
                            : 'border-transparent hover:border-muted-foreground/50',
                    )
                "
                :title="t.label"
                @click="selectTerrain(t.id)"
            >
                <span
                    class="size-8 shrink-0 rounded-md border-2 border-foreground/35 shadow-sm sm:size-9"
                    :style="{ backgroundColor: t.color }"
                    aria-hidden="true"
                />
                <span class="max-w-[7rem] text-center text-pretty leading-snug">{{ t.label }}</span>
            </button>
        </div>
    </div>
</template>
