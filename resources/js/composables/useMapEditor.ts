import { computed, ref, shallowRef, watch } from 'vue';
import { generateRandomMap } from '@/lib/generateRandomMap';
import type { GeneratedMapData, MapGenerationType } from '@/lib/generateRandomMap';
import {
    MAP_EDITOR_CELL_PX,
    MAP_MAX_TEAMS,
    MAP_MIN_TEAMS,
    defaultTeamPaletteSlots,
    emptyMapPayload,
    isAllowedMapGridSize,
    normalizeMapPayload,
    normalizeTeamPaletteSlots,
    validateMapGridData,
} from '@/lib/mapEditorGrid';
import type { MapDataPayload, MapMarker } from '@/lib/mapEditorGrid';
import { isFarEnoughFromHydraulicWaterForMapMarker, isPlaceableTerrain } from '@/lib/mapMarkers';
import {
    computeMinSeparationForMapState,
    countPlaceableLandCells,
    manhattanDistance,
} from '@/lib/mapMarkerSpacing';
import type { TerrainId } from '@/lib/terrainCatalog';
import { randomWackyMapName } from '@/lib/wackyMapName';
import mapsRoutes, { destroy as destroyMap, show, store, update } from '@/routes/maps';
import { useToastStore } from '@/stores/toastStore';

let lastPlacementToastAtMs = 0;

const PLACEMENT_TOAST_DEBOUNCE_MS = 900;

function notifyPlacementBlocked(message: string): void {
    const now = Date.now();

    if (now - lastPlacementToastAtMs < PLACEMENT_TOAST_DEBOUNCE_MS) {
        return;
    }

    lastPlacementToastAtMs = now;
    useToastStore().warning(message, 5600);
}

export type MapEditorTool = 'brush' | 'eraser' | 'fill' | 'pan' | 'capital' | 'flag' | 'infantry' | 'tank';

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
    teamPaletteSlots: number[];
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

type LaravelJsonErrors = {
    message?: string;
    errors?: Record<string, string[] | string | number>;
};

function humanizeValidationFieldKey(key: string): string {
    const map: Record<string, string> = {
        name: 'Map name',
        'data.version': 'Map format',
        'data.cellRows': 'Map height (rows)',
        'data.cellCols': 'Map width (columns)',
        'data.cells': 'Terrain grid',
        'data.markers': 'Capitals & troops',
        'data.teamCount': 'Number of teams',
        'data.teamPaletteSlots': 'Team colour slots',
    };

    if (map[key]) {
        return map[key];
    }

    if (key.startsWith('data.teamPaletteSlots.')) {
        return 'Team colour slots';
    }

    return key;
}

function isGenericInvalidMessage(message: string): boolean {
    const t = message.trim();

    return (
        /^The .+ was invalid\.?$/i.test(t)
        || /^The given data was invalid\.?$/i.test(t)
        || t === 'validation.required'
    );
}

/**
 * Builds a readable error string from a failed map save (JSON) response.
 */
export function formatMapSaveErrorFromResponse(status: number, responseText: string): string {
    let parsed: unknown;

    try {
        parsed = JSON.parse(responseText) as unknown;
    } catch {
        const snippet = responseText.trim().slice(0, 240);

        return snippet.length > 0
            ? `Could not save (${status}). Server said:\n${snippet}`
            : mapSaveHttpStatusMessage(status);
    }

    const body = parsed as LaravelJsonErrors;
    const lines: string[] = [];

    if (body.errors !== undefined && body.errors !== null && typeof body.errors === 'object') {
        for (const [key, raw] of Object.entries(body.errors)) {
            const label = humanizeValidationFieldKey(key);
            const msgs: string[] = Array.isArray(raw)
                ? raw.map((m) => String(m))
                : [String(raw)];

            for (const m of msgs) {
                const t = m.trim();

                if (t.length > 0) {
                    lines.push(`• ${label}: ${t}`);
                }
            }
        }
    }

    const rawMessage = typeof body.message === 'string' ? body.message.trim() : '';

    if (lines.length > 0) {
        const headline = rawMessage && !isGenericInvalidMessage(rawMessage) ? `${rawMessage}\n` : 'Could not save this map:\n';

        return `${headline}${lines.join('\n')}`.slice(0, 4000);
    }

    if (rawMessage.length > 0) {
        return rawMessage;
    }

    return mapSaveHttpStatusMessage(status);
}

