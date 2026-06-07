import { router } from '@inertiajs/vue3';
import { computed, ref, shallowRef } from 'vue';
import {
    generateRandomMap,
    type GeneratedMapData,
    type MapGenerationType,
} from '@/lib/generateRandomMap';
import {
    MAP_EDITOR_CELL_PX,
    emptyMapPayload,
    isAllowedMapGridSize,
    validateMapGridData,
} from '@/lib/mapEditorGrid';
import type { TerrainId } from '@/lib/terrainCatalog';
import { destroy as destroyMap, show, store, update } from '@/routes/maps';

export type MapEditorTool = 'brush' | 'eraser' | 'fill' | 'pan';

export type MapDataPayload = {
    version: number;
    cellRows: number;
    cellCols: number;
    cells: string[][];
    bridges: boolean[][];
};

function cloneCells(cells: string[][]): string[][] {
    return cells.map((row) => [...row]);
}

function emptyBridges(cellRows: number, cellCols: number): boolean[][] {
    return Array.from({ length: cellRows }, () => Array.from({ length: cellCols }, () => false));
}

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

export function useMapEditor(initialDefaults: MapDataPayload) {
    const cells = ref<string[][]>(cloneCells(initialDefaults.cells));
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

    const undoStack = ref<{ cells: string[][] }[]>([]);
    const redoStack = ref<{ cells: string[][] }[]>([]);

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

    function snapshot(): void {
        undoStack.value.push({
            cells: cloneCells(cells.value),
        });
        if (undoStack.value.length > MAX_UNDO) {
            undoStack.value.shift();
        }
        redoStack.value = [];
    }

    function undo(): void {
        const prev = undoStack.value.pop();
        if (!prev) {
            return;
        }
        redoStack.value.push({
            cells: cloneCells(cells.value),
        });
        cells.value = prev.cells;
        dirty.value = true;
        bumpTerrainRender();
    }

    function redo(): void {
        const next = redoStack.value.pop();
        if (!next) {
            return;
        }
        undoStack.value.push({
            cells: cloneCells(cells.value),
        });
        cells.value = next.cells;
        dirty.value = true;
        bumpTerrainRender();
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
        });
    }

    function beginStroke(): void {
        if (activeTool.value === 'fill' || activeTool.value === 'pan') {
            return;
        }
        snapshot();
        strokeOpen.value = true;
    }

    function endStroke(): void {
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
        dirty.value = true;
        bumpTerrainRender();
    }

    function clickTool(gx: number, gy: number): void {
        if (activeTool.value === 'fill') {
            applyFill(gx, gy);
        }
    }

    function newMap(): void {
        cells.value = cloneCells(initialDefaults.cells);
        mapName.value = 'Untitled map';
        currentUuid.value = null;
        dirty.value = false;
        undoStack.value = [];
        redoStack.value = [];
        bumpTerrainRender();
        requestMapViewFit();
    }

    function newMapWithSize(cellRows: number, cellCols: number): void {
        if (!isAllowedMapGridSize(cellRows, cellCols)) {
            return;
        }
        const payload = emptyMapPayload(cellRows, cellCols);
        cells.value = cloneCells(payload.cells);
        mapName.value = 'Untitled map';
        currentUuid.value = null;
        dirty.value = false;
        undoStack.value = [];
        redoStack.value = [];
        bumpTerrainRender();
        requestMapViewFit();
    }

    function loadFromPayload(payload: MapDataPayload, name: string, uuid: string): void {
        if (
            !validateMapGridData({
                cellRows: payload.cellRows,
                cellCols: payload.cellCols,
                cells: payload.cells,
                bridges: payload.bridges,
            })
        ) {
            return;
        }
        cells.value = cloneCells(payload.cells);
        mapName.value = name;
        currentUuid.value = uuid;
        dirty.value = false;
        undoStack.value = [];
        redoStack.value = [];
        bumpTerrainRender();
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
            version: 1,
            cellRows: rows,
            cellCols: cols,
            cells: cloneCells(cells.value),
            bridges: emptyBridges(rows, cols),
        };
    }

    async function saveMap(): Promise<void> {
        const data = getDataPayload();
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
        router.reload({ only: ['maps'] });
    }

    async function deleteMap(uuid: string): Promise<void> {
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
        router.reload({ only: ['maps'] });
    }

    function applyGeneratedMap(payload: MapDataPayload | GeneratedMapData): void {
        if (
            !validateMapGridData({
                cellRows: payload.cellRows,
                cellCols: payload.cellCols,
                cells: payload.cells,
                bridges: payload.bridges,
            })
        ) {
            return;
        }
        snapshot();
        cells.value = cloneCells(payload.cells);
        dirty.value = true;
        bumpTerrainRender();
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
