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

const visibleTeams = computed(() =>
    props.teamColors.filter((c) => c.slot < props.editor.teamCount.value),
);

const canAddTeam = computed(() => props.editor.teamCount.value < MAP_MAX_TEAMS);

const canRemoveTeam = computed(() => props.editor.teamCount.value > MAP_MIN_TEAMS);

function addTeam(): void {
    if (!canAddTeam.value) {
        return;
    }

    props.editor.setTeamCount(props.editor.teamCount.value + 1);
}

/**
 * Read team index from the clicked control so we never remove the wrong slot when
 * stacked/overlapping hit targets compete (e.g. coarse pointer + shared z-index).
 */
function onRemoveTeamButtonClick(ev: MouseEvent): void {
    ev.preventDefault();
    ev.stopPropagation();
    const raw = (ev.currentTarget as HTMLButtonElement | null)?.dataset.teamSlot;
    const slot = raw === undefined ? NaN : Number.parseInt(raw, 10);

    if (!Number.isInteger(slot) || slot < 0) {
        return;
    }

    emit('requestRemoveTeam', slot);
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
                v-for="c in visibleTeams"
                :key="c.slot"
                :class="
                    cn(
                        'flex min-w-[5.25rem] items-stretch gap-0.5 rounded-md border-2 p-1 text-[10px] font-medium capitalize transition-shadow',
                        selectedTeamSlot !== null && selectedTeamSlot === c.slot
                            ? 'border-foreground ring-2 ring-foreground/20'
                            : 'border-transparent hover:border-muted-foreground/40',
                    )
                "
            >
                <button
                    type="button"
                    class="flex min-w-0 flex-1 flex-col items-center gap-1 rounded-sm p-1 transition-colors hover:bg-muted/30"
                    :title="`${c.label} — slot ${c.slot + 1}`"
                    @click="onTeamButtonClick(c.slot)"
                >
                    <span
                        class="size-8 shrink-0 rounded border-2 border-foreground/40 shadow-sm"
                        :style="{ backgroundColor: c.hex }"
                        aria-hidden="true"
                    />
                    <span class="text-center text-muted-foreground">{{ c.label }}</span>
                </button>
                <button
                    v-if="canRemoveTeam"
                    type="button"
                    class="flex w-7 shrink-0 flex-col items-center justify-start rounded-sm border border-transparent pt-0.5 text-foreground transition-colors hover:border-destructive/60 hover:bg-destructive hover:text-white"
                    :data-team-slot="String(c.slot)"
                    :title="`Remove ${c.label} team`"
                    :aria-label="`Remove ${c.label} team`"
                    @click="onRemoveTeamButtonClick"
                >
                    <X class="size-3.5" stroke-width="2.5" />
                </button>
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
