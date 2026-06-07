import type { MapMarker } from '@/lib/mapEditorGrid';
import { isFarEnoughFromHydraulicWaterForMapMarker, isPlaceableTerrain } from '@/lib/mapMarkers';
import {
    computeMinSeparationForMapState,
    countPlaceableLandCells,
    inferCapitalSpacing,
    MAP_MARKER_MIN_MANHATTAN_SEP,
    manhattanDistance,
    troopManhattanClearanceToMarker,
} from '@/lib/mapMarkerSpacing';
import { isTerrainId, isWaterTerrain } from '@/lib/terrainCatalog';

type Cell = { row: number; col: number };

const ORTHO: ReadonlyArray<readonly [number, number]> = [
    [1, 0],
    [-1, 0],
    [0, 1],
    [0, -1],
];

/** Baseline when map / border capacity is small (matches earlier generator defaults). */
export const BASE_GENERATED_INFANTRY_PER_TEAM = 12;

export const BASE_GENERATED_TANKS_PER_TEAM = 4;

export type TroopSpawnGenerationOptions = {
    /**
     * Archipelago / island profile: shorter fronts — use a shallower inland band and
     * targets tied to measured border length.
     */
    islandLike?: boolean;
};

function shuffleInPlace<T>(arr: T[], rng: () => number): void {
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(rng() * (i + 1));
        const t = arr[i]!;
        arr[i] = arr[j]!;
        arr[j] = t;
    }
}

function cellKey(r: number, c: number): string {
    return `${r},${c}`;
}

function isStrictInterTeamBorder(
    owner: number[][],
    rows: number,
    cols: number,
    r: number,
    c: number,
    team: number,
): boolean {
    if ((owner[r]?.[c] ?? -1) !== team) {
        return false;
    }

    for (const [dr, dc] of ORTHO) {
        const nr = r + dr;
        const nc = c + dc;

        if (nr < 0 || nr >= rows || nc < 0 || nc >= cols) {
            continue;
        }

        const o = owner[nr]?.[nc] ?? -1;

        if (o >= 0 && o !== team) {
            return true;
        }
    }

    return false;
}

/**
 * True when ortho-adjacent to water / river (shoreline) or to another team's land — used on
 * island maps where Voronoi "strict" borders are empty because factions only meet across sea.
 */
function isShoreOrContestNeighbor(
    cells: string[][],
    owner: number[][],
    rows: number,
    cols: number,
    r: number,
    c: number,
    team: number,
): boolean {
    for (const [dr, dc] of ORTHO) {
        const nr = r + dr;
        const nc = c + dc;

        if (nr < 0 || nr >= rows || nc < 0 || nc >= cols) {
            continue;
        }

        const terr = cells[nr]?.[nc];

        if (typeof terr === 'string' && isTerrainIdWaterOrRiver(terr)) {
            return true;
        }

        const o = owner[nr]?.[nc] ?? -1;

        if (o >= 0 && o !== team) {
            return true;
        }
    }

    return false;
}

function isTerrainIdWaterOrRiver(terr: string): boolean {
    return isTerrainId(terr) && isWaterTerrain(terr);
}

/** Troop placement "front": land border with another faction, or (islands) shoreline / contest. */
function isTroopFrontCell(
    cells: string[][],
    owner: number[][],
    rows: number,
    cols: number,
    r: number,
    c: number,
    team: number,
    islandLike: boolean,
): boolean {
    if (isStrictInterTeamBorder(owner, rows, cols, r, c, team)) {
        return true;
    }

    if (!islandLike) {
        return false;
    }

    return isShoreOrContestNeighbor(cells, owner, rows, cols, r, c, team);
}

/**
 * Unoccupied placeable cells ortho-adjacent to a team's capital or flags — real BFS seeds
 * (marker cells themselves sit in `occupied` and must not be passed as seeds).
 */
function collectAdjacentAnchorSeedsForTeam(
    cells: string[][],
    rows: number,
    cols: number,
    owner: number[][],
    team: number,
    markers: ReadonlyArray<MapMarker>,
    occupied: ReadonlySet<string>,
): Cell[] {
    const out: Cell[] = [];
    const seen = new Set<string>();

    for (const m of markers) {
        if (m.team !== team || (m.type !== 'capital' && m.type !== 'flag')) {
            continue;
        }

        for (const [dr, dc] of ORTHO) {
            const nr = m.row + dr;
            const nc = m.col + dc;
            const k = cellKey(nr, nc);

            if (seen.has(k)) {
                continue;
            }

            if (nr < 0 || nr >= rows || nc < 0 || nc >= cols || occupied.has(k)) {
                continue;
            }

            if ((owner[nr]?.[nc] ?? -1) !== team) {
                continue;
            }

            const terr = cells[nr]?.[nc];

            if (
                typeof terr !== 'string'
                || !isPlaceableTerrain(terr)
                || !isFarEnoughFromHydraulicWaterForMapMarker(cells, rows, cols, nr, nc)
            ) {
                continue;
            }

            seen.add(k);
            out.push({ row: nr, col: nc });
        }
    }

    return out;
}

