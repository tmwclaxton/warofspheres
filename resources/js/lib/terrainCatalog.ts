/**
 * Map editor terrain ids - keep in sync with App\Maps\TerrainCatalog.
 */
export const TERRAIN_IDS = [
    'plains',
    'meadow',
    'forest',
    'dense_forest',
    'hill',
    'mountain',
    'water',
    'deep_water',
    'river',
    'swamp',
    'desert',
    'beach',
    'snow',
] as const;

export type TerrainId = (typeof TERRAIN_IDS)[number];

export const WATER_TERRAINS: ReadonlySet<TerrainId> = new Set([
    'water',
    'deep_water',
    'river',
]);

export const EDITOR_TERRAIN_COLORS: Record<TerrainId, string> = {
    plains: '#c8d68a',
    meadow: '#b8d4a0',
    forest: '#3d6b45',
    dense_forest: '#1e4a28',
    hill: '#d4d4d4',
    mountain: '#5a5a5a',
    water: '#4a90d9',
    deep_water: '#2d5a8c',
    river: '#5ba3e8',
    swamp: '#6b8f7a',
    desert: '#e6c87a',
    beach: '#f5e6b3',
    snow: '#ddeeff',
};

export function isTerrainId(value: string): value is TerrainId {
    return (TERRAIN_IDS as readonly string[]).includes(value);
}

export function isWaterTerrain(id: TerrainId): boolean {
    return WATER_TERRAINS.has(id);
}
