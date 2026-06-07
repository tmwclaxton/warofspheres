<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import AppModal from '@/components/AppModal.vue';
import MapEditorCanvas from '@/components/map-editor/MapEditorCanvas.vue';
import MapEditorToolbar from '@/components/map-editor/MapEditorToolbar.vue';
import MapGenerateDialog from '@/components/map-editor/MapGenerateDialog.vue';
import MapListPanel from '@/components/map-editor/MapListPanel.vue';
import type { MapSummary } from '@/components/map-editor/MapListPanel.vue';
import MapTerrainPalette from '@/components/map-editor/MapTerrainPalette.vue';
import type { TerrainTypeRow } from '@/components/map-editor/MapTerrainPalette.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { MapDataPayload } from '@/composables/useMapEditor';
import { useMapEditor } from '@/composables/useMapEditor';
import type { MapGenerationType } from '@/lib/generateRandomMap';
import {
    DEFAULT_MAP_CELL_COLS,
    DEFAULT_MAP_CELL_ROWS,
    MAP_GRID_MAX_CELL_COLS,
    MAP_GRID_MAX_CELL_ROWS,
    MAP_GRID_MIN_CELL_COLS,
    MAP_GRID_MIN_CELL_ROWS,
    isAllowedMapGridSize,
} from '@/lib/mapEditorGrid';
import { Redo2, Save, Sparkles, Undo2 } from 'lucide-vue-next';

const props = defineProps<{
    maps: MapSummary[];
    terrainTypes: TerrainTypeRow[];
    defaults: MapDataPayload;
}>();

const editor = useMapEditor(props.defaults);
const zoomPercent = computed(() => Math.round(editor.zoom.value * 100));
const editorDirty = computed(() => editor.dirty.value);
const saving = ref(false);
const saveError = ref<string | null>(null);
const generateDialogOpen = ref(false);
const newMapDialogOpen = ref(false);
const newMapRows = ref(DEFAULT_MAP_CELL_ROWS);
const newMapCols = ref(DEFAULT_MAP_CELL_COLS);
const newMapFormError = ref<string | null>(null);
const newMapRowsInputRef = ref<HTMLInputElement | null>(null);

function onRequestNewMap(): void {
    openNewMapSizeDialog();
}

function openNewMapSizeDialog(): void {
    newMapFormError.value = null;
    newMapRows.value = editor.gridRows.value;
    newMapCols.value = editor.gridCols.value;
    newMapDialogOpen.value = true;
}

watch(newMapDialogOpen, async (open) => {
    if (!open) {
        return;
    }
    await nextTick();
    newMapRowsInputRef.value?.focus();
    newMapRowsInputRef.value?.select();
});

function submitNewMapDialog(): void {
    newMapFormError.value = null;
    const rows = Math.round(Number(newMapRows.value));
    const cols = Math.round(Number(newMapCols.value));
    if (!Number.isFinite(rows) || !Number.isFinite(cols)) {
        newMapFormError.value = 'Enter valid numbers for rows and columns.';

        return;
    }
    if (!isAllowedMapGridSize(rows, cols)) {
        newMapFormError.value = `Use ${MAP_GRID_MIN_CELL_ROWS}–${MAP_GRID_MAX_CELL_ROWS} rows and ${MAP_GRID_MIN_CELL_COLS}–${MAP_GRID_MAX_CELL_COLS} columns.`;

        return;
    }
    editor.newMapWithSize(rows, cols);
    newMapDialogOpen.value = false;
}

function openGenerateDialog(): void {
    generateDialogOpen.value = true;
}

function onGenerateMap(payload: { seed?: number; type: MapGenerationType }): void {
    editor.generateAndApplyMap(payload.seed, payload.type);
    if (editor.mapName.value === 'Untitled map') {
        editor.mapName.value = 'Generated map';
    }
}

async function onSave(): Promise<void> {
    saveError.value = null;
    saving.value = true;
    try {
        await editor.saveMap();
    } catch {
        saveError.value = 'Save failed. Check your connection and try again.';
    } finally {
        saving.value = false;
    }
}

function onBeforeUnload(e: BeforeUnloadEvent): void {
    if (editor.dirty.value) {
        e.preventDefault();
        e.returnValue = '';
    }
}

onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);
});

onUnmounted(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
});
</script>

