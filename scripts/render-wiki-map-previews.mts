/**
 * Writes deterministic SVG previews for wiki / README:
 * - Map Builder generation styles (full map renders)
 * - Individual terrain swatches (editor-blended tile patches)
 * Run: npm run wiki:map-previews
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { MapGenerationType } from '@/lib/generateRandomMap';
import { generateRandomMap } from '@/lib/generateRandomMap';
import { EDITOR_TERRAIN_COLORS, TERRAIN_IDS, isTerrainId, type TerrainId } from '@/lib/terrainCatalog';
import { editorBlendedTerrainFillStyle } from '@/lib/terrainRender';

const __dirname = dirname(fileURLToPath(import.meta.url));
const outDir = join(__dirname, '..', 'public', 'images', 'wiki');

const types: MapGenerationType[] = ['mix', 'islands', 'desert', 'mountains'];
const cellRows = 48;
const cellCols = 56;
const cellPx = 4;
const seed = 4_242_42;

function fillFor(terrainId: string): string {
    return isTerrainId(terrainId) ? EDITOR_TERRAIN_COLORS[terrainId] : '#888888';
}

mkdirSync(outDir, { recursive: true });

for (const type of types) {
    const data = generateRandomMap({
        type,
        seed,
        cellRows,
        cellCols,
        teamCount: 4,
    });

    const parts: string[] = [];

    for (let r = 0; r < cellRows; r++) {
        const row = data.cells[r] ?? [];

        for (let c = 0; c < cellCols; c++) {
            const t = row[c] ?? 'plains';
            const x = c * cellPx;
            const y = r * cellPx;
            parts.push(
                `<rect x="${x}" y="${y}" width="${cellPx}" height="${cellPx}" fill="${fillFor(t)}"/>`,
            );
        }
    }

    const w = cellCols * cellPx;
    const h = cellRows * cellPx;
    const svg = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" shape-rendering="crispEdges">${parts.join('')}</svg>
`;

    const file = join(outDir, `map-generation-${type}.svg`);
    writeFileSync(file, svg, 'utf8');
    console.log('Wrote', file);
}

const swatchGrid = 9;
const swatchCellPx = 6;
const swatchCenter = Math.floor(swatchGrid / 2);
const swatchRadius = 2;

function terrainSwatchCells(terrainId: TerrainId): string[][] {
    const cells = Array.from({ length: swatchGrid }, () =>
        Array.from({ length: swatchGrid }, () => 'plains'),
    );

    for (let r = swatchCenter - swatchRadius; r <= swatchCenter + swatchRadius; r++) {
        for (let c = swatchCenter - swatchRadius; c <= swatchCenter + swatchRadius; c++) {
            cells[r][c] = terrainId;
        }
    }

    return cells;
}

function writeTerrainSwatch(terrainId: TerrainId): void {
    const cells = terrainSwatchCells(terrainId);
    const parts: string[] = [];

    for (let r = 0; r < swatchGrid; r++) {
        for (let c = 0; c < swatchGrid; c++) {
            const fill = editorBlendedTerrainFillStyle(cells, r, c);
            const x = c * swatchCellPx;
            const y = r * swatchCellPx;
            parts.push(
                `<rect x="${x}" y="${y}" width="${swatchCellPx}" height="${swatchCellPx}" fill="${fill}"/>`,
            );
        }
    }

    const inner = swatchGrid * swatchCellPx;
    const pad = 2;
    const w = inner + pad * 2;
    const h = inner + pad * 2;
    const svg = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" shape-rendering="crispEdges">
  <rect x="0" y="0" width="${w}" height="${h}" fill="${EDITOR_TERRAIN_COLORS.plains}"/>
  <g transform="translate(${pad},${pad})">${parts.join('')}</g>
  <rect x="0.5" y="0.5" width="${w - 1}" height="${h - 1}" fill="none" stroke="#111" stroke-width="1"/>
</svg>
`;

    const file = join(outDir, `terrain-${terrainId}.svg`);
    writeFileSync(file, svg, 'utf8');
    console.log('Wrote', file);
}

for (const terrainId of TERRAIN_IDS) {
    writeTerrainSwatch(terrainId);
}
