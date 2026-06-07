/**
 * Run: npx vite-node --config vite.config.ts scripts/verify-generated-troops.mts
 * Asserts symmetric troop counts and non-empty troops for random seeds / styles.
 */
import { generateRandomMap, type GeneratedMapData, type MapGenerationType } from '@/lib/generateRandomMap';

function assert(cond: boolean, msg: string): void {
    if (!cond) {
        throw new Error(msg);
    }
}

function troopStats(data: GeneratedMapData): {
    teams: number;
    inf: number[];
    tank: number[];
    total: number;
} {
    const teams = data.teamCount ?? 0;
    const inf = new Array(teams).fill(0) as number[];
    const tank = new Array(teams).fill(0) as number[];

    for (const m of data.markers ?? []) {
        if (m.type === 'infantry') {
            inf[m.team]! += 1;
        }

        if (m.type === 'tank') {
            tank[m.team]! += 1;
        }
    }

    const total = inf.reduce((a, b) => a + b, 0) + tank.reduce((a, b) => a + b, 0);

    return { teams, inf, tank, total };
}

function check(label: string, data: GeneratedMapData): void {
    const { teams, inf, tank, total } = troopStats(data);

    assert(teams >= 2, `${label}: teamCount`);
    assert(total > 0, `${label}: expected some troops, got 0`);

    const minI = Math.min(...inf);
    const maxI = Math.max(...inf);
    const minT = Math.min(...tank);
    const maxT = Math.max(...tank);

    assert(minI === maxI, `${label}: infantry asymmetric ${JSON.stringify(inf)}`);
    assert(minT === maxT, `${label}: tanks asymmetric ${JSON.stringify(tank)}`);
}

const types: MapGenerationType[] = ['mix', 'islands', 'desert', 'mountains'];

for (const type of types) {
    for (let seed = 0; seed < 15; seed++) {
        const data = generateRandomMap({
            seed,
            type,
            cellRows: 195,
            cellCols: 108,
            teamCount: 2,
        });
        check(`${type} seed=${seed}`, data);
    }
}

console.log('verify-generated-troops: OK (15 seeds × 4 styles, 2 teams)');
