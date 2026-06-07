<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { join, play, start } from '@/routes/games';
import { index as lobbies } from '@/routes/lobbies';

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
    sourceMap: { uuid: string; name: string; by: string } | null;
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

    <div class="mx-auto flex max-w-2xl flex-col gap-5">
        <div class="wod-panel p-8 text-center">
            <p class="text-xs font-semibold text-muted-foreground uppercase">
                Lobby code
            </p>
            <p class="font-display mt-2 text-4xl font-bold tracking-widest">
                {{ game.code }}
            </p>
            <p class="mt-3 text-sm text-muted-foreground">
                {{ game.playerCount }} / {{ game.maxPlayers }} commanders ready
            </p>
        </div>

        <div
            v-if="game.sourceMap"
            class="wod-panel border-dashed p-4 text-sm"
        >
            <p class="text-xs font-semibold uppercase text-muted-foreground">
                Map attribution
            </p>
            <p class="mt-1 font-medium">
                {{ game.sourceMap.name }}
            </p>
            <p class="text-xs text-muted-foreground">
                Design by {{ game.sourceMap.by }} · gameplay still uses the procedural battlefield for now.
            </p>
        </div>

        <div class="wod-panel space-y-2 p-4">
            <h2 class="font-bold">Commanders</h2>
            <div
                v-for="slot in game.maxPlayers"
                :key="slot"
                class="flex items-center justify-between rounded-md border-2 border-foreground bg-background px-3 py-2"
            >
                <span class="text-sm font-semibold">Slot {{ slot }}</span>
                <template v-if="game.players[slot - 1]">
                    <span class="flex items-center gap-2 text-sm">
                        <span
                            class="wod-swatch !size-3 rounded-full"
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

        <div class="flex flex-wrap gap-2">
            <Link :href="lobbies().url">
                <Button variant="outline">Back</Button>
            </Link>
            <Button v-if="!game.isParticipant" @click="joinGame">
                Join lobby
            </Button>
            <Button
                v-if="game.isHost && game.canStart"
                @click="startGame"
            >
                Start battle
            </Button>
            <Link
                v-if="game.status === 'playing' && game.isParticipant"
                :href="play(game.uuid).url"
            >
                <Button>Enter battlefield</Button>
            </Link>
        </div>
    </div>
</template>