function collectCoastalOwnedSeedsForTeam(
    cells: string[][],
    rows: number,
    cols: number,
    owner: number[][],
    team: number,
    occupied: ReadonlySet<string>,
    islandLike: boolean,
): Cell[] {
    if (!islandLike) {
        return [];
    }

    const out: Cell[] = [];
    const seen = new Set<string>();

    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            if ((owner[r]?.[c] ?? -1) !== team) {
                continue;
            }

            const k = cellKey(r, c);

            if (occupied.has(k) || seen.has(k)) {
                continue;
            }

            const terr = cells[r]?.[c];

            if (
                typeof terr !== 'string'
                || !isPlaceableTerrain(terr)
                || !isFarEnoughFromHydraulicWaterForMapMarker(cells, rows, cols, r, c)
            ) {
                continue;
            }

            if (!isShoreOrContestNeighbor(cells, owner, rows, cols, r, c, team)) {
                continue;
            }

            seen.add(k);
            out.push({ row: r, col: c });
        }
    }

    return out;
}

/**
 * Multi-source BFS from strict inter-team border cells into own territory up to `maxDepth`
 * steps, producing a thickened “front band” (not only the one-pixel Voronoi cut).
 */
function buildFrontierBandForTeam(
    cells: string[][],
    rows: number,
    cols: number,
    owner: number[][],
    team: number,
    seeds: Cell[],
    occupied: ReadonlySet<string>,
    maxDepth: number,
): Cell[] {
    const seen = new Set<string>();
    const out: Cell[] = [];
    type Q = { row: number; col: number; depth: number };
    const queue: Q[] = [];

    for (const s of seeds) {
        const k = cellKey(s.row, s.col);

        if (occupied.has(k) || seen.has(k)) {
            continue;
        }

        const terr = cells[s.row]?.[s.col];

        if (
            typeof terr !== 'string'
            || !isPlaceableTerrain(terr)
            || !isFarEnoughFromHydraulicWaterForMapMarker(cells, rows, cols, s.row, s.col)
        ) {
            continue;
        }

        if ((owner[s.row]?.[s.col] ?? -1) !== team) {
            continue;
        }

        seen.add(k);
        queue.push({ row: s.row, col: s.col, depth: 0 });
        out.push({ row: s.row, col: s.col });
    }

    let qi = 0;

    while (qi < queue.length) {
        const { row: r, col: c, depth } = queue[qi]!;
        qi += 1;

        if (depth >= maxDepth) {
            continue;
        }

        for (const [dr, dc] of ORTHO) {
            const nr = r + dr;
            const nc = c + dc;
            const nk = cellKey(nr, nc);

            if (nr < 0 || nr >= rows || nc < 0 || nc >= cols || seen.has(nk) || occupied.has(nk)) {
                continue;
            }

            if ((owner[nr]?.[nc] ?? -1) !== team) {
                continue;
            }

            const terr = cells[nr]?.[nc];

            if (
                typeof terr !== 'string'
                || !isPlaceableTerrain(terr)
                || !isFarEnoughFromHydraulicWaterForMapMarker(cells, rows, cols, nr, nc)
            ) {
                continue;
            }

            seen.add(nk);
            const next = { row: nr, col: nc, depth: depth + 1 };
            queue.push(next);
            out.push({ row: nr, col: nc });
        }
    }

    return out;
}

/** Minimum total troops per team when the smallest faction still has enough owned land. */
const SYMMETRIC_TROOP_TOTAL_FLOOR = 10;

/**
 * Same infantry and same tank count for every team. Desired counts scale with map size, borders,
 * band supply, and flags; the realised total per team is capped by usable owned land, not only the
 * frontier band (bands can be empty when Voronoi territories do not share an edge).
 */
