import { MAP_MAX_TEAMS, MAP_MIN_TEAMS } from '@/lib/mapEditorGrid';
import type { MapMarker } from '@/lib/mapEditorGrid';
import {
    computeMinManhattanMarkerSeparation,
    inferCapitalSpacing,
    minPlaceableHaloAmongCapitals,
    preliminaryMaxRForMarkerSpacing,
} from '@/lib/mapMarkerSpacing';
import { isFarEnoughFromHydraulicWaterForMapMarker, isPlaceableTerrain } from '@/lib/mapMarkers';

type Cell = { row: number; col: number };

function manhattan(a: Cell, b: Cell): number {
    return Math.abs(a.row - b.row) + Math.abs(a.col - b.col);
}

function shuffleInPlace<T>(arr: T[], rng: () => number): void {
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(rng() * (i + 1));
        const t = arr[i]!;
        arr[i] = arr[j]!;
        arr[j] = t;
    }
}

function collectPlaceableCells(cells: string[][], rows: number, cols: number): Cell[] {
    const out: Cell[] = [];

    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            const t = cells[r]?.[c];

            if (
                typeof t === 'string' &&
                isPlaceableTerrain(t) &&
                isFarEnoughFromHydraulicWaterForMapMarker(cells, rows, cols, r, c)
            ) {
                out.push({ row: r, col: c });
            }
        }
    }

    return out;
}

/**
 * Greedy pick in fixed visit order: each cell at least `minDist` Manhattan from all chosen.
 */
function greedyFromOrder(order: readonly Cell[], minDist: number, maxPick: number): Cell[] {
    const picked: Cell[] = [];

    for (const cell of order) {
        if (picked.length >= maxPick) {
            break;
        }

        const ok = picked.every((p) => manhattan(p, cell) >= minDist);

        if (ok) {
            picked.push(cell);
        }
    }

    return picked;
}

/**
 * Score for adding a candidate capital: maximize minimum distance to existing capitals, then
 * total distance (uses map area more evenly than min-distance alone).
 */
function capitalSpreadScore(candidate: Cell, existing: readonly Cell[]): { minD: number; sumD: number } {
    let minD = Infinity;
    let sumD = 0;

    for (const p of existing) {
        const d = manhattan(candidate, p);
        minD = Math.min(minD, d);
        sumD += d;
    }

    return { minD, sumD };
}

/**
 * Farthest-point sampling on placeable land (Manhattan): iteratively pick the cell whose
 * distance to already-chosen capitals is largest, breaking ties by larger total distance then RNG.
 */
function pickFarthestCapitals(placeable: readonly Cell[], k: number, rng: () => number): Cell[] {
    if (k <= 0 || placeable.length === 0) {
        return [];
    }

    const target = Math.min(k, placeable.length);
    const picked: Cell[] = [];
    const used = new Set<string>();
    const first = placeable[Math.floor(rng() * placeable.length)]!;

    picked.push(first);
    used.add(`${first.row},${first.col}`);

    while (picked.length < target) {
        let bestMin = -1;
        let bestSum = -1;
        const candidates: Cell[] = [];

        for (const cell of placeable) {
            const key = `${cell.row},${cell.col}`;

            if (used.has(key)) {
                continue;
            }

            const { minD, sumD } = capitalSpreadScore(cell, picked);

            if (minD > bestMin || (minD === bestMin && sumD > bestSum)) {
                bestMin = minD;
                bestSum = sumD;
                candidates.length = 0;
                candidates.push(cell);
            } else if (minD === bestMin && sumD === bestSum) {
                candidates.push(cell);
            }
        }

        if (candidates.length === 0) {
            break;
        }

        const choice = candidates[Math.floor(rng() * candidates.length)]!;
        picked.push(choice);
        used.add(`${choice.row},${choice.col}`);
    }

    return picked;
}

function padCapitalsToMinTeams(capitals: Cell[], placeable: Cell[], minTeams: number): Cell[] {
    const out = capitals.slice();
    const used = new Set(out.map((p) => `${p.row},${p.col}`));

    for (const p of placeable) {
        if (out.length >= minTeams) {
            break;
        }

        const key = `${p.row},${p.col}`;

        if (!used.has(key)) {
            out.push(p);
            used.add(key);
        }
    }

    return out;
}

