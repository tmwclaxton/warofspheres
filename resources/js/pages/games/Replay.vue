<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import GameCanvas from '@/components/game/GameCanvas.vue';
import { Button } from '@/components/ui/button';
import { useGameStore } from '@/stores/gameStore';

type ReplaySnapshot = {
    worldTick: number;
    state: Record<string, unknown>;
};

type GameInfo = {
    uuid: string;
    id: number;
};

const props = defineProps<{
    game: GameInfo;
    snapshots: ReplaySnapshot[];
}>();

const store = useGameStore();
const currentIndex = ref(0);
const playing = ref(false);
let playInterval: ReturnType<typeof setInterval> | null = null;

const currentSnapshot = computed(() => props.snapshots[currentIndex.value] ?? null);
const total = computed(() => props.snapshots.length);

function applySnapshot(index: number) {
    const snap = props.snapshots[index];
    if (!snap) {
        return;
    }
    const state = snap.state as Record<string, unknown>;
    store.latestState = (state.latestState ?? null) as typeof store.latestState;
    store.economy = (state.economy ?? null) as typeof store.economy;
    store.worldTick = snap.worldTick;
}

// Apply the first snapshot on mount.
if (props.snapshots.length > 0) {
    applySnapshot(0);
}

watch(currentIndex, (idx) => applySnapshot(idx));

function seek(index: number) {
    currentIndex.value = Math.max(0, Math.min(total.value - 1, index));
}

function togglePlay() {
    if (playing.value) {
        stopPlay();
    } else {
        startPlay();
    }
}

function startPlay() {
    playing.value = true;
    playInterval = setInterval(() => {
        if (currentIndex.value >= total.value - 1) {
            stopPlay();
            return;
        }
        currentIndex.value++;
    }, 1000);
}

function stopPlay() {
    playing.value = false;
    if (playInterval !== null) {
        clearInterval(playInterval);
        playInterval = null;
    }
}

onUnmounted(stopPlay);
</script>

<template>
    <Head title="Match Replay" />

    <div class="flex h-screen flex-col bg-background">
        <!-- Header -->
        <div class="flex items-center justify-between border-b px-4 py-2">
            <h1 class="text-sm font-semibold">Match Replay</h1>
            <span class="text-xs text-muted-foreground">
                Tick {{ currentSnapshot?.worldTick ?? 0 }} / {{ snapshots.at(-1)?.worldTick ?? 0 }}
            </span>
        </div>

        <!-- Canvas -->
        <div class="relative min-h-0 flex-1">
            <GameCanvas read-only />
            <div
                v-if="snapshots.length === 0"
                class="absolute inset-0 flex items-center justify-center bg-background/80 text-sm text-muted-foreground"
            >
                No replay data available for this match.
            </div>
        </div>

        <!-- Controls -->
        <div class="flex items-center gap-3 border-t px-4 py-3">
            <Button
                size="sm"
                variant="outline"
                :disabled="currentIndex === 0"
                @click="seek(currentIndex - 1)"
            >
                ◀
            </Button>

            <Button
                size="sm"
                :disabled="snapshots.length === 0"
                @click="togglePlay"
            >
                {{ playing ? '⏸ Pause' : '▶ Play' }}
            </Button>

            <Button
                size="sm"
                variant="outline"
                :disabled="currentIndex >= total - 1"
                @click="seek(currentIndex + 1)"
            >
                ▶
            </Button>

            <input
                type="range"
                class="min-w-0 flex-1"
                :min="0"
                :max="total - 1"
                :value="currentIndex"
                @input="seek(Number(($event.target as HTMLInputElement).value))"
            />

            <span class="shrink-0 text-xs text-muted-foreground">
                {{ currentIndex + 1 }} / {{ total }}
            </span>
        </div>
    </div>
</template>
