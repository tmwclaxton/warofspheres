<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Coins, Map, Mountain, Swords } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';

type TroopStat = {
    id: string;
    label: string;
    role: string;
    health: number;
    recruitCost: number;
    upkeepPerSecond: number;
    defense: number;
    summary: string;
};

type SettlementStat = {
    id: string;
    label: string;
    marker: string;
    incomePerSecond: number;
    supplyCapacity: number;
    healMultiplier: number;
    summary: string;
};

type TerrainEffect = {
    speed: number;
    attack: number;
    defense: number;
};

type TerrainStat = {
    id: string;
    label: string;
    color: string;
    isWater: boolean;
    impassable: boolean;
    description: string;
    infantry: TerrainEffect;
    tank: TerrainEffect;
};

type MapGenerationStat = {
    id: string;
    label: string;
    description: string;
    traits: string[];
};

type EconomyNote = {
    title: string;
    body: string;
};

defineProps<{
    troops: TroopStat[];
    settlements: SettlementStat[];
    terrain: TerrainStat[];
    mapGeneration: MapGenerationStat[];
    economyNotes: EconomyNote[];
}>();

function formatStat(value: number, digits = 2): string {
    return value.toFixed(digits).replace(/\.?0+$/, '');
}

function attackRatio(infantry: number, tank: number): string {
    if (infantry === 0 && tank === 0) {
        return '—';
    }

    if (infantry === 0) {
        return 'Tank only';
    }

    const ratio = tank / infantry;

    if (Math.abs(ratio - 1) < 0.05) {
        return 'Equal';
    }

    return `${formatStat(ratio, 1)}×`;
}

function speedClass(speed: number, impassable: boolean): string {
    if (impassable || speed <= 0.02) {
        return 'text-muted-foreground';
    }

    if (speed >= 0.45) {
        return 'text-wod-green-dk font-semibold';
    }

    if (speed >= 0.25) {
        return 'text-foreground';
    }

    return 'text-amber-700 dark:text-amber-300';
}
</script>