/**
 * Placeable land cells within Manhattan distance `maxR` of `cap`, ordered nearest first.
 * Within the same distance, order is randomized for variety.
 */
function buildOrderedPlaceableNear(
    cells: string[][],
    rows: number,
    cols: number,
    cap: Cell,
    maxR: number,
    rng: () => number,
): Cell[] {
    const byDistance = new Map<number, Cell[]>();

    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            const terr = cells[r]?.[c];

            if (typeof terr !== 'string' || !isPlaceableTerrain(terr)) {
                continue;
            }

            if (!isFarEnoughFromHydraulicWaterForMapMarker(cells, rows, cols, r, c)) {
                continue;
            }

            const d = manhattan(cap, { row: r, col: c });

            if (d === 0 || d > maxR) {
                continue;
            }

            const bucket = byDistance.get(d) ?? [];

            bucket.push({ row: r, col: c });
            byDistance.set(d, bucket);
        }
    }

    const ordered: Cell[] = [];

    for (const d of [...byDistance.keys()].sort((a, b) => a - b)) {
        const bucket = byDistance.get(d)!;

        shuffleInPlace(bucket, rng);
        ordered.push(...bucket);
    }

    return ordered;
}

/**
 * If placement stalls unevenly, drop surplus flags so every team has the same count (minimum
 * achieved across teams).
 */
function balanceFlagsPerTeamEquality(flags: MapMarker[], teamCount: number): MapMarker[] {
    const buckets: MapMarker[][] = Array.from({ length: teamCount }, () => []);

    for (const f of flags) {
        if (f.type !== 'flag') {
            continue;
        }

        const t = f.team;

        if (t >= 0 && t < teamCount) {
            buckets[t]!.push(f);
        }
    }

    let m = Infinity;

    for (let t = 0; t < teamCount; t++) {
        m = Math.min(m, buckets[t]!.length);
    }

    if (!Number.isFinite(m)) {
        m = 0;
    }

    const out: MapMarker[] = [];

    for (let t = 0; t < teamCount; t++) {
        out.push(...buckets[t]!.slice(0, m));
    }

    return out;
}

/**
 * Place flags in a cluster around each team's capital: nearest legal land first, round-robin
 * across teams with the same per-team quota. Flags stay at least `minBetweenFlags` Manhattan
 * apart from each other and from every capital (same gap).
 */
function placeFlagsNearCapitals(
    cells: string[][],
    rows: number,
    cols: number,
    teamCount: number,
    capitals: Cell[],
    occupied: Set<string>,
    flagTarget: number,
    capitalSpacing: number,
    nLand: number,
    rng: () => number,
): MapMarker[] {
    const minDim = Math.min(rows, cols);
    const preliminaryMaxR = preliminaryMaxRForMarkerSpacing(rows, cols);
    let minHalo = minPlaceableHaloAmongCapitals(cells, rows, cols, capitals, preliminaryMaxR);

    if (!Number.isFinite(minHalo) || minHalo < 6) {
        minHalo = Math.max(12, Math.floor(nLand / Math.max(8, teamCount * 4)));
    }

    const minBetweenFlags = computeMinManhattanMarkerSeparation({
        rows,
        cols,
        nLand,
        teamCount,
        flagBudget: flagTarget,
        capitalSpacing,
        minHaloLandCells: minHalo,
    });

    const maxR = Math.min(
        rows + cols - 2,
        Math.max(preliminaryMaxR, minBetweenFlags * 5, Math.floor(minDim * 0.52)),
    );
    const queues = capitals.map((cap) =>
        buildOrderedPlaceableNear(cells, rows, cols, cap, maxR, rng),
    );
    const ptr = new Array(teamCount).fill(0);
    const flags: MapMarker[] = [];
    let placed = 0;
    const quota = Math.floor(flagTarget / teamCount);
    const flagsPerTeam = new Array(teamCount).fill(0);

    function farEnoughFromFlagsAndCapitals(cell: Cell): boolean {
        for (const f of flags) {
            if (manhattan(cell, { row: f.row, col: f.col }) < minBetweenFlags) {
                return false;
            }
        }

        for (const c of capitals) {
            if (manhattan(cell, c) < minBetweenFlags) {
                return false;
            }
        }

        return true;
    }

    while (placed < flagTarget) {
        let progressed = false;

        for (let t = 0; t < teamCount; t++) {
            if (placed >= flagTarget) {
                break;
            }

            if (flagsPerTeam[t]! >= quota) {
                continue;
            }

            const q = queues[t]!;

            while (ptr[t] < q.length) {
                const cell = q[ptr[t]]!;

                ptr[t] += 1;
                const key = `${cell.row},${cell.col}`;

                if (occupied.has(key)) {
                    continue;
                }

                if (!farEnoughFromFlagsAndCapitals(cell)) {
                    continue;
                }

                flags.push({
                    type: 'flag',
                    team: t,
                    row: cell.row,
                    col: cell.col,
                });
                occupied.add(key);
                placed += 1;
                flagsPerTeam[t]! += 1;
                progressed = true;

                break;
            }
        }

        if (!progressed) {
            break;
        }
    }

    return balanceFlagsPerTeamEquality(flags, teamCount);
}

