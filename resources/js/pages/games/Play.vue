<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';
import GameCanvas from '@/components/game/GameCanvas.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import { index as lobbies } from '@/routes/lobbies';
import { useGameStore } from '@/stores/gameStore';

type GamePayload = {
    uuid: string;
    code: string;
    maxPlayers: number;
    slot: number;
    color: string;
    players: Array<{ slot: number; name: string; color: string }>;
};

const props = defineProps<{
    game: GamePayload;
    snapshotUrl: string;
}>();

const page = usePage();
const store = useGameStore();

onMounted(() => {
    const userId = page.props.auth.user?.id;

    if (userId) {
        store.connect(props.game.uuid, userId, props.game.slot, props.game.color);
        void store.fetchSnapshotIfNeeded(props.snapshotUrl);
        setTimeout(() => {
            void store.fetchSnapshotIfNeeded(props.snapshotUrl);
        }, 2200);
    }
});

onUnmounted(() => {
    store.disconnect();
});
</script>

<template>
    <Head title="Battlefield" />

    <div class="flex h-screen flex-col bg-background text-foreground">
        <header
            class="wod-bar-top relative flex shrink-0 items-center justify-between px-4 py-2"
        >
            <div>
                <p class="font-display text-xs font-bold text-foreground">
                    Clash of Dots
                </p>
                <p class="text-sm font-bold tracking-widest text-foreground">
                    {{ game.code }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span
                    v-for="player in [...game.players].sort((a, b) => a.slot - b.slot)"
                    :key="player.slot"
                    class="wod-chip"
                >
                    <span
                        class="wod-swatch !size-2.5 rounded-full"
                        :style="{ backgroundColor: player.color }"
                    />
                    {{ player.name }}
                </span>
            </div>
            <div class="flex items-center gap-2">
                <ThemeToggle />
                <Button
                    size="sm"
                    variant="outline"
                    @click="store.clearDrafts()"
                >
                    Clear (C)
                </Button>
                <Button size="sm" @click="store.submitOrders(game.uuid)">
                    Execute (Space)
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    @click="store.togglePause(game.uuid)"
                >
                    Pause (P)
                </Button>
                <Link :href="lobbies().url">
                    <Button size="sm" variant="ghost">Exit</Button>
                </Link>
            </div>
        </header>

        <div class="relative min-h-0 flex-1 border-y-2 border-foreground">
            <div
                v-if="!store.initialized && !store.winnerUserId"
                class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-background/90 text-center"
            >
                <p class="text-sm font-semibold text-muted-foreground">
                    Connecting to battlefield…
                </p>
                <p
                    v-if="!store.connected"
                    class="max-w-sm text-xs text-muted-foreground"
                >
                    If this hangs, ensure Reverb is running and your `.env` matches
                    `VITE_REVERB_*`.
                </p>
            </div>
            <GameCanvas />
            <div
                v-if="store.winnerUserId"
                class="absolute inset-0 flex items-center justify-center bg-foreground/40"
            >
                <div class="wod-panel px-8 py-6 text-center">
                    <p class="font-display text-2xl font-bold">
                        {{
                            store.winnerUserId === page.props.auth.user?.id
                                ? 'Victory!'
                                : 'Defeat'
                        }}
                    </p>
                    <Link :href="lobbies().url" class="mt-4 inline-block">
                        <Button>Return to lobbies</Button>
                    </Link>
                </div>
            </div>
        </div>

        <footer class="wod-bar-bottom px-4 py-2 text-xs font-medium">
            Left-click drag to plan paths · Right-click drag to pan · Scroll
            to zoom
        </footer>
    </div>
</template>
