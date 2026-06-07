<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- editor exposes mutable refs shared by map builder */
import {
    Circle,
    Flag,
    Landmark,
    Plus,
    RectangleHorizontal,
    X,
} from 'lucide-vue-next';
import { computed } from 'vue';
import type { MapEditorInstance } from '@/composables/useMapEditor';
import { MAP_MAX_TEAMS, MAP_MIN_TEAMS } from '@/lib/mapEditorGrid';
import { cn } from '@/lib/utils';

export type TeamColorRow = {
    slot: number;
    hex: string;
    label: string;
};

const props = defineProps<{
    editor: MapEditorInstance;
    teamColors: TeamColorRow[];
}>();

const emit = defineEmits<{
    needMarkerTool: [slot: number];
    requestRemoveTeam: [slot: number];
}>();

const isPlacementTool = computed(
    () =>
        props.editor.activeTool.value === 'capital'
        || props.editor.activeTool.value === 'flag'
        || props.editor.activeTool.value === 'infantry'
        || props.editor.activeTool.value === 'tank',
);

const selectedTeamSlot = computed(() => props.editor.selectedTeam.value);

function onTeamButtonClick(slot: number): void {
    if (isPlacementTool.value) {
        props.editor.selectedTeam.value = slot;

        return;
    }

    emit('needMarkerTool', slot);
}

const visibleTeamRows = computed(() => {
    const n = props.editor.teamCount.value;
    const slots = props.editor.teamPaletteSlots.value;
    const out: { teamIndex: number; colorRow: TeamColorRow }[] = [];

    for (let i = 0; i < n; i++) {
        const ps = slots[i] ?? i;
        const colorRow =
            props.teamColors.find((c) => c.slot === ps)
            ?? ({
                slot: ps,
                hex: '#888888',
                label: `Team ${i + 1}`,
            } satisfies TeamColorRow);

        out.push({ teamIndex: i, colorRow });
    }

    return out;
});

/** Per logical team: marker counts on the map (flags shown as outposts). */
const markerTotalsByTeam = computed(() => {
    void props.editor.markersEpoch.value;
    const n = props.editor.teamCount.value;
    const markers = props.editor.markers.value;
    const totals = Array.from({ length: n }, () => ({
        capitals: 0,
        outposts: 0,
        infantry: 0,
        tanks: 0,
    }));

    for (const m of markers) {
        if (!Number.isInteger(m.team) || m.team < 0 || m.team >= n) {
            continue;
        }

        const row = totals[m.team];

        if (!row) {
            continue;
        }

        if (m.type === 'capital') {
            row.capitals += 1;
        } else if (m.type === 'flag') {
            row.outposts += 1;
        } else if (m.type === 'infantry') {
            row.infantry += 1;
        } else if (m.type === 'tank') {
            row.tanks += 1;
        }
    }

    return totals;
});

function formatTeamCountsTitle(teamIndex: number): string {
    const r = markerTotalsByTeam.value[teamIndex];

    if (!r) {
        return 'Capitals 0, outposts (flags) 0, infantry 0, tanks 0';
    }

    return `Capitals ${r.capitals}, outposts (flags) ${r.outposts}, infantry ${r.infantry}, tanks ${r.tanks}`;
}

const canAddTeam = computed(() => props.editor.teamCount.value < MAP_MAX_TEAMS);

const canRemoveTeam = computed(() => props.editor.teamCount.value > MAP_MIN_TEAMS);

function addTeam(): void {
    if (!canAddTeam.value) {
        return;
    }

    props.editor.setTeamCount(props.editor.teamCount.value + 1);
}

/** `teamIndex` is contiguous logical team; colour/label come from {@link teamPaletteSlots}. */
function removeTeamForSlot(teamIndex: number): void {
    if (
        !Number.isInteger(teamIndex)
        || teamIndex < 0
        || teamIndex >= props.editor.teamCount.value
    ) {
        return;
    }

    emit('requestRemoveTeam', teamIndex);
}
</script>