/**
 * Pick capital sites and flags from generated terrain. Uses the same placement rules as the
 * editor (land only: not water / deep_water / river; capitals and flags also keep Chebyshev
 * clearance from hydraulic water so large glyphs do not read as overlapping water).
 *
 * Team count: scan Manhattan spacing thresholds on one shuffled land list — prefer more teams
 * when the map has room; tie-break toward wider spacing. Capital *positions* are then chosen by
 * farthest-point sampling so teams spread across placeable land instead of following visit order.
 */
export function buildMarkersForGeneratedTerrain(
    cells: string[][],
    rows: number,
    cols: number,
    rng: () => number,
): { teamCount: number; markers: MapMarker[] } {
    const placeable = collectPlaceableCells(cells, rows, cols);
    const nLand = placeable.length;
    const minDim = Math.min(rows, cols);

    if (nLand === 0) {
        return { teamCount: MAP_MIN_TEAMS, markers: [] };
    }

    const order = placeable.slice();

    shuffleInPlace(order, rng);

    const maxD = Math.max(2, Math.floor(minDim / 9));
    let best: Cell[] = [];
    let bestD = 0;

    for (let d = maxD; d >= 1; d--) {
        const c = greedyFromOrder(order, d, MAP_MAX_TEAMS);

        if (c.length >= MAP_MIN_TEAMS) {
            if (c.length > best.length || (c.length === best.length && d > bestD)) {
                best = c;
                bestD = d;
            }
        }
    }

    let capitals = best;

    if (capitals.length < MAP_MIN_TEAMS) {
        capitals = greedyFromOrder(order, 1, MAP_MAX_TEAMS);
    }

    if (capitals.length < MAP_MIN_TEAMS) {
        capitals = padCapitalsToMinTeams(capitals, placeable, MAP_MIN_TEAMS);
    }

    const teamCount = Math.min(MAP_MAX_TEAMS, Math.max(MAP_MIN_TEAMS, capitals.length));
    capitals = pickFarthestCapitals(placeable, teamCount, rng);

    const markers: MapMarker[] = capitals.map((cell, team) => ({
        type: 'capital' as const,
        team,
        row: cell.row,
        col: cell.col,
    }));

    const occupied = new Set(capitals.map((p) => `${p.row},${p.col}`));
    const landBudget = Math.floor(nLand / 90);
    const maxFlags = Math.min(48, Math.max(teamCount, landBudget));
    const maxTotal = Math.min(maxFlags, teamCount * 5);
    const flagsPerTeamQuota = Math.floor(maxTotal / teamCount);
    const flagTarget = flagsPerTeamQuota * teamCount;

    const capitalSpacing = inferCapitalSpacing(capitals);

    const flagMarkers = placeFlagsNearCapitals(
        cells,
        rows,
        cols,
        teamCount,
        capitals,
        occupied,
        flagTarget,
        capitalSpacing,
        nLand,
        rng,
    );

    markers.push(...flagMarkers);

    return { teamCount, markers };
}
