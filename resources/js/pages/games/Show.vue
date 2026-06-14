<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import MapExplorePreview from '@/components/map-explore/MapExplorePreview.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { MapDataPayload } from '@/lib/mapEditorGrid';
import { join, leave, play, replay as replayRoute, start } from '@/routes/games';
import { index as lobbies } from '@/routes/lobbies';

const COUNTDOWN_SECONDS = 10;

const PALETTE = [
    '#FF0000',
    '#0000FF',
    '#FF9600',
    '#AF00AF',
    '#00AF00',
    '#00FFFF',
    '#FF69B4',
    '#8B4513',
    '#FFD700',
    '#4B0082',
    '#00CED1',
    '#FF6347',
];

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
    countdownStartedAt: string | null;
    mySlot: number | null;
    myColor: string | null;
    myDisplayName: string | null;
    players: Array<{ slot: number; name: string; color: string; teamIndex: number }>;
    sourceMap: { uuid: string; name: string; by: string } | null;
    mapPreviewData: MapDataPayload | null;
    abortedReason?: string | null;
};

const props = defineProps<{
    game: Lobby;
}>();

const page = usePage();
const guestDisplayName = ref('');

// ── Profile editing ───────────────────────────────────────────────────────────

const editingProfile = ref(false);
const profileName = ref(props.game.myDisplayName ?? '');
const profileColor = ref(props.game.myColor ?? '#FF0000');
const profileSaving = ref(false);

watch(
    () => [props.game.myDisplayName, props.game.myColor],
    ([name, color]) => {
        if (!editingProfile.value) {
            profileName.value = name ?? '';
            profileColor.value = color ?? '#FF0000';
        }
    },
);

async function saveProfile(): Promise<void> {
    profileSaving.value = true;
    try {
        await fetch(`/games/${props.game.uuid}/player-profile`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
            body: JSON.stringify({
                display_name: profileName.value.trim() || null,
                color: profileColor.value,
            }),
        });
        editingProfile.value = false;
        router.reload({ only: ['game'] });
    } finally {
        profileSaving.value = false;
    }
}

// ── Countdown ─────────────────────────────────────────────────────────────────

const countdownSeconds = ref<number | null>(null);
let countdownTick: ReturnType<typeof setInterval> | null = null;

function computeCountdown(): number | null {
    if (!props.game.countdownStartedAt) {
        return null;
    }
    const elapsed = (Date.now() - new Date(props.game.countdownStartedAt).getTime()) / 1000;
    const remaining = COUNTDOWN_SECONDS - Math.floor(elapsed);
    return remaining > 0 ? remaining : 0;
}

function startCountdownTick(): void {
    if (countdownTick !== null) {
        return;
    }
    countdownSeconds.value = computeCountdown();
    countdownTick = setInterval(() => {
        countdownSeconds.value = computeCountdown();
        if (countdownSeconds.value !== null && countdownSeconds.value <= 0) {
            stopCountdownTick();
        }
    }, 250);
}

function stopCountdownTick(): void {
    if (countdownTick !== null) {
        clearInterval(countdownTick);
        countdownTick = null;
    }
}

watch(
    () => props.game.countdownStartedAt,
    (val) => {
        if (val) {
            startCountdownTick();
        }
    },
    { immediate: true },
);

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
    stopCountdownTick();
});

function joinGame(): void {
    const payload =
        page.props.auth.user === null && guestDisplayName.value.trim() !== ''
            ? { display_name: guestDisplayName.value.trim() }
            : {};
    router.post(join(props.game.uuid).url, payload);
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

        <!-- Countdown banner -->
        <div
            v-if="game.status === 'lobby' && countdownSeconds !== null"
            class="wod-panel border-2 border-wod-yellow bg-wod-yellow/10 p-5 text-center"
        >
            <p class="text-sm font-semibold uppercase tracking-widest text-foreground/70">
                Battle begins in
            </p>
            <p class="font-display mt-1 text-6xl font-bold tabular-nums">
                {{ countdownSeconds }}
            </p>
            <p class="mt-2 text-xs text-muted-foreground">
                Customise your display name and colour below before the countdown ends!
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
                v-if="game.status === 'lobby' && countdownSeconds === null"
                class="mt-2 text-xs text-muted-foreground"
            >
                Waiting room updates every few seconds. When the host starts, you will jump to the battlefield automatically.
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

        <!-- Profile panel: shown to participants while in lobby -->
        <div
            v-if="game.isParticipant && game.status === 'lobby'"
            class="wod-panel space-y-4 p-4"
        >
            <div class="flex items-center justify-between">
                <h2 class="font-bold">Your commander</h2>
                <button
                    v-if="!editingProfile"
                    class="text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                    @click="editingProfile = true"
                >
                    Edit
                </button>
            </div>

            <div v-if="!editingProfile" class="flex items-center gap-3">
                <span
                    class="size-5 shrink-0 rounded-full border-2 border-foreground"
                    :style="{ backgroundColor: game.myColor ?? '#888' }"
                />
                <span class="text-sm font-semibold">{{ game.myDisplayName }}</span>
            </div>

            <template v-else>
                <div class="space-y-2">
                    <Label for="profile-name">Display name</Label>
                    <Input
                        id="profile-name"
                        v-model="profileName"
                        maxlength="50"
                        placeholder="Commander"
                        class="max-w-xs"
                    />
                    <p
                        v-if="page.props.auth.user"
                        class="text-xs text-muted-foreground"
                    >
                        This will be saved to your account.
                    </p>
                </div>
                <div class="space-y-2">
                    <Label>Colour</Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="hex in PALETTE"
                            :key="hex"
                            class="size-7 rounded-full border-2 transition-transform hover:scale-110"
                            :class="profileColor === hex ? 'border-foreground scale-110' : 'border-transparent'"
                            :style="{ backgroundColor: hex }"
                            :title="hex"
                            @click="profileColor = hex"
                        />
                    </div>
                    <input
                        v-model="profileColor"
                        type="color"
                        class="mt-1 h-8 w-16 cursor-pointer rounded border border-border bg-background p-0.5"
                        title="Custom colour"
                    />
                </div>
                <div class="flex gap-2">
                    <Button :disabled="profileSaving" @click="saveProfile">
                        {{ profileSaving ? 'Saving…' : 'Save' }}
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="profileSaving"
                        @click="editingProfile = false"
                    >
                        Cancel
                    </Button>
                </div>
            </template>
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
            <div
                v-if="!game.isParticipant && !page.props.auth.user"
                class="flex w-full flex-col gap-2 sm:w-auto"
            >
                <div class="space-y-1">
                    <Label for="guest-name" class="text-xs">Display name (optional)</Label>
                    <Input
                        id="guest-name"
                        v-model="guestDisplayName"
                        maxlength="50"
                        placeholder="Guest"
                        class="max-w-xs"
                    />
                </div>
            </div>
            <Button v-if="!game.isParticipant" class="w-full sm:w-auto" @click="joinGame">
                Join lobby
            </Button>
            <Button
                v-if="game.isHost && game.canStart && countdownSeconds === null"
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