function computeSymmetricTroopTargets(
    islandLike: boolean,
    minDim: number,
    minPrimaryBorderLen: number,
    minBandLen: number,
    minOwnedPlaceablePerTeam: number,
    capitalCount: number,
    flagsPerTeam: number,
): { infantry: number; tanks: number } {
    let wantInf = BASE_GENERATED_INFANTRY_PER_TEAM;
    let wantTanks = BASE_GENERATED_TANKS_PER_TEAM;

    const band = Math.max(0, minBandLen);
    const border = Math.max(4, minPrimaryBorderLen);
    const outpostInf = Math.round(flagsPerTeam * 2.8);
    const outpostTanks = Math.round(flagsPerTeam * 0.55);
    const capitalInf = Math.round(capitalCount * 2.5);

    if (islandLike) {
        const scaleBorder = Math.floor(border * 0.72);
        wantInf = Math.min(
            40,
            Math.max(12, scaleBorder + outpostInf + capitalInf),
        );
        wantTanks = Math.min(10, Math.max(3, Math.floor(border * 0.18) + outpostTanks + 1));
    } else {
        const scale = Math.min(2.65, 0.75 + minDim / 88);
        wantInf = Math.min(
            80,
            Math.max(
                18,
                Math.round(
                    BASE_GENERATED_INFANTRY_PER_TEAM * scale
                        + band * 0.055
                        + border * 0.14
                        + outpostInf
                        + capitalInf,
                ),
            ),
        );
        wantTanks = Math.min(
            20,
            Math.max(
                5,
                Math.round(BASE_GENERATED_TANKS_PER_TEAM * scale + band * 0.018 + outpostTanks + 1),
            ),
        );
    }

    const wantTotal = wantInf + wantTanks;
    const owned = Math.max(0, Math.floor(minOwnedPlaceablePerTeam));
    let cap = Math.min(wantTotal, owned);

    if (owned >= SYMMETRIC_TROOP_TOTAL_FLOOR) {
        cap = Math.max(cap, Math.min(SYMMETRIC_TROOP_TOTAL_FLOOR, wantTotal, owned));
    }

    cap = Math.max(0, Math.min(cap, wantTotal, owned));

    const wSum = wantInf + wantTanks;
    let infantry = wSum > 0 ? Math.round((cap * wantInf) / wSum) : 0;
    let tanks = cap - infantry;

    infantry = Math.min(infantry, wantInf);
    tanks = Math.min(tanks, wantTanks);

    while (infantry + tanks < cap && (infantry < wantInf || tanks < wantTanks)) {
        if (tanks < wantTanks && infantry < wantInf) {
            const rInf = infantry / wantInf;
            const rTank = tanks / wantTanks;

            if (rTank >= rInf) {
                tanks += 1;
            } else {
                infantry += 1;
            }
        } else if (tanks < wantTanks) {
            tanks += 1;
        } else if (infantry < wantInf) {
            infantry += 1;
        } else {
            break;
        }
    }

    while (infantry + tanks > cap) {
        if (tanks > 0) {
            tanks -= 1;
        } else {
            infantry = Math.max(0, infantry - 1);
        }
    }

    return { infantry, tanks };
}

function canPlaceTroopHere(
    row: number,
    col: number,
    sep: number,
    troopSep: number,
    sepBlockingMarkers: ReadonlyArray<MapMarker>,
    troopTeam: number,
    troopSites: ReadonlyArray<{ row: number; col: number }>,
): boolean {
    const p = { row, col };

    for (const m of sepBlockingMarkers) {
        if (m.type !== 'capital' && m.type !== 'flag') {
            continue;
        }

        const need = troopManhattanClearanceToMarker(
            sep,
            m.team,
            troopTeam,
            m.type === 'capital' ? 'capital' : 'flag',
        );

        if (manhattanDistance(p, { row: m.row, col: m.col }) < need) {
            return false;
        }
    }

    for (const t of troopSites) {
        if (manhattanDistance(p, t) < troopSep) {
            return false;
        }
    }

    return true;
}

function minManhattanToMarkers(row: number, col: number, sites: ReadonlyArray<MapMarker>): number {
    if (sites.length === 0) {
        return Infinity;
    }

    const p = { row, col };
    let best = Infinity;

    for (const s of sites) {
        best = Math.min(best, manhattanDistance(p, { row: s.row, col: s.col }));
    }

    return best;
}

function minOwnedPlaceableCellsPerTeam(
    cells: string[][],
    rows: number,
    cols: number,
    owner: number[][],
    teamCount: number,
    occupied: ReadonlySet<string>,
): number {
    const counts = new Array(teamCount).fill(0) as number[];

    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            const t = owner[r]?.[c] ?? -1;

            if (t < 0 || t >= teamCount) {
                continue;
            }

            const k = cellKey(r, c);

            if (occupied.has(k)) {
                continue;
            }

            const terr = cells[r]?.[c];

            if (
                typeof terr !== 'string'
                || !isPlaceableTerrain(terr)
                || !isFarEnoughFromHydraulicWaterForMapMarker(cells, rows, cols, r, c)
            ) {
                continue;
            }

            counts[t]! += 1;
        }
    }

    return counts.length > 0 ? Math.min(...counts) : 0;
}