<template>
    <div
        class="wod-panel flex min-h-0 min-w-0 flex-col gap-1 rounded-lg border-2 border-foreground p-2"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-0.5">
            <p class="font-display text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                Teams
            </p>
            <p class="sr-only">
                Per-team counts use the same icons as the toolbar: capital, flag, infantry spawn, tank
                spawn.
            </p>
            <p
                v-if="!isPlacementTool"
                class="max-w-prose text-[9px] leading-tight text-muted-foreground sm:text-[10px]"
            >
                Click a swatch to arm that team, then use marker tools on the map.
            </p>
        </div>
        <div
            class="grid min-h-0 w-full min-w-0 grid-cols-3 content-start gap-1.5 pb-0.5"
        >
            <div
                v-for="t in visibleTeamRows"
                :key="`team-slot-${t.teamIndex}`"
                :class="
                    cn(
                        'isolate flex w-full min-w-0 flex-col rounded-md border-2 p-1 text-[9px] font-medium normal-case transition-shadow',
                        selectedTeamSlot !== null && selectedTeamSlot === t.teamIndex
                            ? 'border-foreground ring-2 ring-foreground/20'
                            : 'border-transparent hover:border-muted-foreground/40',
                    )
                "
            >
                <div class="relative flex w-full items-center gap-1">
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center gap-1 rounded-sm py-0.5 pr-5 text-left capitalize transition-colors hover:bg-muted/30"
                        :title="`${t.colorRow.label} — team ${t.teamIndex + 1}. Select for marker placement.`"
                        @click="onTeamButtonClick(t.teamIndex)"
                    >
                        <span
                            class="size-6 shrink-0 rounded border-2 border-foreground/40 shadow-sm"
                            :style="{ backgroundColor: t.colorRow.hex }"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 truncate text-[10px] text-muted-foreground">{{
                            t.colorRow.label
                        }}</span>
                    </button>
                    <button
                        v-if="canRemoveTeam"
                        type="button"
                        class="absolute top-0 right-0 inline-flex size-6 items-center justify-center rounded border border-foreground/30 bg-wod-paper text-foreground shadow-sm transition-colors hover:border-destructive hover:bg-destructive hover:text-white"
                        :title="`Remove ${t.colorRow.label} team`"
                        :aria-label="`Remove ${t.colorRow.label} team`"
                        @click.stop.prevent="removeTeamForSlot(t.teamIndex)"
                    >
                        <X class="pointer-events-none size-3" stroke-width="2.5" />
                    </button>
                </div>
                <div
                    class="mt-0.5 border-t border-foreground/15 pt-1"
                    :title="formatTeamCountsTitle(t.teamIndex)"
                >
                    <span class="sr-only">{{ formatTeamCountsTitle(t.teamIndex) }}</span>
                    <div
                        class="flex items-end justify-between gap-0.5 text-foreground"
                        aria-hidden="true"
                    >
                        <div class="flex flex-1 flex-col items-center gap-0.5">
                            <Landmark class="size-3.5 shrink-0 sm:size-4" stroke-width="2" />
                            <span class="text-[10px] font-semibold tabular-nums leading-none sm:text-xs">
                                {{ markerTotalsByTeam[t.teamIndex]?.capitals ?? 0 }}
                            </span>
                        </div>
                        <div class="flex flex-1 flex-col items-center gap-0.5">
                            <Flag class="size-3.5 shrink-0 sm:size-4" stroke-width="2" />
                            <span class="text-[10px] font-semibold tabular-nums leading-none sm:text-xs">
                                {{ markerTotalsByTeam[t.teamIndex]?.outposts ?? 0 }}
                            </span>
                        </div>
                        <div class="flex flex-1 flex-col items-center gap-0.5">
                            <Circle class="size-3.5 shrink-0 sm:size-4" stroke-width="2" />
                            <span class="text-[10px] font-semibold tabular-nums leading-none sm:text-xs">
                                {{ markerTotalsByTeam[t.teamIndex]?.infantry ?? 0 }}
                            </span>
                        </div>
                        <div class="flex flex-1 flex-col items-center gap-0.5">
                            <RectangleHorizontal
                                class="size-3.5 shrink-0 sm:size-4"
                                stroke-width="2"
                            />
                            <span class="text-[10px] font-semibold tabular-nums leading-none sm:text-xs">
                                {{ markerTotalsByTeam[t.teamIndex]?.tanks ?? 0 }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <button
                v-if="canAddTeam"
                type="button"
                class="flex w-full min-w-0 flex-col items-center justify-center gap-0.5 rounded-md border-2 border-dashed border-muted-foreground/50 p-1 text-[9px] font-medium text-muted-foreground transition-shadow hover:border-foreground/50 hover:bg-muted/40 hover:text-foreground"
                title="Add another team (up to 6)"
                aria-label="Add team"
                @click="addTeam"
            >
                <span
                    class="flex size-6 shrink-0 items-center justify-center rounded border-2 border-dashed border-muted-foreground/50 bg-muted/30"
                    aria-hidden="true"
                >
                    <Plus class="size-3.5 stroke-[2.5]" />
                </span>
                <span>Add</span>
            </button>
        </div>
    </div>
</template>
