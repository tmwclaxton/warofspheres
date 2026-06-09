<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Circle,
    Copy,
    Flag,
    Landmark,
    Loader2,
    Lock,
    Monitor,
    RectangleHorizontal,
    Redo2,
    Save,
    Sparkles,
    Undo2,
    Upload,
} from 'lucide-vue-next';
import { useMediaQuery } from '@vueuse/core';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import AppModal from '@/components/AppModal.vue';
import MapEditorCanvas from '@/components/map-editor/MapEditorCanvas.vue';
import MapEditorToolbar from '@/components/map-editor/MapEditorToolbar.vue';
import MapGenerateDialog from '@/components/map-editor/MapGenerateDialog.vue';
import MapListPanel from '@/components/map-editor/MapListPanel.vue';
import type { MapSummary } from '@/components/map-editor/MapListPanel.vue';
import MapTeamPalette from '@/components/map-editor/MapTeamPalette.vue';
import type { TeamColorRow } from '@/components/map-editor/MapTeamPalette.vue';
import MapTerrainPalette from '@/components/map-editor/MapTerrainPalette.vue';
import type { TerrainTypeRow } from '@/components/map-editor/MapTerrainPalette.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { MapDataPayload, MapEditorTool } from '@/composables/useMapEditor';
import { useMapEditor } from '@/composables/useMapEditor';
import type { MapGenerationType } from '@/lib/generateRandomMap';
import { runProceduralMapGeneration } from '@/lib/runProceduralMapGeneration';
import {
    DEFAULT_MAP_CELL_COLS,
    DEFAULT_MAP_CELL_ROWS,
    MAP_GRID_MAX_CELL_COLS,
    MAP_GRID_MAX_CELL_ROWS,
    MAP_GRID_MIN_CELL_COLS,
    MAP_GRID_MIN_CELL_ROWS,
    isAllowedMapGridSize,
} from '@/lib/mapEditorGrid';
import { mapBuilder } from '@/routes';
import { explore as mapsExplore, fork as forkMap, publish as publishMap } from '@/routes/maps';
import { useToastStore } from '@/stores/toastStore';

export type MapBuilderInitialDocument = {
    uuid: string;
    name: string;
    data: MapDataPayload;
    published?: boolean;
};

const props = defineProps<{
    maps: MapSummary[];
    terrainTypes: TerrainTypeRow[];
    teamColors: TeamColorRow[];
    defaults: MapDataPayload;
    initialDocument: MapBuilderInitialDocument | null;
}>();

const teamCountOptions = [2, 3, 4, 5, 6] as const;

const editor = useMapEditor(props.defaults);
const toast = useToastStore();
const page = usePage();
const isLargeScreen = useMediaQuery('(min-width: 1024px)');

const mapGenerationPending = ref(false);

const mapsList = ref<MapSummary[]>([...props.maps]);

const mapPublished = ref(props.initialDocument?.published ?? false);

watch(
    () => props.initialDocument,
    (doc) => {
        mapPublished.value = doc?.published ?? false;
    },
    { deep: true },
);

const editorLocked = computed(() => mapPublished.value);

const publishedMapExploreUrl = computed(() => {
    const uuid = editor.currentUuid.value;

    if (!uuid || !mapPublished.value) {
        return mapsExplore().url;
    }

    return mapsExplore.url({ query: { uuid } });
});

const hasSavedMap = computed(() => editor.currentUuid.value !== null);