function eachTeamHasAtLeastOneFreeOwnedPlaceableCell(
    cells: string[][],
    rows: number,
    cols: number,
    owner: number[][],
    teamCount: number,
    markerOccupied: ReadonlySet<string>,
): boolean {
    const noopRng = (): number => 0;

    for (let team = 0; team < teamCount; team++) {
        if (
            buildAllFreeOwnedPlaceableCellsForTeam(
                cells,
                rows,
                cols,
                owner,
                team,
                markerOccupied,
                noopRng,
            ).length === 0
        ) {
            return false;
        }
    }

    return true;
}

/**
 * All passable, non-marker land cells owned by `team` in the Voronoi sense (for emergency troop
 * placement when frontier bands are empty or full spacing cannot be satisfied).
 */
function buildAllFreeOwnedPlaceableCellsForTeam(
    cells: string[][],
    rows: number,
    cols: number,
    owner: number[][],
    team: number,
    markerOccupied: ReadonlySet<string>,
    rng: () => number,
): Cell[] {
    const out: Cell[] = [];

    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            if ((owner[r]?.[c] ?? -1) !== team) {
                continue;
            }

            const k = cellKey(r, c);

            if (markerOccupied.has(k)) {
                continue;
            }

            const terr = cells[r]?.[c];

            if (
                typeof terr !== 'string'
                || !isPlaceableTerrain(terr)
                || !isFarEnoughFromHydraulicWaterForMapMarker(cells, rows, cols, r, c)
            ) {
                continue;
            }

            out.push({ row: r, col: c });
        }
    }

    shuffleInPlace(out, rng);

    return out;
}

function mergeCandidateCellsUnique(a: readonly Cell[], b: readonly Cell[], rng: () => number): Cell[] {
    const map = new Map<string, Cell>();

    for (const c of a) {
        map.set(cellKey(c.row, c.col), c);
    }

    for (const c of b) {
        map.set(cellKey(c.row, c.col), c);
    }

    const out = [...map.values()];
    shuffleInPlace(out, rng);

    return out;
}

function collectExtraOwnedCandidatesForTeam(
    cells: string[][],
    rows: number,
    cols: number,
    owner: number[][],
    team: number,
    occupied: ReadonlySet<string>,
    existingKeys: ReadonlySet<string>,
    maxAdd: number,
): Cell[] {
    const out: Cell[] = [];

    for (let r = 0; r < rows && out.length < maxAdd; r++) {
        for (let c = 0; c < cols && out.length < maxAdd; c++) {
            if ((owner[r]?.[c] ?? -1) !== team) {
                continue;
            }

            const k = cellKey(r, c);

            if (occupied.has(k) || existingKeys.has(k)) {
                continue;
            }

            const terr = cells[r]?.[c];

            if (
                typeof terr !== 'string'
                || !isPlaceableTerrain(terr)
                || !isFarEnoughFromHydraulicWaterForMapMarker(cells, rows, cols, r, c)
            ) {
                continue;
            }

            out.push({ row: r, col: c });
        }
    }

    return out;
}

function scoreTroopCandidateCell(
    cells: string[][],
    row: number,
    col: number,
    team: number,
    rows: number,
    cols: number,
    owner: number[][],
    ownCapitals: ReadonlyArray<MapMarker>,
    ownFlags: ReadonlyArray<MapMarker>,
    sameTeamTroopKeys: ReadonlySet<string>,
    islandLike: boolean,
    rng: () => number,
): number {
    let s = rng() * 0.04;
    const onFront = isTroopFrontCell(cells, owner, rows, cols, row, col, team, islandLike);

    if (onFront) {
        s += 28;
    } else {
        s += 0.35;
    }

    const dCap = minManhattanToMarkers(row, col, ownCapitals);
    const dFlag = minManhattanToMarkers(row, col, ownFlags);

    if (onFront) {
        if (Number.isFinite(dCap)) {
            s += 4 / (1 + dCap);
        }

        if (Number.isFinite(dFlag)) {
            s += 3 / (1 + Math.min(dFlag, 48));
        }
    } else {
        if (Number.isFinite(dCap)) {
            s += 1.2 / (1 + dCap);
        }

        if (Number.isFinite(dFlag)) {
            s += 0.9 / (1 + Math.min(dFlag, 72));
        }
    }

    let adjSame = 0;

    for (const [dr, dc] of ORTHO) {
        const nk = cellKey(row + dr, col + dc);

        if (sameTeamTroopKeys.has(nk)) {
            adjSame += 1;
        }
    }

    if (adjSame === 1) {
        s += onFront ? 22 : 10;
    } else if (adjSame === 2) {
        s += onFront ? 14 : 6;
    } else if (adjSame > 2) {
        s -= (adjSame - 2) * (onFront ? 2.2 : 4);
    }

    return s;
}

