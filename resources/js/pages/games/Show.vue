<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index, join, play, start } from '@/routes/games';

type Lobby = {
    uuid: string;
    code: string;
    status: string;
    maxPlayers: number;
    playerCount: number;
    isHost: boolean;
    isParticipant: boolean;
    canStart: boolean;
    hostName: string;
    players: Array<{ slot: number; name: string; color: string }>;
};

const props = defineProps<{
    game: Lobby;
}>();

function joinGame() {
    router.post(join(props.game.uuid).url);
}

function startGame() {
    router.post(start(props.game.uuid).url);
}
</script>

<template>
    <Head :title="`Lobby ${game.code}`" />

    <div class="mx-auto flex max-w-2xl flex-col gap-6">
        <div class="rounded-xl border bg-[#f7f1e3] p-8 text-center dark:bg-[#1a1814]">
            <p class="text-sm uppercase tracking-[0.3em] text-muted-foreground">
                Lobby code
            </p>
            <p class="mt-2 font-mono text-4xl tracking-[0.2em]">{{ game.code }}</p>
            <p class="mt-4 text-muted-foreground">
                {{ game.playerCount }} / {{ game.maxPlayers }} commanders ready
            </p>
        </div>

        <div class="space-y-2 rounded-xl border p-4">
            <h2 class="font-semibold">Commanders</h2>
            <div
                v-for="slot in game.maxPlayers"
                :key="slot"
                class="flex items-center justify-between rounded-lg border px-3 py-2"
            >
                <span>Slot {{ slot }}</span>
                <template v-if="game.players[slot - 1]">
                    <span class="flex items-center gap-2">
                        <span
                            class="h-3 w-3 rounded-full"
                            :style="{
                                backgroundColor: game.players[slot - 1].color,
                            }"
                        />
                        {{ game.players[slot - 1].name }}
                    </span>
                </template>
                <Badge v-else variant="outline">Empty</Badge>
            </div>
        </div>

        <div class="flex flex-wrap gap-3">
            <Link :href="index().url">
                <Button variant="outline">Back</Button>
            </Link>
            <Button
                v-if="!game.isParticipant"
                @click="joinGame"
            >
                Join lobby
            </Button>
            <Button
                v-if="game.isHost && game.canStart"
                @click="startGame"
            >
                Start battle
            </Button>
            <Link v-if="game.status === 'playing' && game.isParticipant" :href="play(game.uuid).url">
                <Button>Enter battlefield</Button>
            </Link>
        </div>
    </div>
</template>
