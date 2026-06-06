<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';
import GameCanvas from '@/components/game/GameCanvas.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/games';
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
}>();

const page = usePage();
const store = useGameStore();

onMounted(() => {
    const userId = page.props.auth.user?.id;
    if (userId) {
        store.connect(props.game.uuid, userId, props.game.slot, props.game.color);
    }
});

onUnmounted(() => {
    store.disconnect();
});
</script>

<template>
    <Head title="Battlefield" />

    <div class="flex h-screen flex-col bg-[#e8dfc8] text-[#1a1814]">
        <header
            class="flex items-center justify-between border-b border-[#1a1814]/10 bg-[#f7f1e3] px-4 py-3"
        >
            <div>
                <p class="text-xs uppercase tracking-[0.25em]">War of Spheres</p>
                <p class="font-mono text-sm">{{ game.code }}</p>
            </div>
            <div class="flex items-center gap-2">
                <span
                    v-for="player in game.players"
                    :key="player.slot"
                    class="flex items-center gap-1 rounded-full border px-2 py-1 text-xs"
                >
                    <span
                        class="h-2 w-2 rounded-full"
                        :style="{ backgroundColor: player.color }"
                    />
                    {{ player.name }}
                </span>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    @click="store.clearDrafts()"
                >
                    Clear (C)
                </Button>
                <Button
                    size="sm"
                    @click="store.submitOrders(game.uuid)"
                >
                    Execute (Space)
                </Button>
                <Button
                    size="sm"
                    variant="secondary"
                    @click="store.togglePause(game.uuid)"
                >
                    Pause (P)
                </Button>
                <Link :href="index().url">
                    <Button size="sm" variant="ghost">Exit</Button>
                </Link>
            </div>
        </header>

        <div class="relative min-h-0 flex-1">
            <GameCanvas />
            <div
                v-if="store.winnerUserId"
                class="absolute inset-0 flex items-center justify-center bg-black/40"
            >
                <div class="rounded-xl bg-[#f7f1e3] px-8 py-6 text-center shadow-lg">
                    <p class="text-xl font-semibold">
                        {{
                            store.winnerUserId === page.props.auth.user?.id
                                ? 'Victory!'
                                : 'Defeat'
                        }}
                    </p>
                    <Link :href="index().url" class="mt-4 inline-block">
                        <Button>Return to lobbies</Button>
                    </Link>
                </div>
            </div>
        </div>

        <footer class="border-t border-[#1a1814]/10 bg-[#f7f1e3] px-4 py-2 text-xs text-muted-foreground">
            Left-click drag on your units or cities to plan paths. Right-click drag to pan. Scroll to zoom.
        </footer>
    </div>
</template>