function pickNextTroopTypeForTeam(
    team: number,
    targetInfantry: number,
    targetTanks: number,
    placedInf: readonly number[],
    placedTank: readonly number[],
): 'infantry' | 'tank' | null {
    const pi = placedInf[team] ?? 0;
    const pt = placedTank[team] ?? 0;

    if (pi >= targetInfantry && pt >= targetTanks) {
        return null;
    }

    if (pi >= targetInfantry) {
        return 'tank';
    }

    if (pt >= targetTanks) {
        return 'infantry';
    }

    if (targetInfantry <= 0) {
        return 'tank';
    }

    if (targetTanks <= 0) {
        return 'infantry';
    }

    const rInf = pi / targetInfantry;
    const rTank = pt / targetTanks;

    return rTank >= rInf ? 'tank' : 'infantry';
}

/**
 * Places one troop at a time in team rotation so early factions do not consume the entire frontier
 * under global troop–troop spacing.
 */
function placeSymmetricTroopsRoundRobin(
    teamCount: number,
    enrichedCandidatesByTeam: ReadonlyArray<Cell[]>,
    cells: string[][],
    rows: number,
    cols: number,
    owner: number[][],
    targetInfantry: number,
    targetTanks: number,
    sep: number,
    troopSep: number,
    sepBlockingMarkers: ReadonlyArray<MapMarker>,
    markers: ReadonlyArray<MapMarker>,
    markerOccupied: ReadonlySet<string>,
    islandLike: boolean,
    rng: () => number,
): MapMarker[] {
    const ownCapitalsByTeam: MapMarker[][] = Array.from({ length: teamCount }, () => []);
    const ownFlagsByTeam: MapMarker[][] = Array.from({ length: teamCount }, () => []);

    for (const m of markers) {
        if (m.type === 'capital' && m.team >= 0 && m.team < teamCount) {
            ownCapitalsByTeam[m.team]!.push(m);
        }

        if (m.type === 'flag' && m.team >= 0 && m.team < teamCount) {
            ownFlagsByTeam[m.team]!.push(m);
        }
    }

    const out: MapMarker[] = [];
    const troopSitesGlobal: { row: number; col: number }[] = [];
    const placedInf = new Array(teamCount).fill(0) as number[];
    const placedTank = new Array(teamCount).fill(0) as number[];
    const sameKeysByTeam = Array.from({ length: teamCount }, () => new Set<string>());
    const remainingByTeam = enrichedCandidatesByTeam.map(
        (cellsList) => new Set(cellsList.map((c) => cellKey(c.row, c.col))),
    );

    const allPlaced = (): boolean => {
        for (let t = 0; t < teamCount; t++) {
            if (placedInf[t]! < targetInfantry || placedTank[t]! < targetTanks) {
                return false;
            }
        }

        return true;
    };

    let stallWaves = 0;

    while (!allPlaced()) {
        let progress = false;
        const waveOrder = Array.from({ length: teamCount }, (_, i) => i);
        waveOrder.sort((a, b) => {
            const deficit = (t: number) =>
                targetInfantry - placedInf[t]! + (targetTanks - placedTank[t]!);
            const d = deficit(b) - deficit(a);

            if (d !== 0) {
                return d;
            }

            return a - b;
        });

        for (const team of waveOrder) {
            const kind = pickNextTroopTypeForTeam(
                team,
                targetInfantry,
                targetTanks,
                placedInf,
                placedTank,
            );

            if (kind === null) {
                continue;
            }

            const remaining = remainingByTeam[team]!;

            if (remaining.size === 0) {
                continue;
            }

            let bestKey: string | null = null;
            let bestScore = -Infinity;

            for (const k of remaining) {
                const [rs, cs] = k.split(',');
                const row = Number(rs);
                const col = Number(cs);

                if (!Number.isInteger(row) || !Number.isInteger(col)) {
                    continue;
                }

                if (
                    !canPlaceTroopHere(
                        row,
                        col,
                        sep,
                        troopSep,
                        sepBlockingMarkers,
                        team,
                        troopSitesGlobal,
                    )
                ) {
                    continue;
                }

                const sc = scoreTroopCandidateCell(
                    cells,
                    row,
                    col,
                    team,
                    rows,
                    cols,
                    owner,
                    ownCapitalsByTeam[team]!,
                    ownFlagsByTeam[team]!,
                    sameKeysByTeam[team]!,
                    islandLike,
                    rng,
                );

                if (sc > bestScore || (sc === bestScore && rng() < 0.5)) {
                    bestScore = sc;
                    bestKey = k;
                }
            }

            if (bestKey === null) {
                continue;
            }

            const [brs, bcs] = bestKey.split(',');
            const row = Number(brs);
            const col = Number(bcs);
            remaining.delete(bestKey);

            if (kind === 'tank') {
                out.push({ type: 'tank', team, row, col });
                placedTank[team]! += 1;
            } else {
                out.push({ type: 'infantry', team, row, col });
                placedInf[team]! += 1;
            }

            troopSitesGlobal.push({ row, col });
            sameKeysByTeam[team]!.add(bestKey);
            progress = true;
        }

        if (progress) {
            stallWaves = 0;
        } else {
            stallWaves += 1;

            if (stallWaves < 2) {
                continue;
            }

            let expanded = false;

            for (let team = 0; team < teamCount; team++) {
                if (placedInf[team]! >= targetInfantry && placedTank[team]! >= targetTanks) {
                    continue;
                }

                const extras = collectExtraOwnedCandidatesForTeam(
                    cells,
                    rows,
                    cols,
                    owner,
                    team,
                    markerOccupied,
                    remainingByTeam[team]!,
                    520,
                );

                for (const c of extras) {
                    const k = cellKey(c.row, c.col);

                    if (!remainingByTeam[team]!.has(k)) {
                        remainingByTeam[team]!.add(k);
                        expanded = true;
                    }
                }
            }

            if (!expanded) {
                break;
            }

            stallWaves = 0;
        }
    }

    return out;
}

