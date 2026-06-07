<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- editor exposes mutable refs shared by map builder */
import { Plus, X } from 'lucide-vue-next';
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
        props.editor.activeTool.value === 'capital' || props.editor.activeTool.value === 'flag',
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
        const colorRow = props.teamColors.find((c) => c.slot === ps);

        if (colorRow) {
            out.push({ teamIndex: i, colorRow });
        }
    }

    return out;
});

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
        class="wod-panel flex min-h-0 min-w-0 flex-1 flex-col gap-2 rounded-lg border-2 border-foreground p-3"
    >
        <p class="font-display text-xs font-bold uppercase tracking-wide text-muted-foreground">
            Team (capital / flag)
        </p>
        <p
            v-if="!isPlacementTool"
            class="text-[10px] leading-snug text-muted-foreground"
        >
            Choose a team below, then use the Capital or Flag tool and click the map to place.
        </p>
        <div class="flex flex-wrap gap-2">
            <div
                v-for="t in visibleTeamRows"
                :key="`team-slot-${t.teamIndex}`"
                :class="
                    cn(
                        'isolate flex min-w-[4.75rem] flex-col gap-1 rounded-md border-2 p-1.5 text-[10px] font-medium capitalize transition-shadow',
                        selectedTeamSlot !== null && selectedTeamSlot === t.teamIndex
                            ? 'border-foreground ring-2 ring-foreground/20'
                            : 'border-transparent hover:border-muted-foreground/40',
                    )
                "
            >
                <button
                    type="button"
                    class="flex w-full flex-col items-center gap-1 rounded-sm px-1 py-1 transition-colors hover:bg-muted/30"
                    :title="`${t.colorRow.label} — team ${t.teamIndex + 1}`"
                    @click="onTeamButtonClick(t.teamIndex)"
                >
                    <span
                        class="size-8 shrink-0 rounded border-2 border-foreground/40 shadow-sm"
                        :style="{ backgroundColor: t.colorRow.hex }"
                        aria-hidden="true"
                    />
                    <span class="text-center text-muted-foreground">{{ t.colorRow.label }}</span>
                </button>
                <div
                    v-if="canRemoveTeam"
                    class="flex justify-center border-t border-foreground/15 pt-1"
                >
                    <button
                        type="button"
                        class="inline-flex size-7 items-center justify-center rounded-md border-2 border-foreground/35 bg-wod-paper text-foreground shadow-sm transition-colors hover:border-destructive hover:bg-destructive hover:text-white"
                        :title="`Remove ${t.colorRow.label} team`"
                        :aria-label="`Remove ${t.colorRow.label} team`"
                        @click.stop.prevent="removeTeamForSlot(t.teamIndex)"
                    >
                        <X class="pointer-events-none size-3.5" stroke-width="2.5" />
                    </button>
                </div>
            </div>
            <button
                v-if="canAddTeam"
                type="button"
                class="flex min-w-[4.5rem] flex-col items-center justify-center gap-1 rounded-md border-2 border-dashed border-muted-foreground/50 p-2 text-[10px] font-medium text-muted-foreground transition-shadow hover:border-foreground/50 hover:bg-muted/40 hover:text-foreground"
                title="Add another team (up to 6)"
                aria-label="Add team"
                @click="addTeam"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded border-2 border-dashed border-muted-foreground/50 bg-muted/30"
                    aria-hidden="true"
                >
                    <Plus class="size-4 stroke-[2.5]" />
                </span>
                <span>Add team</span>
            </button>
        </div>
    </div>
</template>