function getCookie(name: string): string {
    const match = document.cookie.match(new RegExp(`(^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[2] ?? '') : '';
}

function csrfToken(): string {
    return (
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ??
        getCookie('XSRF-TOKEN')
    );
}

async function jsonPost(url: string, body: Record<string, unknown> = {}): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': decodeURIComponent(getCookie('XSRF-TOKEN')),
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body),
    });
}

watch(
    () => props.maps,
    (m) => {
        mapsList.value = m.map((row) => ({ ...row }));
    },
    { deep: true },
);

const auth = computed(() => page.props.auth as { user?: object } | undefined);
const allowLibraryMutations = computed(() => Boolean(auth.value?.user));

const AUTO_SAVE_DEBOUNCE_MS = 3500;

const autoSaving = ref(false);
let autoSaveTimer: ReturnType<typeof setTimeout> | null = null;

function currentPathname(): string {
    return new URL(
        page.url,
        typeof window !== 'undefined' ? window.location.origin : 'http://localhost',
    ).pathname;
}

function pathMatchesMapSlug(uuid: string | null): boolean {
    const path = currentPathname();

    if (uuid === null) {
        return path === '/map-builder';
    }

    return path === `/map-builder/${uuid}`;
}

function visitMapBuilder(uuid: string | null, options: { replace?: boolean } = {}): void {
    const url = uuid ? mapBuilder.url(uuid) : mapBuilder.url();

    router.visit(url, {
        preserveState: true,
        preserveScroll: true,
        replace: options.replace ?? false,
    });
}

function syncSlugToCurrentMapIfNeeded(options: { replace?: boolean } = {}): void {
    const uuid = editor.currentUuid.value;

    if (!uuid || pathMatchesMapSlug(uuid)) {
        return;
    }

    visitMapBuilder(uuid, { replace: options.replace ?? true });
}

watch(
    () => props.initialDocument?.uuid ?? null,
    (uuid, prevUuid) => {
        const doc = props.initialDocument;

        if (doc?.uuid) {
            editor.loadFromPayload(doc.data, doc.name, doc.uuid);
            mapPublished.value = doc.published ?? false;

            return;
        }

        if (prevUuid != null && !uuid) {
            editor.newMap();
        }
    },
    { immediate: true },
);

watch(
    () => [editor.currentUuid.value, page.url, props.initialDocument?.uuid ?? null] as const,
    ([uuid, , slugProp]) => {
        if (uuid !== null) {
            return;
        }

        const path = currentPathname();

        if (!path.startsWith('/map-builder/') || path === '/map-builder') {
            return;
        }

        const match = path.match(/^\/map-builder\/([^/]+)$/);

        if (match?.[1] && slugProp && match[1] === slugProp) {
            return;
        }

        visitMapBuilder(null, { replace: true });
    },
);

function onOpenMapFromList(uuid: string): void {
    if (
        editor.currentUuid.value === uuid
        && !editor.dirty.value
        && pathMatchesMapSlug(uuid)
    ) {
        return;
    }

    visitMapBuilder(uuid);
}

/** Native &lt;select&gt; needs a primitive; nested refs on `editor` are not auto-unwrapped in templates. */
const headerTeamCount = computed(() => editor.teamCount.value);
const zoomPercent = computed(() => Math.round(editor.zoom.value * 100));
const editorDirty = computed(() => editor.dirty.value);
const saving = ref(false);

async function runAutoSave(): Promise<void> {
    if (editorLocked.value) {
        return;
    }

    if (!editor.dirty.value || saving.value || autoSaving.value) {
        return;
    }

    autoSaving.value = true;

    try {
        const updated = await editor.saveMap();

        if (updated) {
            mapsList.value = updated;
        }

        syncSlugToCurrentMapIfNeeded({ replace: true });
    } catch (err) {
        const message =
            err instanceof Error
                ? err.message
                : 'Autosave failed. Check your connection and try again.';
        toast.error(message, message.includes('\n') ? 16_000 : 10_000);
    } finally {
        autoSaving.value = false;
    }
}

watch(
    () => editor.dirty.value,
    (dirty) => {
        if (!dirty || editorLocked.value) {
            return;
        }

        if (autoSaveTimer !== null) {
            clearTimeout(autoSaveTimer);
        }

        autoSaveTimer = setTimeout(() => {
            autoSaveTimer = null;
            void runAutoSave();
        }, AUTO_SAVE_DEBOUNCE_MS);
    },
);

const generateDialogOpen = ref(false);
const newMapDialogOpen = ref(false);
const newMapRows = ref(DEFAULT_MAP_CELL_ROWS);
const newMapCols = ref(DEFAULT_MAP_CELL_COLS);
const newMapFormError = ref<string | null>(null);
const newMapRowsInputRef = ref<HTMLInputElement | null>(null);

const teamMarkerDialogOpen = ref(false);
const teamMarkerDialogSlot = ref(0);

function factionRowForTeamIndex(teamIndex: number): TeamColorRow | undefined {
    const ps = editor.teamPaletteSlots.value[teamIndex] ?? teamIndex;

    return props.teamColors.find((c) => c.slot === ps);
}

const teamMarkerDialogLabel = computed(() => {
    const ti = teamMarkerDialogSlot.value;

    return factionRowForTeamIndex(ti)?.label ?? `Team ${ti + 1}`;
});

const teamMarkerDialogDescription = computed(
    () =>
        `Capitals, flags, and troop spawns are placed with the marker tools in the toolbar. You chose ${teamMarkerDialogLabel.value} - pick a tool below, then click land on the map.`,
);

function onTeamNeedMarkerTool(slot: number): void {
    teamMarkerDialogSlot.value = slot;
    teamMarkerDialogOpen.value = true;
}

function closeTeamMarkerDialog(): void {
    teamMarkerDialogOpen.value = false;
}

function applyTeamMarkerTool(tool: Extract<MapEditorTool, 'capital' | 'flag' | 'infantry' | 'tank'>): void {
    editor.selectedTeam.value = teamMarkerDialogSlot.value;
    editor.activeTool.value = tool;
    teamMarkerDialogOpen.value = false;
}

/**
 * Remove one team by palette slot. Uses the slot argument synchronously (no modal ref) so
 * the deleted team always matches the swatch you clicked (e.g. red → slot 0).
 */
function onRequestRemoveTeam(slot: number): void {
    const n = Math.trunc(Number(slot));

    if (!Number.isInteger(n) || n < 0 || n >= editor.teamCount.value) {
        return;
    }

    const row = factionRowForTeamIndex(n);
    const label = row?.label ?? `Team ${n + 1}`;

    if (
        !window.confirm(
            `Remove the ${label} team? Their capital, flags, and troop spawns will be removed, and remaining teams will be renumbered. You can Undo.`,
        )
    ) {
        return;
    }

    editor.removeTeamAtSlot(n);
}

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
    visitMapBuilder(null, { replace: true });
}

function openGenerateDialog(): void {
    if (mapGenerationPending.value) {
        return;
    }

    generateDialogOpen.value = true;
}

async function onGenerateMap(payload: {
    seed?: number;
    type: MapGenerationType;
    teamCount: number;
}): Promise<void> {
    mapGenerationPending.value = true;

    await nextTick();

    try {
        const data = await runProceduralMapGeneration({
            seed: payload.seed,
            type: payload.type,
            cellRows: editor.gridRows.value,
            cellCols: editor.gridCols.value,
            teamCount: payload.teamCount,
        });

        const applied = editor.applyGeneratedMap(data);

        if (!applied) {
            toast.error('The generated map could not be applied to the current grid.');

            return;
        }
    } catch (err) {
        const message =
            err instanceof Error ? err.message : 'Map generation failed. Try again with different options.';
        toast.error(message);
    } finally {
        mapGenerationPending.value = false;
    }
}

async function onSave(): Promise<void> {
    if (editorLocked.value) {
        return;
    }

    saving.value = true;

    try {
        const updated = await editor.saveMap();

        if (updated) {
            mapsList.value = updated;
        }

        syncSlugToCurrentMapIfNeeded({ replace: true });

        toast.success('Map saved.');
    } catch (err) {
        const message =
            err instanceof Error
                ? err.message
                : 'Save failed. Check your connection and try again.';
        toast.error(message, message.includes('\n') ? 16_000 : 10_000);
    } finally {
        saving.value = false;
    }
}

const publishBusy = ref(false);
const publishConfirmOpen = ref(false);
const duplicateBusy = ref(false);

function requestPublishConfirm(): void {
    const uuid = editor.currentUuid.value;

    if (!uuid) {
        toast.warning('Save the map once before publishing.');

        return;
    }

    if (editor.dirty.value) {
        toast.warning('Save your latest changes before publishing.');

        return;
    }

    publishConfirmOpen.value = true;
}

async function confirmPublish(): Promise<void> {
    const uuid = editor.currentUuid.value;

    if (!uuid || editor.dirty.value) {
        publishConfirmOpen.value = false;

        return;
    }

    publishBusy.value = true;

    try {
        const res = await jsonPost(publishMap.url(uuid));

        const text = await res.text();

        if (!res.ok) {
            toast.error(text.slice(0, 4000), 14_000);

            return;
        }

        publishConfirmOpen.value = false;
        mapPublished.value = true;
        toast.success('Map published. It appears on Explore and editing is locked.');
        router.reload({ only: ['initialDocument', 'maps'] });
    } finally {
        publishBusy.value = false;
    }
}

async function onDuplicatePublished(): Promise<void> {
    const uuid = editor.currentUuid.value;

    if (!uuid || !mapPublished.value) {
        return;
    }

    if (!auth.value?.user) {
        toast.info('Sign in to duplicate this map into your library.');

        return;
    }

    duplicateBusy.value = true;

    try {
        const res = await jsonPost(forkMap.url(uuid), {});

        const text = await res.text();

        if (!res.ok) {
            toast.error(text.slice(0, 4000), 14_000);

            return;
        }

        const body = (await res.json()) as { map: { uuid: string } };
        toast.success('Editable copy created. Opening it now.');
        router.visit(mapBuilder.url(body.map.uuid));
    } finally {
        duplicateBusy.value = false;
    }
}

function onMapsListUpdated(next: MapSummary[]): void {
    mapsList.value = next;
}

function onTeamCountChange(e: Event): void {
    const el = e.target as HTMLSelectElement;
    const n = Number(el.value);

    if (Number.isFinite(n)) {
        editor.setTeamCount(n);
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

    if (autoSaveTimer !== null) {
        clearTimeout(autoSaveTimer);
    }
});
</script>

<template>
    <Head title="Map Builder" />

    <div
        v-if="!isLargeScreen"
        class="mx-auto flex w-full max-w-md flex-col items-center gap-4 py-8 text-center sm:py-12"
    >
        <div class="wod-panel flex w-full flex-col items-center gap-4 p-6 sm:p-8">
            <div
                class="flex size-14 items-center justify-center rounded-lg border-2 border-foreground bg-muted/40"
                aria-hidden="true"
            >
                <Monitor class="size-7 text-muted-foreground" />
            </div>
            <div class="space-y-2">
                <h2 class="font-display text-xl font-bold">Use a larger screen</h2>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    The map builder needs a wide canvas, toolbars, and palettes. Open this page
                    on a tablet in landscape or a desktop computer.
                </p>
            </div>
            <Button as-child variant="outline" class="w-full sm:w-auto">
                <Link :href="mapsExplore().url">Browse maps on Explore</Link>
            </Button>
        </div>
    </div>

    <div v-else class="flex h-full min-h-0 flex-1 flex-col gap-2 overflow-hidden">
        <div class="flex flex-wrap items-center gap-2 wod-surface px-3 py-2">
            <label class="sr-only" for="map-builder-name">Map name</label>
            <Input
                id="map-builder-name"
                v-model="editor.mapName"
                class="h-9 w-48 max-w-full border-2 border-foreground md:w-64"
                maxlength="120"
                placeholder="Map name"
                autocomplete="off"
                :disabled="editorLocked"
            />
            <span
                v-if="editorLocked"
                class="inline-flex items-center gap-1 rounded border border-foreground/25 bg-muted/50 px-2 py-0.5 text-xs font-medium text-muted-foreground"
            >
                <Lock class="size-3 shrink-0" />
                Published
            </span>
            <span
                v-if="editorDirty"
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
                class="h-9 gap-1.5 border-2 border-foreground !bg-wod-blue px-3 text-xs font-bold !text-white shadow-[0_2px_0_0_var(--wod-shadow)] hover:!bg-wod-blue/90 hover:!text-white active:!bg-wod-blue/80 dark:!bg-wod-blue dark:hover:!bg-wod-blue/85"
                title="Replace the map with procedurally generated terrain (runs locally in your browser)"
                :disabled="editorLocked || mapGenerationPending"
                @click="openGenerateDialog"
            >
                <Loader2 v-if="mapGenerationPending" class="size-4 shrink-0 animate-spin" />
                <Sparkles v-else class="size-4 shrink-0" />
                {{ mapGenerationPending ? 'Generating…' : 'Generate Map' }}
            </Button>
            <Button
                type="button"
                size="sm"
                variant="outline"
                class="h-8 gap-1 px-2 text-xs"
                title="Change vertex grid size (rows × columns)"
                :disabled="editorLocked"
                @click="openNewMapSizeDialog"
            >
                Grid {{ editor.gridRows }}×{{ editor.gridCols }}
            </Button>
            <div class="flex items-center gap-1.5">
                <label class="text-xs font-semibold text-muted-foreground" for="map-builder-teams">
                    Teams
                </label>
                <select
                    id="map-builder-teams"
                    class="wod-field h-8 w-auto min-w-[3.5rem] px-2 font-mono text-xs"
                    :disabled="editorLocked"
                    :value="headerTeamCount"
                    @change="onTeamCountChange"
                >
                    <option v-for="t in teamCountOptions" :key="t" :value="t">{{ t }}</option>
                </select>
            </div>
            <div class="flex flex-1 flex-wrap items-center justify-end gap-2">
                <Button
                    v-if="mapPublished && editor.currentUuid"
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="h-8 px-2 text-xs"
                    as-child
                >
                    <Link :href="publishedMapExploreUrl">Explore</Link>
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="!editor.canUndo || editorLocked"
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
                    :disabled="!editor.canRedo || editorLocked"
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
                    :disabled="saving || autoSaving || editorLocked"
                    @click="onSave"
                >
                    <Save class="size-3.5" />
                    {{ saving ? 'Saving…' : autoSaving ? 'Autosaving…' : 'Save' }}
                </Button>
                <Button
                    v-if="!editorLocked"
                    type="button"
                    size="sm"
                    variant="secondary"
                    class="gap-1"
                    :disabled="publishBusy || !hasSavedMap || editorDirty"
                    title="Validate, publish, and lock editing"
                    @click="requestPublishConfirm"
                >
                    <Upload class="size-3.5" />
                    Publish
                </Button>
                <Button
                    v-else
                    type="button"
                    size="sm"
                    variant="secondary"
                    class="gap-1"
                    :disabled="duplicateBusy || !hasSavedMap"
                    title="Create an editable copy in your library (the published map stays public)"
                    @click="onDuplicatePublished"
                >
                    <Copy class="size-3.5" />
                    {{ duplicateBusy ? 'Duplicating…' : 'Duplicate' }}
                </Button>
            </div>
        </div>

        <div
            class="flex min-h-0 flex-1 gap-2 overflow-hidden min-h-[clamp(16rem,44svh,50rem)]"
        >
            <MapListPanel
                :editor="editor"
                :maps="mapsList"
                :allow-library-mutations="allowLibraryMutations"
                @maps-list-updated="onMapsListUpdated"
                @open-map="onOpenMapFromList"
                @request-new-map="onRequestNewMap"
            />
            <MapEditorToolbar :editor="editor" :read-only="editorLocked" />
            <MapEditorCanvas
                :editor="editor"
                :team-colors="teamColors"
                :read-only="editorLocked"
                class="min-h-0 min-w-0 flex-1"
            />
        </div>

        <div
            class="flex w-full min-w-0 shrink-0 flex-row items-stretch gap-2 border-t border-foreground/15 py-1.5 min-h-[8.5rem]"
            :class="{ 'pointer-events-none opacity-60': editorLocked }"
        >
            <MapTerrainPalette
                :editor="editor"
                :terrain-types="terrainTypes"
                class="min-h-0 min-w-0 flex-1 basis-0"
            />
            <MapTeamPalette
                :editor="editor"
                :team-colors="teamColors"
                class="min-h-0 min-w-0 flex-1 basis-0"
                @need-marker-tool="onTeamNeedMarkerTool"
                @request-remove-team="onRequestRemoveTeam"
            />
        </div>

        <MapGenerateDialog
            v-model:open="generateDialogOpen"
            :dirty="editorDirty"
            :team-count="headerTeamCount"
            :generating="mapGenerationPending"
            @generate="onGenerateMap"
        />

        <AppModal
            v-model:open="publishConfirmOpen"
            title="Publish this map?"
            description="It will appear on Explore for everyone. You cannot take it back or edit this version afterward. Use Duplicate afterward if you want an editable copy while keeping the public design."
            content-class="sm:max-w-lg"
        >
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="publishBusy"
                    @click="publishConfirmOpen = false"
                >
                    Cancel
                </Button>
                <Button type="button" :disabled="publishBusy" @click="confirmPublish">
                    {{ publishBusy ? 'Publishing…' : 'Publish' }}
                </Button>
            </template>
        </AppModal>

        <AppModal
            v-model:open="teamMarkerDialogOpen"
            title="Use a marker tool"
            :description="teamMarkerDialogDescription"
            content-class="sm:max-w-xl"
        >
            <div class="grid grid-cols-2 gap-2">
                <Button
                    type="button"
                    variant="outline"
                    class="h-auto min-h-11 w-full justify-center gap-2 py-2"
                    @click="applyTeamMarkerTool('flag')"
                >
                    <Flag class="size-4 shrink-0" stroke-width="2" />
                    Flag
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="h-auto min-h-11 w-full justify-center gap-2 py-2"
                    @click="applyTeamMarkerTool('capital')"
                >
                    <Landmark class="size-4 shrink-0" stroke-width="2" />
                    Capital
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="h-auto min-h-11 w-full justify-center gap-2 py-2"
                    @click="applyTeamMarkerTool('infantry')"
                >
                    <Circle class="size-4 shrink-0" stroke-width="2" />
                    Infantry
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="h-auto min-h-11 w-full justify-center gap-2 py-2"
                    @click="applyTeamMarkerTool('tank')"
                >
                    <RectangleHorizontal class="size-4 shrink-0" stroke-width="2" />
                    Tank
                </Button>
            </div>
            <template #footer>
                <Button type="button" variant="destructive" @click="closeTeamMarkerDialog">
                    Cancel
                </Button>
            </template>
        </AppModal>

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