function mapSaveHttpStatusMessage(status: number): string {
    if (status === 401) {
        return 'You need to be signed in to save maps. Refresh the page and sign in again.';
    }

    if (status === 403) {
        return 'You do not have permission to save this map.';
    }

    if (status === 404) {
        return 'That map was not found. It may have been deleted — try picking another map from the list.';
    }

    if (status === 419) {
        return 'Your session expired (CSRF). Refresh the page and try saving again.';
    }

    if (status === 422) {
        return 'The map could not be saved because some fields are invalid. Check the form and try again.';
    }

    if (status >= 500) {
        return 'The server had a problem saving the map. Wait a moment and try again.';
    }

    return `Could not save the map (HTTP ${status}). Check your connection and try again.`;
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
    const teamPaletteSlots = ref<number[]>(
        normalizeTeamPaletteSlots(teamCount.value, initialNormalized.teamPaletteSlots),
    );
    /** `null` when painting terrain so the team strip does not look “armed” for markers. */
    const selectedTeam = ref<number | null>(null);

    const mapName = ref(randomWackyMapName());
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
        if (tool === 'capital' || tool === 'flag' || tool === 'infantry' || tool === 'tank') {
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
            teamPaletteSlots: [...teamPaletteSlots.value],
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

            if (m.type === 'capital' || m.type === 'flag' || m.type === 'infantry' || m.type === 'tank') {
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
            teamPaletteSlots: [...teamPaletteSlots.value],
        });
        cells.value = prev.cells;
        markers.value = cloneMarkerList(prev.markers);
        teamCount.value = prev.teamCount;
        teamPaletteSlots.value = [...prev.teamPaletteSlots];
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
            teamPaletteSlots: [...teamPaletteSlots.value],
        });
        cells.value = next.cells;
        markers.value = cloneMarkerList(next.markers);
        teamCount.value = next.teamCount;
        teamPaletteSlots.value = [...next.teamPaletteSlots];
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
            || activeTool.value === 'infantry'
            || activeTool.value === 'tank'
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

        const existingOnCell = markers.value.find((m) => m.row === gx && m.col === gy);

        if (
            existingOnCell?.type === 'flag'
            || existingOnCell?.type === 'infantry'
            || existingOnCell?.type === 'tank'
        ) {
            snapshot();
            markers.value = markers.value.filter((m) => !(m.row === gx && m.col === gy));
            dirty.value = true;
            bumpMarkersRender();

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
            notifyPlacementBlocked(
                'This terrain cannot hold a flag (hills, mountains, rivers, and water are blocked).',
            );

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
            notifyPlacementBlocked('Too close to deep water or a river — move further from the coast.');

            return;
        }

        const existingOnCell = markers.value.find((m) => m.row === gx && m.col === gy);

        if (existingOnCell?.type === 'flag') {
            snapshot();
            markers.value = markers.value.filter((m) => !(m.row === gx && m.col === gy));
            dirty.value = true;
            bumpMarkersRender();

            return;
        }

        if (existingOnCell) {
            notifyPlacementBlocked(
                'That tile already has a marker. Remove it first, or choose an empty tile for the flag.',
            );

            return;
        }

        const capitalPositions = markers.value
            .filter((m) => m.type === 'capital')
            .map((m) => ({ row: m.row, col: m.col }));
        const nonCapitalCount = markers.value.filter((m) => m.type !== 'capital').length;
        const nLand = countPlaceableLandCells(cells.value, gridRows.value, gridCols.value);
        /**
         * {@link computeMinSeparationForMapState} grows spacing when {@link flagBudget} is small
         * (few flags planned), which made the first couple of manual flags nearly impossible to add.
         * Assume a denser eventual layout while editing; save-time validation still enforces the
         * real rules for the final marker set.
         */
        const flagBudget = Math.max(
            nonCapitalCount + 1,
            teamCount.value * 2,
            Math.min(320, Math.max(48, Math.floor(nLand / 3))),
        );
        const sep = computeMinSeparationForMapState({
            cells: cells.value,
            rows: gridRows.value,
            cols: gridCols.value,
            teamCount: teamCount.value,
            capitalPositions,
            flagBudget,
        });

        for (const m of markers.value) {
            if (m.type !== 'capital' && m.type !== 'flag' && m.type !== 'infantry' && m.type !== 'tank') {
                continue;
            }

            if (manhattanDistance({ row: gx, col: gy }, { row: m.row, col: m.col }) < sep) {
                notifyPlacementBlocked(
                    'Too close to another capital, flag, or troop spawn for this map’s spacing rules.',
                );

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

    function placeTroopAt(gx: number, gy: number, troopType: 'infantry' | 'tank'): void {
        const t = cells.value[gx]?.[gy];

        if (typeof t !== 'string' || !isPlaceableTerrain(t)) {
            notifyPlacementBlocked(
                'This terrain cannot hold a troop spawn (hills, mountains, rivers, and water are blocked).',
            );

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
            notifyPlacementBlocked('Too close to deep water or a river — move further from the coast.');

            return;
        }

        const team = selectedTeam.value ?? 0;
        const existingOnCell = markers.value.find((m) => m.row === gx && m.col === gy);

        if (existingOnCell?.type === troopType && existingOnCell.team === team) {
            snapshot();
            markers.value = markers.value.filter((m) => !(m.row === gx && m.col === gy));
            dirty.value = true;
            bumpMarkersRender();

            return;
        }

        let didSnapshot = false;

        if (existingOnCell?.type === 'infantry' || existingOnCell?.type === 'tank') {
            snapshot();
            didSnapshot = true;
            markers.value = markers.value.filter((m) => !(m.row === gx && m.col === gy));
            dirty.value = true;
            bumpMarkersRender();
        } else if (existingOnCell) {
            notifyPlacementBlocked(
                'That tile has a capital or flag. Remove it with the Capital or Flag tool first, then place a spawn.',
            );

            return;
        }

        if (!didSnapshot) {
            snapshot();
        }

        markers.value = [...markers.value, { type: troopType, team, row: gx, col: gy }];
        dirty.value = true;
        bumpMarkersRender();
    }

    function placementClick(gx: number, gy: number): void {
        if (activeTool.value === 'capital') {
            placeCapitalAt(gx, gy);
        } else if (activeTool.value === 'flag') {
            placeFlagAt(gx, gy);
        } else if (activeTool.value === 'infantry') {
            placeTroopAt(gx, gy, 'infantry');
        } else if (activeTool.value === 'tank') {
            placeTroopAt(gx, gy, 'tank');
        }
    }

    function resetDocumentFromPayload(payload: MapDataPayload): void {
        const n = normalizeMapPayload(payload);
        cells.value = cloneCells(n.cells);
        const tc = n.teamCount ?? MAP_MIN_TEAMS;
        teamCount.value = tc;
        markers.value = cloneMarkerList(n.markers ?? []);
        teamPaletteSlots.value = normalizeTeamPaletteSlots(tc, n.teamPaletteSlots);
        selectedTeam.value = null;
    }

    function newMap(): void {
        resetDocumentFromPayload(initialDefaults);
        mapName.value = randomWackyMapName();
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

        resetDocumentFromPayload(emptyMapPayload(cellRows, cellCols));
        mapName.value = randomWackyMapName();
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

        resetDocumentFromPayload(payload);
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
            teamPaletteSlots: [...teamPaletteSlots.value],
        };
    }

    async function saveMap(): Promise<MapListSummary[] | null> {
        const data = getDataPayload();
        const name = mapName.value.trim() || randomWackyMapName();

        if (!mapName.value.trim()) {
            mapName.value = name;
        }

        if (currentUuid.value) {
            const res = await jsonFetch(update.url(currentUuid.value), {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ name, data }),
            });
            const text = await res.text();

            if (!res.ok) {
                throw new Error(formatMapSaveErrorFromResponse(res.status, text));
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
            const text = await res.text();

            if (!res.ok) {
                throw new Error(formatMapSaveErrorFromResponse(res.status, text));
            }

            const body = JSON.parse(text) as { map: { uuid: string } };
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
            teamPaletteSlots.value = normalizeTeamPaletteSlots(
                payload.teamCount,
                payload.teamPaletteSlots,
            );
        } else {
            teamCount.value = MAP_MIN_TEAMS;
            markers.value = [];
            teamPaletteSlots.value = defaultTeamPaletteSlots(MAP_MIN_TEAMS);
        }

        selectedTeam.value = null;
        mapName.value = randomWackyMapName();
        dirty.value = true;
        bumpTerrainRender();
        bumpMarkersRender();
        requestMapViewFit();
    }

    function generateAndApplyMap(seed?: number, type?: MapGenerationType, teamCountArg?: number): void {
        applyGeneratedMap(
            generateRandomMap({
                seed,
                type,
                cellRows: gridRows.value,
                cellCols: gridCols.value,
                teamCount: teamCountArg,
            }),
        );
    }

    function nextUnusedPaletteSlot(used: readonly number[]): number {
        for (let s = 0; s < MAP_MAX_TEAMS; s++) {
            if (!used.includes(s)) {
                return s;
            }
        }

        return MAP_MAX_TEAMS - 1;
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
        const prev = teamCount.value;
        teamCount.value = n;
        markers.value = markers.value.filter((m) => m.team < n);

        if (n < prev) {
            teamPaletteSlots.value = teamPaletteSlots.value.slice(0, n);
        } else if (n > prev) {
            const slots = [...teamPaletteSlots.value];

            while (slots.length < n) {
                slots.push(nextUnusedPaletteSlot(slots));
            }

            teamPaletteSlots.value = slots;
        }

        clampSelectedTeamToTeamCount();
        dirty.value = true;
        bumpMarkersRender();
    }

    /**
     * Removes one team slot: deletes that team's markers and decrements {@link teamCount},
     * remapping higher team indices down by one. {@link teamPaletteSlots} is spliced in parallel
     * so surviving teams keep their faction colours.
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
        const nextSlots = [...teamPaletteSlots.value];
        nextSlots.splice(slot, 1);
        teamPaletteSlots.value = nextSlots;

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
        teamPaletteSlots,
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
