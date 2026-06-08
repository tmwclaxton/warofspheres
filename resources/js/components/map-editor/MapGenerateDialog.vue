<script setup lang="ts">
import { Loader2, Sparkles } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import AppModal from '@/components/AppModal.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { MapGenerationType } from '@/lib/generateRandomMap';
import { MAP_GENERATION_TYPE_OPTIONS } from '@/lib/generateRandomMap';
import { MAP_MAX_TEAMS, MAP_MIN_TEAMS } from '@/lib/mapEditorGrid';
import { cn } from '@/lib/utils';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    dirty: boolean;
    teamCount: number;
    generating: boolean;
    generationsUsed: number;
    generationLimit: number;
}>();

const emit = defineEmits<{
    generate: [payload: { seed?: number; type: MapGenerationType; teamCount: number }];
}>();

const atGenerationLimit = computed(
    () => props.generationsUsed >= props.generationLimit,
);

const generationsRemaining = computed(() =>
    Math.max(0, props.generationLimit - props.generationsUsed),
);

const dialogDescription = computed(() => {
    const local =
        'Terrain is built entirely in your browser (no server round-trip). ';

    if (atGenerationLimit.value) {
        return `${local}You have used all ${props.generationLimit} procedural generations allowed for this account on this device.`;
    }

    return `${local}You can run ${generationsRemaining.value} more generation${generationsRemaining.value === 1 ? '' : 's'} on this device.`;
});

const selectedType = ref<MapGenerationType>('mix');
const seed = ref('');
const generateTeamCount = ref(MAP_MIN_TEAMS);

const teamCountChoices = Array.from(
    { length: MAP_MAX_TEAMS - MAP_MIN_TEAMS + 1 },
    (_, i) => MAP_MIN_TEAMS + i,
);

watch(open, (isOpen) => {
    if (isOpen) {
        selectedType.value = 'mix';
        seed.value = '';

        const t = props.teamCount;

        generateTeamCount.value =
            t >= MAP_MIN_TEAMS && t <= MAP_MAX_TEAMS ? t : MAP_MIN_TEAMS;
    }
});

function parseSeedInput(raw: string): number | undefined {
    const t = raw.trim();

    if (t === '') {
        return undefined;
    }

    const n = Number.parseInt(t, 10);

    if (!Number.isFinite(n)) {
        return undefined;
    }

    return n;
}

function onGenerate(): void {
    if (atGenerationLimit.value || props.generating) {
        return;
    }

    emit('generate', {
        seed: parseSeedInput(seed.value),
        type: selectedType.value,
        teamCount: generateTeamCount.value,
    });
    open.value = false;
}
</script>

<template>
    <AppModal
        v-model:open="open"
        title="Generate map"
        :description="dialogDescription"
        content-class="sm:max-w-lg"
    >
        <div class="space-y-4" @keydown.stop>
            <p
                v-if="atGenerationLimit"
                class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
            >
                Generation limit reached for this browser. You have already created
                {{ props.generationLimit }} procedurally generated maps.
            </p>
            <p
                v-if="props.dirty"
                class="rounded-md border border-amber-300/80 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-950/40 dark:text-amber-100"
            >
                You have unsaved changes. Generating will discard them.
            </p>

            <div class="space-y-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Generation style
                </p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="option in MAP_GENERATION_TYPE_OPTIONS"
                        :key="option.id"
                        type="button"
                        :class="
                            cn(
                                'rounded-md border-2 px-3 py-2 text-left transition-shadow',
                                selectedType === option.id
                                    ? 'border-foreground bg-muted ring-2 ring-foreground/15'
                                    : 'border-transparent bg-muted/40 hover:border-muted-foreground/30',
                            )
                        "
                        @click="selectedType = option.id"
                    >
                        <span class="block text-sm font-semibold">{{ option.label }}</span>
                        <span class="mt-0.5 block text-xs text-muted-foreground">
                            {{ option.description }}
                        </span>
                    </button>
                </div>
            </div>

            <div class="space-y-1">
                <label class="text-xs font-semibold" for="map-generate-teams">Teams</label>
                <select
                    id="map-generate-teams"
                    v-model.number="generateTeamCount"
                    class="h-9 w-full max-w-xs rounded-md border-2 border-foreground bg-background px-2 text-sm font-medium"
                >
                    <option v-for="t in teamCountChoices" :key="t" :value="t">{{ t }}</option>
                </select>
                <p class="text-xs text-muted-foreground">
                    Number of players / team slots on the generated map.
                </p>
            </div>

            <div class="space-y-1">
                <label class="text-xs font-semibold" for="map-generate-seed">
                    Random seed (optional)
                </label>
                <Input
                    id="map-generate-seed"
                    v-model="seed"
                    class="h-9 border-2 border-foreground font-mono text-xs"
                    inputmode="numeric"
                    placeholder="Leave empty for random"
                    autocomplete="off"
                />
            </div>
        </div>

        <template #footer>
            <Button type="button" variant="outline" :disabled="props.generating" @click="open = false">
                Cancel
            </Button>
            <Button
                type="button"
                class="gap-1.5"
                :disabled="props.generating || atGenerationLimit"
                @click="onGenerate"
            >
                <Loader2 v-if="props.generating" class="size-3.5 shrink-0 animate-spin" />
                <Sparkles v-else class="size-3.5 shrink-0" />
                {{ props.generating ? 'Generating…' : 'Generate' }}
            </Button>
        </template>
    </AppModal>
</template>
