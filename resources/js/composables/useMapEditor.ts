import { computed, ref, shallowRef, watch } from 'vue';
import { generateRandomMap } from '@/lib/generateRandomMap';
import type { GeneratedMapData, MapGenerationType } from '@/lib/generateRandomMap';
import {
    MAP_EDITOR_CELL_PX,
    MAP_MAX_TEAMS,
    MAP_MIN_TEAMS,
    emptyMapPayload,
    isAllowedMapGridSize,
    normalizeMapPayload,
    validateMapGridData,
    validateMapMarkers,
} from '@/lib/mapEditorGrid';
import type { MapDataPayload, MapMarker } from '@/lib/mapEditorGrid';
import { computeMinSeparationForMapState, manhattanDistance } from '@/lib/mapMarkerSpacing';
import { isFarEnoughFromHydraulicWaterForMapMarker, isPlaceableTerrain } from '@/lib/mapMarkers';
import type { TerrainId } from '@/lib/terrainCatalog';
import mapsRoutes, { destroy as destroyMap, show, store, update } from '@/routes/maps';

export type MapEditorTool = 'brush' | 'eraser' | 'fill' | 'pan' | 'capital' | 'flag';

export type { MapDataPayload, MapMarker } from '@/lib/mapEditorGrid';

function cloneCells(cells: string[][]): string[][] {
    return cells.map((row) => [...row]);
}

function cloneMarkerList(markers: MapMarker[]): MapMarker[] {
    return markers.map((m) => ({ ...m }));
}

type EditorSnapshot = {
    cells: string[][];
    markers: MapMarker[];
    teamCount: number;
};

const MAX_UNDO = 50;

/** Symmetric screen padding (px) when fitting the map so edges stay visibly inside the view. */
const FIT_SCREEN_PADDING_PX = 56;

/**
 * After computing the zoom that would exactly fit the map, multiply by this (values below 1)
 * so the default view is a bit more zoomed out with breathing room around the grid.
 */
const FIT_TO_VIEW_ZOOM_MULTIPLIER = 0.82;

export const MAP_EDITOR_MIN_ZOOM = 0.04;

export const MAP_EDITOR_MAX_ZOOM = 3;

export const MAP_EDITOR_BRUSH_SIZES = [1, 3, 5] as const;

export type MapEditorBrushSize = (typeof MAP_EDITOR_BRUSH_SIZES)[number];

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

async function jsonFetch(
    url: string,
    options: RequestInit & { method?: string } = {},
): Promise<Response> {
    const headers: HeadersInit = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': decodeURIComponent(getCookie('XSRF-TOKEN')),
        ...((options.headers as Record<string, string>) ?? {}),
    };

    return fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers,
    });
}

/** Row shape returned by {@see MapController::index} for the map-builder sidebar. */
export type MapListSummary = {
    id: number;
    uuid: string;
    name: string;
    updated_at: string | null;
};

async function fetchMapsSummaries(): Promise<MapListSummary[] | null> {
    const res = await jsonFetch(mapsRoutes.index.url());

    if (!res.ok) {
        return null;
    }

    const body = (await res.json()) as { maps: MapListSummary[] };

    return body.maps;
}

