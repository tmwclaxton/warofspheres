<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import MapExplorePreview from '@/components/map-explore/MapExplorePreview.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { MapDataPayload } from '@/lib/mapEditorGrid';
import { join, leave, play, replay as replayRoute, start } from '@/routes/games';
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
    players: Array<{ slot: number; name: string; color: string; teamIndex: number }>;
    sourceMap: { uuid: string; name: string; by: string } | null;
    mapPreviewData: MapDataPayload | null;
    abortedReason?: string | null;
};

const props = defineProps<{
    game: Lobby;
}>();

// ── Lobby polling ─────────────────────────────────────────────────────────────

const commanderByDisplaySlot = computed(() => {
    const out: Record<number, Lobby['players'][0] | undefined> = {};
    for (let i = 1; i <= props.game.maxPlayers; i++) {
        out[i] = props.game.players.find((p) => p.slot === i - 1);
    }
    return out;
});

let pollTimer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    pollTimer = setInterval(() => {
        if (props.game.status === 'lobby') {
            router.reload({ only: ['game'] });
        }
    }, 2000);
});

watch(
    () => props.game.status,
    (status) => {
        if (status === 'playing' && props.game.isParticipant) {
            router.visit(play(props.game.uuid).url);
        }
    },
);

onBeforeUnmount(() => {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
});

function joinGame(): void {
    router.post(join(props.game.uuid).url);
}

function startGame(): void {
    router.post(start(props.game.uuid).url);
}

function leaveGame(): void {
    router.delete(leave(props.game.uuid).url);
}

const codeCopied = ref(false);

async function copyCode(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.game.code);
        codeCopied.value = true;
        setTimeout(() => {
            codeCopied.value = false;
        }, 2000);
    } catch {
        // Clipboard API unavailable (non-secure context)
    }
}
</script>

<template>
    <Head :title="`Lobby ${game.code}`" />

    <div class="mx-auto flex max-w-2xl flex-col gap-5">
        <div
            v-if="game.status === 'finished' && game.abortedReason === 'lobby_timeout'"
            class="wod-panel border-2 border-dashed border-foreground/40 bg-card/80 p-4 text-center text-sm"
        >
            <p class="font-semibold text-foreground">This lobby closed automatically</p>
            <p class="mt-1 text-muted-foreground">
                Open lobbies expire after one hour if the battle never starts.
            </p>
        </div>

        <div class="wod-panel p-5 text-center sm:p-8">
            <p class="text-xs font-semibold text-muted-foreground uppercase">
                Lobby code
            </p>
            <div class="mt-2 flex items-center justify-center gap-3">
                <p class="font-display text-3xl font-bold tracking-widest sm:text-4xl">
                    {{ game.code }}
                </p>
                <Button
                    size="sm"
                    variant="outline"
                    class="text-xs"
                    @click="copyCode"
                >
                    {{ codeCopied ? 'Copied!' : 'Copy' }}
                </Button>
            </div>
            <p class="mt-3 text-sm text-muted-foreground">
                {{ game.playerCount }} / {{ game.maxPlayers }} commanders ready
            </p>
            <p
                v-if="game.status === 'lobby'"
                class="mt-2 text-xs text-muted-foreground"
            >
                Waiting for all commanders. The battle starts the moment everyone joins.
            </p>
        </div>

        <div
            v-if="game.sourceMap"
            class="wod-panel border-dashed p-4 text-sm"
        >
            <p class="text-xs font-semibold uppercase text-muted-foreground">
                Battlefield map
            </p>
            <p class="mt-1 font-medium">
                {{ game.sourceMap.name }}
                <span class="text-muted-foreground font-normal">by {{ game.sourceMap.by }}</span>
            </p>
            <div
                v-if="game.mapPreviewData"
                class="mt-3 flex justify-center overflow-hidden rounded-md border border-border"
            >
                <MapExplorePreview :data="game.mapPreviewData" />
            </div>
        </div>

        <div class="wod-panel space-y-2 p-4">
            <h2 class="font-bold">Commanders</h2>
            <div
                v-for="slotNum in game.maxPlayers"
                :key="slotNum"
                class="flex items-center justify-between rounded-md border-2 border-foreground bg-background px-3 py-2"
            >
                <span class="text-sm font-semibold">Slot {{ slotNum }}</span>
                <template v-if="commanderByDisplaySlot[slotNum]">
                    <span class="flex items-center gap-2 text-sm">
                        <span
                            class="wod-swatch !size-3 rounded-full"
                            :style="{ backgroundColor: commanderByDisplaySlot[slotNum]!.color }"
                        />
                        {{ commanderByDisplaySlot[slotNum]!.name }}
                        <Badge
                            v-if="commanderByDisplaySlot[slotNum]!.teamIndex > 0"
                            variant="outline"
                            class="text-[0.6rem]"
                        >
                            Team {{ commanderByDisplaySlot[slotNum]!.teamIndex }}
                        </Badge>
                    </span>
                </template>
                <Badge v-else variant="outline">Empty</Badge>
            </div>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
            <Link :href="lobbies().url" class="w-full sm:w-auto">
                <Button variant="outline" class="w-full sm:w-auto">Back</Button>
            </Link>
            <Button
                v-if="game.isParticipant && game.status === 'lobby'"
                variant="destructive"
                class="w-full sm:w-auto"
                @click="leaveGame"
            >
                Leave lobby
            </Button>
            <Button v-if="!game.isParticipant" class="w-full sm:w-auto" @click="joinGame">
                Join lobby
            </Button>
            <Button
                v-if="game.isHost && game.canStart"
                class="w-full sm:w-auto"
                @click="startGame"
            >
                Start battle
            </Button>
            <Link
                v-if="game.isParticipant && game.status === 'playing'"
                :href="play(game.uuid).url"
                class="w-full sm:w-auto"
            >
                <Button class="w-full sm:w-auto">Enter battlefield</Button>
            </Link>
            <Link
                v-if="game.status === 'finished'"
                :href="replayRoute(game.uuid).url"
                class="w-full sm:w-auto"
            >
                <Button variant="outline" class="w-full sm:w-auto">
                    Watch Replay
                </Button>
            </Link>
        </div>
    </div>
</template>