<template>
    <Head title="Game Wiki" />

    <div class="flex flex-col gap-10 pb-4">
        <Heading
            title="Game Wiki"
            description="Unit stats, terrain effects, economy rules, and map generation — tuned from War of Dots community data and adapted for War of Spheres."
        />

        <section class="wod-panel p-6">
            <div class="flex items-start gap-3">
                <div class="wod-logo-terrain size-10 shrink-0">
                    <Swords class="size-5" />
                </div>
                <div>
                    <h2 class="font-display text-xl font-bold">Combat units</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Two unit types share the same upkeep but trade speed for
                        durability. Defense is flat — there is no bonus for holding
                        ground.
                    </p>
                </div>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-2">
                <article
                    v-for="troop in troops"
                    :key="troop.id"
                    class="wod-panel-soft p-5"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-display text-lg font-bold">
                            {{ troop.label }}
                        </h3>
                        <span class="wod-chip">{{ troop.role }}</span>
                    </div>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        {{ troop.summary }}
                    </p>
                    <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Health</dt>
                            <dd class="font-display font-bold">{{ troop.health }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Recruit cost</dt>
                            <dd class="font-display font-bold">
                                {{ troop.recruitCost }} funds
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Upkeep</dt>
                            <dd class="font-display font-bold">
                                {{ formatStat(troop.upkeepPerSecond, 1) }}/s
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Defense</dt>
                            <dd class="font-display font-bold">
                                {{ formatStat(troop.defense, 1) }}×
                            </dd>
                        </div>
                    </dl>
                </article>
            </div>
        </section>

        <section class="wod-panel p-6">
            <div class="flex items-start gap-3">
                <div class="wod-logo-terrain size-10 shrink-0">
                    <Coins class="size-5" />
                </div>
                <div>
                    <h2 class="font-display text-xl font-bold">
                        Settlements & economy
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Yellow settlements fund your war machine. Capitals are
                        richer and harder to replace — never abandon one lightly.
                    </p>
                </div>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-2">
                <article
                    v-for="settlement in settlements"
                    :key="settlement.id"
                    class="wod-panel-soft p-5"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-display text-lg font-bold">
                            {{ settlement.label }}
                        </h3>
                        <span class="wod-chip text-[0.65rem]">
                            {{ settlement.marker }}
                        </span>
                    </div>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        {{ settlement.summary }}
                    </p>
                    <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Income</dt>
                            <dd class="font-display font-bold">
                                {{ settlement.incomePerSecond }} funds/s
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Supply cap</dt>
                            <dd class="font-display font-bold">
                                {{ settlement.supplyCapacity }} units
                            </dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-muted-foreground">Healing</dt>
                            <dd class="font-display font-bold">
                                {{ formatStat(settlement.healMultiplier, 1) }}×
                                near settlements
                            </dd>
                        </div>
                    </dl>
                </article>
            </div>

            <ul class="mt-6 grid gap-3 sm:grid-cols-2">
                <li
                    v-for="note in economyNotes"
                    :key="note.title"
                    class="rounded-md border-2 border-foreground/15 bg-muted/30 px-4 py-3 text-sm"
                >
                    <p class="font-display font-bold">{{ note.title }}</p>
                    <p class="mt-1 leading-relaxed text-muted-foreground">
                        {{ note.body }}
                    </p>
                </li>
            </ul>
        </section>

        <section class="wod-panel p-6">
            <div class="flex items-start gap-3">
                <div class="wod-logo-terrain size-10 shrink-0">
                    <Mountain class="size-5" />
                </div>
                <div>
                    <h2 class="font-display text-xl font-bold">Terrain types</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Speed and attack use the War of Dots scale (plains infantry
                        speed&nbsp;=&nbsp;0.5, attack&nbsp;=&nbsp;0.08). The pixel
                        under a unit’s center determines which terrain applies.
                    </p>
                </div>
            </div>

            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[52rem] border-collapse text-sm">
                    <thead>
                        <tr class="border-b-2 border-foreground text-left">
                            <th class="px-3 py-2 font-display font-bold">
                                Terrain
                            </th>
                            <th
                                class="px-3 py-2 text-center font-display font-bold"
                                colspan="3"
                            >
                                Infantry
                            </th>
                            <th
                                class="px-3 py-2 text-center font-display font-bold"
                                colspan="3"
                            >
                                Tank
                            </th>
                            <th class="px-3 py-2 font-display font-bold">
                                Notes
                            </th>
                        </tr>
                        <tr
                            class="border-b border-foreground/20 text-xs text-muted-foreground"
                        >
                            <th class="px-3 py-1" />
                            <th class="px-3 py-1 text-center">Speed</th>
                            <th class="px-3 py-1 text-center">Attack</th>
                            <th class="px-3 py-1 text-center">Def</th>
                            <th class="px-3 py-1 text-center">Speed</th>
                            <th class="px-3 py-1 text-center">Attack</th>
                            <th class="px-3 py-1 text-center">Def</th>
                            <th class="px-3 py-1" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="tile in terrain"
                            :key="tile.id"
                            class="border-b border-foreground/10 hover:bg-muted/20"
                        >
                            <td class="px-3 py-2.5">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="size-4 shrink-0 border-2 border-foreground"
                                        :style="{ backgroundColor: tile.color }"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <p class="font-display font-bold">
                                            {{ tile.label }}
                                        </p>
                                        <p
                                            v-if="tile.isWater"
                                            class="text-xs text-sky-700 dark:text-sky-300"
                                        >
                                            Water · damage over time
                                        </p>
                                        <p
                                            v-else-if="tile.impassable"
                                            class="text-xs text-muted-foreground"
                                        >
                                            Impassable
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td
                                class="px-3 py-2.5 text-center tabular-nums"
                                :class="speedClass(tile.infantry.speed, tile.impassable)"
                            >
                                {{ formatStat(tile.infantry.speed) }}
                            </td>
                            <td class="px-3 py-2.5 text-center tabular-nums">
                                {{ formatStat(tile.infantry.attack) }}
                            </td>
                            <td class="px-3 py-2.5 text-center tabular-nums">
                                {{ formatStat(tile.infantry.defense, 1) }}
                            </td>
                            <td
                                class="px-3 py-2.5 text-center tabular-nums"
                                :class="speedClass(tile.tank.speed, tile.impassable)"
                            >
                                {{ formatStat(tile.tank.speed) }}
                            </td>
                            <td class="px-3 py-2.5 text-center tabular-nums">
                                {{ formatStat(tile.tank.attack) }}
                            </td>
                            <td class="px-3 py-2.5 text-center tabular-nums">
                                {{ formatStat(tile.tank.defense, 1) }}
                            </td>
                            <td class="max-w-xs px-3 py-2.5 text-xs leading-relaxed text-muted-foreground">
                                {{ tile.description }}
                                <span
                                    v-if="!tile.impassable"
                                    class="mt-1 block text-foreground/70"
                                >
                                    Tank attack vs infantry:
                                    {{
                                        attackRatio(
                                            tile.infantry.attack,
                                            tile.tank.attack,
                                        )
                                    }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="wod-panel p-6">
            <div class="flex items-start gap-3">
                <div class="wod-logo-terrain size-10 shrink-0">
                    <Map class="size-5" />
                </div>
                <div>
                    <h2 class="font-display text-xl font-bold">
                        Map generation styles
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Available in the Map Builder when generating a new map.
                        Each style uses the same team and seed options.
                    </p>
                </div>
            </div>

            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                <article
                    v-for="style in mapGeneration"
                    :key="style.id"
                    class="wod-panel-soft p-5"
                >
                    <h3 class="font-display text-lg font-bold">
                        {{ style.label }}
                    </h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        {{ style.description }}
                    </p>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        <li
                            v-for="trait in style.traits"
                            :key="trait"
                            class="wod-chip"
                        >
                            {{ trait }}
                        </li>
                    </ul>
                </article>
            </div>
        </section>
    </div>
</template>