<template>
    <Head title="Map Builder" />

    <div class="flex h-full min-h-0 flex-1 flex-col gap-2 overflow-hidden">
        <div
            class="flex flex-wrap items-center gap-2 rounded-lg border-2 border-foreground bg-wod-paper px-3 py-2 shadow-sm"
        >
            <label class="sr-only" for="map-builder-name">Map name</label>
            <Input
                id="map-builder-name"
                v-model="editor.mapName"
                class="h-9 w-48 max-w-full border-2 border-foreground md:w-64"
                maxlength="120"
                placeholder="Map name"
                autocomplete="off"
            />
            <span
                v-if="editor.dirty"
                class="text-xs font-medium text-amber-700 dark:text-amber-400"
            >
                Unsaved changes
            </span>
            <span class="text-xs text-muted-foreground">
                Zoom {{ zoomPercent }}%
            </span>
            <Button
                type="button"
                size="sm"
                variant="ghost"
                class="h-8 px-2 text-xs text-muted-foreground"
                title="Zoom to fit the whole map in the canvas"
                @click="editor.requestMapViewFit()"
            >
                Fit view
            </Button>
            <Button
                type="button"
                size="sm"
                variant="outline"
                class="h-8 gap-1 px-2 text-xs"
                title="Replace the map with procedurally generated terrain"
                @click="openGenerateDialog"
            >
                <Sparkles class="size-3.5" />
                Generate
            </Button>
            <Button
                type="button"
                size="sm"
                variant="outline"
                class="h-8 gap-1 px-2 text-xs"
                title="Change vertex grid size (rows × columns)"
                @click="openNewMapSizeDialog"
            >
                Grid {{ editor.gridRows }}×{{ editor.gridCols }}
            </Button>
            <span class="hidden text-xs text-muted-foreground sm:inline">
                [ / ] brush size · right-drag pan
            </span>
            <div class="flex flex-1 flex-wrap items-center justify-end gap-2">
                <p v-if="saveError" class="w-full text-right text-xs text-destructive sm:w-auto">
                    {{ saveError }}
                </p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="!editor.canUndo"
                    class="gap-1"
                    @click="editor.undo()"
                >
                    <Undo2 class="size-3.5" />
                    Undo
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="!editor.canRedo"
                    class="gap-1"
                    @click="editor.redo()"
                >
                    <Redo2 class="size-3.5" />
                    Redo
                </Button>
                <Button
                    type="button"
                    size="sm"
                    class="gap-1"
                    :disabled="saving"
                    @click="onSave"
                >
                    <Save class="size-3.5" />
                    {{ saving ? 'Saving…' : 'Save' }}
                </Button>
            </div>
        </div>

        <div
            class="flex min-h-0 flex-1 gap-2 overflow-hidden min-h-[clamp(18rem,52svh,56rem)]"
        >
            <MapListPanel :editor="editor" :maps="maps" @request-new-map="onRequestNewMap" />
            <MapEditorToolbar :editor="editor" />
            <MapEditorCanvas :editor="editor" class="min-h-0 min-w-0 flex-1" />
        </div>

        <MapTerrainPalette :editor="editor" :terrain-types="terrainTypes" />

        <MapGenerateDialog
            v-model:open="generateDialogOpen"
            :dirty="editorDirty"
            @generate="onGenerateMap"
        />

        <AppModal
            v-model:open="newMapDialogOpen"
            title="New map size"
            :description="`Width and height are vertex counts (cells). Allowed ranges: ${MAP_GRID_MIN_CELL_ROWS}–${MAP_GRID_MAX_CELL_ROWS} rows and ${MAP_GRID_MIN_CELL_COLS}–${MAP_GRID_MAX_CELL_COLS} columns.`"
        >
            <div class="space-y-3" @keydown.stop>
                <p
                    v-if="editorDirty"
                    class="rounded-md border border-amber-300/80 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-950/40 dark:text-amber-100"
                >
                    You have unsaved changes. Creating a new map will discard them.
                </p>
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold" for="new-map-rows">Rows</label>
                        <input
                            id="new-map-rows"
                            ref="newMapRowsInputRef"
                            v-model.number="newMapRows"
                            type="number"
                            :min="MAP_GRID_MIN_CELL_ROWS"
                            :max="MAP_GRID_MAX_CELL_ROWS"
                            class="wod-field h-9 w-28 rounded-md border-2 border-foreground px-2 font-mono text-sm text-foreground md:text-sm"
                            autocomplete="off"
                        />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold" for="new-map-cols">Columns</label>
                        <input
                            id="new-map-cols"
                            v-model.number="newMapCols"
                            type="number"
                            :min="MAP_GRID_MIN_CELL_COLS"
                            :max="MAP_GRID_MAX_CELL_COLS"
                            class="wod-field h-9 w-28 rounded-md border-2 border-foreground px-2 font-mono text-sm text-foreground md:text-sm"
                            autocomplete="off"
                        />
                    </div>
                </div>
                <p v-if="newMapFormError" class="text-sm text-destructive">{{ newMapFormError }}</p>
            </div>
            <template #footer>
                <Button type="button" variant="outline" @click="newMapDialogOpen = false">
                    Cancel
                </Button>
                <Button type="button" @click="submitNewMapDialog">Create empty map</Button>
            </template>
        </AppModal>
    </div>
</template>