function countTroopMarkersPerTeam(
    troops: readonly MapMarker[],
    teamCount: number,
): { inf: number[]; tank: number[] } {
    const inf = new Array(teamCount).fill(0) as number[];
    const tank = new Array(teamCount).fill(0) as number[];

    for (const m of troops) {
        if (m.type === 'infantry') {
            inf[m.team]! += 1;
        } else if (m.type === 'tank') {
            tank[m.team]! += 1;
        }
    }

    return { inf, tank };
}

/**
 * Drop surplus troops from teams above the minimum so every faction matches the smallest infantry
 * and smallest tank counts (removes interior / non-front spawns first when possible).
 */
function symmetricTrimTroopMarkers(
    troops: MapMarker[],
    teamCount: number,
    cells: string[][],
    rows: number,
    cols: number,
    owner: number[][],
    islandLike: boolean,
): MapMarker[] {
    const { inf, tank } = countTroopMarkersPerTeam(troops, teamCount);
    const minI = Math.min(...inf);
    const maxI = Math.max(...inf);
    const minT = Math.min(...tank);
    const maxT = Math.max(...tank);

    if (minI === maxI && minT === maxT) {
        return troops;
    }

    const toRemove = new Set<string>();

    const removalTier = (m: MapMarker): number => {
        return isTroopFrontCell(cells, owner, rows, cols, m.row, m.col, m.team, islandLike)
            ? 1
            : 0;
    };

    if (maxI > minI) {
        if (minI > 0) {
            for (let t = 0; t < teamCount; t++) {
                if (inf[t]! <= minI) {
                    continue;
                }

                const take = inf[t]! - minI;
                const cands = troops.filter((m) => m.team === t && m.type === 'infantry');
                cands.sort((a, b) => removalTier(a) - removalTier(b));

                for (let i = 0; i < take && i < cands.length; i++) {
                    toRemove.add(cellKey(cands[i]!.row, cands[i]!.col));
                }
            }
        }
    }

    if (maxT > minT) {
        if (minT > 0) {
            for (let t = 0; t < teamCount; t++) {
                if (tank[t]! <= minT) {
                    continue;
                }

                const take = tank[t]! - minT;
                const cands = troops.filter(
                    (m) =>
                        m.team === t
                        && m.type === 'tank'
                        && !toRemove.has(cellKey(m.row, m.col)),
                );
                cands.sort((a, b) => removalTier(a) - removalTier(b));

                for (let i = 0; i < take && i < cands.length; i++) {
                    toRemove.add(cellKey(cands[i]!.row, cands[i]!.col));
                }
            }
        }
    }

    if (toRemove.size === 0) {
        return troops;
    }

    return troops.filter((m) => !toRemove.has(cellKey(m.row, m.col)));
}

