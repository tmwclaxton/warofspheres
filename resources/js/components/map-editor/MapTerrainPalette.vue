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
    ) {
        props.editor.activeTool.value = 'brush';
    }
}
</script>

<template>
    <div
        class="wod-panel flex shrink-0 flex-col gap-2 rounded-lg border-2 border-foreground p-3"
    >
        <p class="font-display text-xs font-bold uppercase tracking-wide text-muted-foreground">
            Terrain
        </p>
        <div class="grid grid-cols-6 gap-2 sm:grid-cols-12">
            <button
                v-for="t in terrainTypes"
                :key="t.id"
                type="button"
                :class="
                    cn(
                        'flex flex-col items-center gap-1 rounded-md border-2 p-1.5 text-[10px] font-medium transition-shadow',
                        editor.selectedTerrain.value === t.id
                            ? 'border-foreground ring-2 ring-foreground/20'
                            : 'border-transparent hover:border-muted-foreground/40',
                    )
                "
                :title="t.label"
                @click="selectTerrain(t.id)"
            >
                <span
                    class="size-7 shrink-0 rounded border border-foreground/30 shadow-sm"
                    :style="{ backgroundColor: t.color }"
                    aria-hidden="true"
                />
                <span class="max-w-full truncate text-muted-foreground">{{ t.label }}</span>
            </button>
        </div>
    </div>
</template>