export function useMapEditor(initialDefaults: MapDataPayload) {
    const initialNormalized = normalizeMapPayload(initialDefaults);

    const cells = ref<string[][]>(cloneCells(initialNormalized.cells));
    const teamCount = ref(initialNormalized.teamCount ?? MAP_MIN_TEAMS);
    const markers = ref<MapMarker[]>(cloneMarkerList(initialNormalized.markers ?? []));
    /** `null` when painting terrain so the team strip does not look “armed” for markers. */
    const selectedTeam = ref<number | null>(null);

    const mapName = ref('Untitled map');
    const currentUuid = shallowRef<string | null>(null);
    const dirty = ref(false);
    const activeTool = ref<MapEditorTool>('brush');
    const selectedTerrain = ref<TerrainId>('plains');
    const brushRadius = ref(1);
    const zoom = ref(1);
    const camX = ref(0);
    const camY = ref(0);
    /** Bumped when the map document changes so the canvas can re-fit the view. */
    const mapViewNonce = ref(0);
    /** Bumped when terrain changes so the editor can repaint without deep-watching cells. */
    const terrainEpoch = ref(0);
    /** Bumped when markers or team count change. */
    const markersEpoch = ref(0);

    function clampSelectedTeamToTeamCount(): void {
        if (selectedTeam.value === null) {
            return;
        }

        selectedTeam.value = Math.min(selectedTeam.value, teamCount.value - 1);
    }

    watch(activeTool, (tool) => {
        if (tool === 'capital' || tool === 'flag') {
            if (selectedTeam.value === null) {
                selectedTeam.value = 0;
            }

            return;
        }

        if (tool === 'brush' || tool === 'eraser' || tool === 'fill') {
            selectedTeam.value = null;
        }
    });

    watch(selectedTerrain, () => {
        selectedTeam.value = null;
    });

    const undoStack = ref<EditorSnapshot[]>([]);
    const redoStack = ref<EditorSnapshot[]>([]);

    const strokeOpen = ref(false);

    const cellSize = MAP_EDITOR_CELL_PX;
    const gridRows = computed(() => cells.value.length);
    const gridCols = computed(() => cells.value[0]?.length ?? 0);
    const worldWidth = computed(() => gridRows.value * cellSize);
    const worldHeight = computed(() => gridCols.value * cellSize);

    function fitMapToView(viewportWidthPx: number, viewportHeightPx: number): void {
        const w = gridRows.value * cellSize;
        const h = gridCols.value * cellSize;
        const availW = Math.max(64, viewportWidthPx - 2 * FIT_SCREEN_PADDING_PX);
        const availH = Math.max(64, viewportHeightPx - 2 * FIT_SCREEN_PADDING_PX);
        const zw = availW / w;
        const zh = availH / h;
        const fitZ = Math.min(zw, zh) * FIT_TO_VIEW_ZOOM_MULTIPLIER;
        const z = Math.min(MAP_EDITOR_MAX_ZOOM, Math.max(MAP_EDITOR_MIN_ZOOM, fitZ));
        zoom.value = z;
        camX.value = (viewportWidthPx / z - w) / 2;
        camY.value = (viewportHeightPx / z - h) / 2;
    }

    function requestMapViewFit(): void {
        mapViewNonce.value += 1;
    }

    function bumpTerrainRender(): void {
        terrainEpoch.value += 1;
    }

    function bumpMarkersRender(): void {
        markersEpoch.value += 1;
    }

    function snapshot(): void {
        undoStack.value.push({
            cells: cloneCells(cells.value),
            markers: cloneMarkerList(markers.value),
            teamCount: teamCount.value,
        });

        if (undoStack.value.length > MAX_UNDO) {
            undoStack.value.shift();
        }

        redoStack.value = [];
    }

    function pruneMarkersOnInvalidTerrain(): void {
        const next = markers.value.filter((m) => {
            const t = cells.value[m.row]?.[m.col];

            if (typeof t !== 'string' || !isPlaceableTerrain(t)) {
                return false;
            }

            if (m.type === 'capital' || m.type === 'flag') {
                return isFarEnoughFromHydraulicWaterForMapMarker(
                    cells.value,
                    gridRows.value,
                    gridCols.value,
                    m.row,
                    m.col,
                );
            }

            return true;
        });

        if (next.length !== markers.value.length) {
            markers.value = next;
            bumpMarkersRender();
        }
    }

    function undo(): void {
        const prev = undoStack.value.pop();

        if (!prev) {
            return;
        }

        redoStack.value.push({
            cells: cloneCells(cells.value),
            markers: cloneMarkerList(markers.value),
            teamCount: teamCount.value,
        });
        cells.value = prev.cells;
        markers.value = cloneMarkerList(prev.markers);
        teamCount.value = prev.teamCount;
        clampSelectedTeamToTeamCount();
        dirty.value = true;
        bumpTerrainRender();
        bumpMarkersRender();
    }

    function redo(): void {
        const next = redoStack.value.pop();

        if (!next) {
            return;
        }

        undoStack.value.push({
            cells: cloneCells(cells.value),
            markers: cloneMarkerList(markers.value),
            teamCount: teamCount.value,
        });
        cells.value = next.cells;
        markers.value = cloneMarkerList(next.markers);
        teamCount.value = next.teamCount;
        clampSelectedTeamToTeamCount();
        dirty.value = true;
        bumpTerrainRender();
        bumpMarkersRender();
    }

    function stampDisc(gx: number, gy: number, paint: (x: number, y: number) => void): void {
        const r = brushRadius.value;

        for (let dy = -r; dy <= r; dy++) {
            for (let dx = -r; dx <= r; dx++) {
                if (dx * dx + dy * dy > r * r + 0.5) {
                    continue;
                }

                const x = gx + dx;
                const y = gy + dy;

                if (x >= 0 && x < gridRows.value && y >= 0 && y < gridCols.value) {
                    paint(x, y);
                }
            }
        }
    }

    function stampBrushOnly(gx: number, gy: number): void {
        stampDisc(gx, gy, (x, y) => {
            cells.value[x][y] = selectedTerrain.value;
        });
    }

    function stampEraserOnly(gx: number, gy: number): void {
        stampDisc(gx, gy, (x, y) => {
            cells.value[x][y] = 'plains';
            markers.value = markers.value.filter((m) => !(m.row === x && m.col === y));
        });
    }

    function beginStroke(): void {
        if (
            activeTool.value === 'fill'
            || activeTool.value === 'pan'
            || activeTool.value === 'capital'
            || activeTool.value === 'flag'
        ) {
            return;
        }

        snapshot();
        strokeOpen.value = true;
    }

    function endStroke(): void {
        if (strokeOpen.value && (activeTool.value === 'brush' || activeTool.value === 'eraser')) {
            pruneMarkersOnInvalidTerrain();
        }

        strokeOpen.value = false;
    }

    function strokePaint(gx: number, gy: number): void {
        if (!strokeOpen.value) {
            return;
        }

        if (activeTool.value === 'brush') {
            stampBrushOnly(gx, gy);
            dirty.value = true;
            bumpTerrainRender();
        } else if (activeTool.value === 'eraser') {
            stampEraserOnly(gx, gy);
            dirty.value = true;
            bumpTerrainRender();
            bumpMarkersRender();
        }
    }

    function applyFill(gx: number, gy: number): void {
        const target = cells.value[gx][gy];

        if (target === selectedTerrain.value) {
            return;
        }

        snapshot();
        const visited = new Set<string>();
        const queue: [number, number][] = [[gx, gy]];

        while (queue.length > 0) {
            const [x, y] = queue.pop()!;
            const key = `${x},${y}`;

            if (visited.has(key)) {
                continue;
            }

            if (x < 0 || x >= gridRows.value || y < 0 || y >= gridCols.value) {
                continue;
            }

            if (cells.value[x][y] !== target) {
                continue;
            }

            visited.add(key);
            cells.value[x][y] = selectedTerrain.value;
            queue.push([x + 1, y], [x - 1, y], [x, y + 1], [x, y - 1]);
        }

        pruneMarkersOnInvalidTerrain();
        dirty.value = true;
        bumpTerrainRender();
    }

    function clickTool(gx: number, gy: number): void {
        if (activeTool.value === 'fill') {
            applyFill(gx, gy);
        }
    }

    function placeCapitalAt(gx: number, gy: number): void {
        const t = cells.value[gx]?.[gy];

        if (typeof t !== 'string' || !isPlaceableTerrain(t)) {
            return;
        }

        if (
            !isFarEnoughFromHydraulicWaterForMapMarker(
                cells.value,
                gridRows.value,
                gridCols.value,
                gx,
                gy,
            )
        ) {
            return;
        }

        snapshot();
        const team = selectedTeam.value ?? 0;
        markers.value = markers.value.filter(
            (m) => !(m.team === team && m.type === 'capital') && !(m.row === gx && m.col === gy),
        );
        markers.value = [...markers.value, { type: 'capital', team, row: gx, col: gy }];
        dirty.value = true;
        bumpMarkersRender();
    }

    function placeFlagAt(gx: number, gy: number): void {
        const t = cells.value[gx]?.[gy];

        if (typeof t !== 'string' || !isPlaceableTerrain(t)) {
            return;
        }

        if (
            !isFarEnoughFromHydraulicWaterForMapMarker(
                cells.value,
                gridRows.value,
                gridCols.value,
                gx,
                gy,
            )
        ) {
            return;
        }

        if (markers.value.some((m) => m.row === gx && m.col === gy)) {
            return;
        }

        const capitalPositions = markers.value
            .filter((m) => m.type === 'capital')
            .map((m) => ({ row: m.row, col: m.col }));
        const flagCount = markers.value.filter((m) => m.type === 'flag').length;
        const flagBudget = Math.max(flagCount + 1, teamCount.value * 2, 1);
        const sep = computeMinSeparationForMapState({
            cells: cells.value,
            rows: gridRows.value,
            cols: gridCols.value,
            teamCount: teamCount.value,
            capitalPositions,
            flagBudget,
        });

        for (const m of markers.value) {
            if (m.type !== 'capital' && m.type !== 'flag') {
                continue;
            }

            if (manhattanDistance({ row: gx, col: gy }, { row: m.row, col: m.col }) < sep) {
                return;
            }
        }

        snapshot();
        markers.value = [
            ...markers.value,
            { type: 'flag', team: selectedTeam.value ?? 0, row: gx, col: gy },
        ];
        dirty.value = true;
        bumpMarkersRender();
    }

    function placementClick(gx: number, gy: number): void {
        if (activeTool.value === 'capital') {
            placeCapitalAt(gx, gy);
        } else if (activeTool.value === 'flag') {
            placeFlagAt(gx, gy);
        }
    }

    function resetDocumentFromPayload(payload: MapDataPayload): void {
        const n = normalizeMapPayload(payload);
        cells.value = cloneCells(n.cells);
        teamCount.value = n.teamCount ?? MAP_MIN_TEAMS;
        markers.value = cloneMarkerList(n.markers ?? []);
        selectedTeam.value = null;
    }

    function newMap(): void {
        resetDocumentFromPayload(initialDefaults);
        teamCount.value = MAP_MIN_TEAMS;
        selectedTeam.value = null;
        mapName.value = 'Untitled map';
        currentUuid.value = null;
        dirty.value = false;
        undoStack.value = [];
        redoStack.value = [];
        bumpTerrainRender();
        bumpMarkersRender();
        requestMapViewFit();
    }

    function newMapWithSize(cellRows: number, cellCols: number): void {
        if (!isAllowedMapGridSize(cellRows, cellCols)) {
            return;
        }

        const payload = emptyMapPayload(cellRows, cellCols);
        cells.value = cloneCells(payload.cells);
        teamCount.value = MAP_MIN_TEAMS;
        markers.value = cloneMarkerList(payload.markers ?? []);
        selectedTeam.value = null;
        mapName.value = 'Untitled map';
        currentUuid.value = null;
        dirty.value = false;
        undoStack.value = [];
        redoStack.value = [];
        bumpTerrainRender();
        bumpMarkersRender();
        requestMapViewFit();
    }

    function loadFromPayload(payload: MapDataPayload, name: string, uuid: string): void {
        if (
            !validateMapGridData({
                cellRows: payload.cellRows,
                cellCols: payload.cellCols,
                cells: payload.cells,
            })
        ) {
            return;
        }

        const n = normalizeMapPayload(payload);
        cells.value = cloneCells(n.cells);
        teamCount.value = n.teamCount ?? MAP_MIN_TEAMS;
        markers.value = cloneMarkerList(n.markers ?? []);
        selectedTeam.value = null;
        mapName.value = name;
        currentUuid.value = uuid;
        dirty.value = false;
        undoStack.value = [];
        redoStack.value = [];
        bumpTerrainRender();
        bumpMarkersRender();
        requestMapViewFit();
    }

    async function loadMap(uuid: string): Promise<void> {
        const res = await jsonFetch(show.url(uuid));

        if (!res.ok) {
            throw new Error('Failed to load map');
        }

        const body = (await res.json()) as {
            map: { name: string; uuid: string; data: MapDataPayload };
        };
        loadFromPayload(body.map.data, body.map.name, body.map.uuid);
    }

    function getDataPayload(): MapDataPayload {
        const rows = gridRows.value;
        const cols = gridCols.value;

        return {
            version: 2,
            cellRows: rows,
            cellCols: cols,
            cells: cloneCells(cells.value),
            teamCount: teamCount.value,
            markers: cloneMarkerList(markers.value),
        };
    }

    async function saveMap(): Promise<MapListSummary[] | null> {
        const data = getDataPayload();
        const markerErrors = validateMapMarkers(data);

        if (markerErrors.length > 0) {
            throw new Error(markerErrors.join('\n'));
        }

        const name = mapName.value.trim() || 'Untitled map';

        if (currentUuid.value) {
            const res = await jsonFetch(update.url(currentUuid.value), {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ name, data }),
            });

            if (!res.ok) {
                throw new Error('Save failed');
            }
        } else {
            const res = await jsonFetch(store.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ name, data }),
            });

            if (!res.ok) {
                throw new Error('Create failed');
            }

            const body = (await res.json()) as { map: { uuid: string } };
            currentUuid.value = body.map.uuid;
        }

        dirty.value = false;

        return fetchMapsSummaries();
    }

    async function deleteMap(uuid: string): Promise<MapListSummary[] | null> {
        const res = await jsonFetch(destroyMap.url(uuid), {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': csrfToken(),
            },
        });

        if (!res.ok) {
            throw new Error('Delete failed');
        }

        if (currentUuid.value === uuid) {
            newMap();
        }

        return fetchMapsSummaries();
    }

    function applyGeneratedMap(payload: MapDataPayload | GeneratedMapData): void {
        if (
            !validateMapGridData({
                cellRows: payload.cellRows,
                cellCols: payload.cellCols,
                cells: payload.cells,
            })
        ) {
            return;
        }

        snapshot();
        cells.value = cloneCells(payload.cells);

        if (
            payload.version >= 2
            && typeof payload.teamCount === 'number'
            && Array.isArray(payload.markers)
        ) {
            teamCount.value = payload.teamCount;
            markers.value = cloneMarkerList(payload.markers);
        } else {
            teamCount.value = MAP_MIN_TEAMS;
            markers.value = [];
        }

        selectedTeam.value = null;
        dirty.value = true;
        bumpTerrainRender();
        bumpMarkersRender();
        requestMapViewFit();
    }

    function generateAndApplyMap(seed?: number, type?: MapGenerationType): void {
        applyGeneratedMap(
            generateRandomMap({
                seed,
                type,
                cellRows: gridRows.value,
                cellCols: gridCols.value,
            }),
        );
    }

    function setTeamCount(next: number): void {
        let n = Math.round(next);

        if (!Number.isFinite(n)) {
            return;
        }

        n = Math.max(MAP_MIN_TEAMS, Math.min(MAP_MAX_TEAMS, n));

        if (n === teamCount.value) {
            return;
        }

        snapshot();
        teamCount.value = n;
        markers.value = markers.value.filter((m) => m.team < n);
        clampSelectedTeamToTeamCount();
        dirty.value = true;
        bumpMarkersRender();
    }

    /**
     * Removes one team slot: deletes that team's markers and decrements {@link teamCount},
     * remapping higher team indices down by one (so colour slots stay contiguous).
     */
    function removeTeamAtSlot(slot: number): void {
        if (teamCount.value <= MAP_MIN_TEAMS) {
            return;
        }

        if (slot < 0 || slot >= teamCount.value || !Number.isInteger(slot)) {
            return;
        }

        snapshot();
        markers.value = markers.value
            .filter((m) => m.team !== slot)
            .map((m) => (m.team > slot ? { ...m, team: m.team - 1 } : m));
        teamCount.value -= 1;

        if (selectedTeam.value === slot) {
            selectedTeam.value = null;
        } else if (selectedTeam.value !== null && selectedTeam.value > slot) {
            selectedTeam.value = selectedTeam.value - 1;
        }

        clampSelectedTeamToTeamCount();
        dirty.value = true;
        bumpMarkersRender();
    }

    function setBrushRadius(size: MapEditorBrushSize): void {
        brushRadius.value = size;
    }

    function bumpBrush(delta: number): void {
        const i = MAP_EDITOR_BRUSH_SIZES.indexOf(brushRadius.value as MapEditorBrushSize);
        const idx = i === -1 ? 0 : i;
        const next = Math.max(0, Math.min(MAP_EDITOR_BRUSH_SIZES.length - 1, idx + delta));
        brushRadius.value = MAP_EDITOR_BRUSH_SIZES[next] ?? 1;
    }

    const canUndo = computed(() => undoStack.value.length > 0);
    const canRedo = computed(() => redoStack.value.length > 0);

    return {
        cells,
        teamCount,
        markers,
        selectedTeam,
        mapName,
        currentUuid,
        dirty,
        activeTool,
        selectedTerrain,
        brushRadius,
        zoom,
        camX,
        camY,
        mapViewNonce,
        terrainEpoch,
        markersEpoch,
        cellSize,
        gridRows,
        gridCols,
        worldWidth,
        worldHeight,
        fitMapToView,
        requestMapViewFit,
        canUndo,
        canRedo,
        undo,
        redo,
        beginStroke,
        endStroke,
        strokePaint,
        clickTool,
        placementClick,
        setTeamCount,
        removeTeamAtSlot,
        newMap,
        newMapWithSize,
        loadMap,
        saveMap,
        deleteMap,
        loadFromPayload,
        getDataPayload,
        bumpBrush,
        setBrushRadius,
        applyGeneratedMap,
        generateAndApplyMap,
    };
}

export type MapEditorInstance = ReturnType<typeof useMapEditor>;