/**
 * After capitals exist, assign each placeable land cell to the nearest capital (Manhattan).
 * Troops use a symmetric round-robin fill on a merged frontier band plus an anchored corridor
 * from each team's capital and flags toward borders, matching {@see App\Maps\MapMarkers::validate}
 * spacing to capitals, flags, and other troops.
 */
export function buildTroopMarkersForGeneratedMap(
    cells: string[][],
    rows: number,
    cols: number,
    markers: MapMarker[],
    teamCount: number,
    rng: () => number,
    options?: TroopSpawnGenerationOptions,
): MapMarker[] {
    const islandLike = options?.islandLike === true;
    const capitals = markers.filter((m) => m.type === 'capital');

    if (capitals.length === 0 || capitals.length !== teamCount) {
        return [];
    }

    const markerOccupied = new Set(markers.map((m) => `${m.row},${m.col}`));
    const occupied = new Set(markerOccupied);

    const capitalPositions = capitals.map((c) => ({ row: c.row, col: c.col }));
    const nLand = countPlaceableLandCells(cells, rows, cols);
    const nonCapitalCount = markers.filter((m) => m.type !== 'capital').length;
    const flagBudget = Math.max(
        nonCapitalCount + 1,
        teamCount * 2,
        Math.min(320, Math.max(48, Math.floor(nLand / 3))),
    );
    const sep = Math.min(
        computeMinSeparationForMapState({
            cells,
            rows,
            cols,
            teamCount,
            capitalPositions,
            flagBudget,
        }),
        Math.max(MAP_MARKER_MIN_MANHATTAN_SEP, inferCapitalSpacing(capitals)),
    );
    const troopSep = Math.max(2, Math.floor(sep / 2));

    const markersSepBlocking = markers.filter((m) => m.type === 'capital' || m.type === 'flag');

    const owner: number[][] = Array.from({ length: rows }, () => Array(cols).fill(-1));

    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            const terr = cells[r]?.[c];

            if (
                typeof terr !== 'string'
                || !isPlaceableTerrain(terr)
                || !isFarEnoughFromHydraulicWaterForMapMarker(cells, rows, cols, r, c)
            ) {
                continue;
            }

            let bestD = Infinity;
            let bestTeam = 0;

            for (const cap of capitals) {
                const d = Math.abs(r - cap.row) + Math.abs(c - cap.col);

                if (d < bestD || (d === bestD && cap.team < bestTeam)) {
                    bestD = d;
                    bestTeam = cap.team;
                }
            }

            owner[r]![c] = bestTeam;
        }
    }

    const borderByTeam: Map<number, Cell[]> = new Map();

    for (let t = 0; t < teamCount; t++) {
        borderByTeam.set(t, []);
    }

    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            const t = owner[r]?.[c] ?? -1;

            if (t < 0) {
                continue;
            }

            if (isStrictInterTeamBorder(owner, rows, cols, r, c, t)) {
                borderByTeam.get(t)!.push({ row: r, col: c });
            }
        }
    }

    const primaryLens: number[] = [];

    for (let team = 0; team < teamCount; team++) {
        primaryLens.push(borderByTeam.get(team)?.length ?? 0);
    }

    const minPrimaryBorderLen = primaryLens.length > 0 ? Math.min(...primaryLens) : 0;
    const minDim = Math.min(rows, cols);

    const bandDepth = islandLike ? 3 : minDim >= 120 ? 5 : 4;
    const anchorDepth = islandLike ? 6 : minDim >= 120 ? 11 : 9;

    const bandsByTeam: Cell[][] = [];
    const anchorsByTeam: Cell[][] = [];

    for (let team = 0; team < teamCount; team++) {
        const primary = borderByTeam.get(team) ?? [];
        const coastal = collectCoastalOwnedSeedsForTeam(
            cells,
            rows,
            cols,
            owner,
            team,
            occupied,
            islandLike,
        );
        const seedCells = mergeCandidateCellsUnique(
            primary.filter((p) => !occupied.has(cellKey(p.row, p.col))),
            coastal,
            rng,
        );
        const band = buildFrontierBandForTeam(
            cells,
            rows,
            cols,
            owner,
            team,
            seedCells,
            occupied,
            bandDepth,
        );
        bandsByTeam.push(band);

        const anchorSeeds = collectAdjacentAnchorSeedsForTeam(
            cells,
            rows,
            cols,
            owner,
            team,
            markers,
            occupied,
        );

        shuffleInPlace(anchorSeeds, rng);

        const anchorBand = buildFrontierBandForTeam(
            cells,
            rows,
            cols,
            owner,
            team,
            anchorSeeds,
            occupied,
            anchorDepth,
        );
        anchorsByTeam.push(anchorBand);
    }

    const minBandLen = bandsByTeam.length > 0 ? Math.min(...bandsByTeam.map((b) => b.length)) : 0;

    const minOwnedPlaceable = minOwnedPlaceableCellsPerTeam(
        cells,
        rows,
        cols,
        owner,
        teamCount,
        occupied,
    );

    const flagCount = markers.filter((m) => m.type === 'flag').length;
    const flagsPerTeam = teamCount > 0 ? flagCount / teamCount : 0;

    const targets = computeSymmetricTroopTargets(
        islandLike,
        minDim,
        minPrimaryBorderLen,
        minBandLen,
        minOwnedPlaceable,
        capitals.length,
        flagsPerTeam,
    );

    let initialInfantryTarget = targets.infantry;
    let initialTankTarget = targets.tanks;

    if (initialInfantryTarget + initialTankTarget === 0) {
        if (
            capitals.length !== teamCount
            || !eachTeamHasAtLeastOneFreeOwnedPlaceableCell(
                cells,
                rows,
                cols,
                owner,
                teamCount,
                markerOccupied,
            )
        ) {
            return [];
        }

        initialInfantryTarget = 2;
        initialTankTarget = 0;
    }

    const enrichedByTeam: Cell[][] = [];

    for (let team = 0; team < teamCount; team++) {
        enrichedByTeam.push(
            mergeCandidateCellsUnique(bandsByTeam[team] ?? [], anchorsByTeam[team] ?? [], rng),
        );
    }

    let ti = initialInfantryTarget;
    let tt = initialTankTarget;
    let troops: MapMarker[] = [];

    for (let attempt = 0; attempt < 12; attempt++) {
        troops = placeSymmetricTroopsRoundRobin(
            teamCount,
            enrichedByTeam,
            cells,
            rows,
            cols,
            owner,
            ti,
            tt,
            sep,
            troopSep,
            markersSepBlocking,
            markers,
            markerOccupied,
            islandLike,
            rng,
        );

        troops = symmetricTrimTroopMarkers(
            troops,
            teamCount,
            cells,
            rows,
            cols,
            owner,
            islandLike,
        );

        const c2 = countTroopMarkersPerTeam(troops, teamCount);
        const symmetricCounts =
            Math.min(...c2.inf) === Math.max(...c2.inf)
            && Math.min(...c2.tank) === Math.max(...c2.tank);
        const hasAnyTroops = troops.length > 0;

        if (symmetricCounts && (hasAnyTroops || ti + tt === 0)) {
            return troops;
        }

        if (ti + tt <= 4) {
            if (troops.length > 0) {
                return troops;
            }

            break;
        }

        ti = Math.max(2, ti - 3);
        tt = Math.max(1, tt - 1);
    }

    if (troops.length === 0 && initialInfantryTarget + initialTankTarget > 0) {
        const fullByTeam: Cell[][] = [];

        for (let team = 0; team < teamCount; team++) {
            fullByTeam.push(
                buildAllFreeOwnedPlaceableCellsForTeam(
                    cells,
                    rows,
                    cols,
                    owner,
                    team,
                    markerOccupied,
                    rng,
                ),
            );
        }

        const sepRel = Math.min(sep, 6);
        const troopSepRel = 2;
        const maxK = Math.min(8, initialInfantryTarget);

        for (let k = maxK; k >= 1; k -= 1) {
            const t2 = placeSymmetricTroopsRoundRobin(
                teamCount,
                fullByTeam,
                cells,
                rows,
                cols,
                owner,
                k,
                0,
                sepRel,
                troopSepRel,
                markersSepBlocking,
                markers,
                markerOccupied,
                islandLike,
                rng,
            );
            const trimmed = symmetricTrimTroopMarkers(
                t2,
                teamCount,
                cells,
                rows,
                cols,
                owner,
                islandLike,
            );
            const c2 = countTroopMarkersPerTeam(trimmed, teamCount);
            const symmetricCounts =
                Math.min(...c2.inf) === Math.max(...c2.inf)
                && Math.min(...c2.tank) === Math.max(...c2.tank);

            if (symmetricCounts && trimmed.length > 0) {
                return trimmed;
            }
        }
    }

    return troops;
}
