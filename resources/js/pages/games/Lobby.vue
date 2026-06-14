<script setup lang="ts">
import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { Loader2, Plus, Zap } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { joinCode, leave, show, store } from '@/routes/games';
import { join as qsJoin, leave as qsLeave, status as qsStatus } from '@/routes/quick-start';

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

type PublishedMap = {
    uuid: string;
    name: string;
    teamCount: number;
    ownerName: string;
};

type QsStatus = {
    status: 'none' | 'queued' | 'matched';
    queueSize: number;
    gameUuid: string | null;
};

const props = defineProps<{
    lobbies: Lobby[];
    publishedMaps: PublishedMap[];
}>();

usePoll(2000, { only: ['lobbies'] });

const page = usePage();

const createForm = useForm({
    map_uuid: '',
});

const joinForm = useForm({
    code: '',
});

const selectedMap = computed(() =>
    props.publishedMaps.find((m) => m.uuid === createForm.map_uuid),
);

const myLobby = computed(() => props.lobbies.find((l) => l.isParticipant) ?? null);
const otherLobbies = computed(() => props.lobbies.filter((l) => !l.isParticipant));

function leaveLobby(uuid: string) {
    router.delete(leave(uuid).url);
}

function createLobby() {
    createForm.post(store().url);
}

function joinLobby() {
    joinForm.post(joinCode().url);
}

// ── Quick Start ──────────────────────────────────────────────────────────────

const qsState = ref<QsStatus>({ status: 'none', queueSize: 0, gameUuid: null });
const qsLoading = ref(false);
let qsPollTimer: ReturnType<typeof setInterval> | null = null;

function startQsPoll() {
    if (qsPollTimer !== null) return;
    qsPollTimer = setInterval(async () => {
        try {
            const res = await fetch(qsStatus().url, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data: QsStatus = await res.json();
            qsState.value = data;
            if (data.status === 'matched' && data.gameUuid) {
                stopQsPoll();
                router.visit(show(data.gameUuid).url);
            } else if (data.status === 'none') {
                stopQsPoll();
            }
        } catch {
            // network blip — keep polling
        }
    }, 2000);
}

function stopQsPoll() {
    if (qsPollTimer !== null) {
        clearInterval(qsPollTimer);
        qsPollTimer = null;
    }
}

async function joinQuickStart() {
    qsLoading.value = true;
    try {
        const res = await fetch(qsJoin().url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
        });
        if (!res.ok) return;
        const data: QsStatus = await res.json();
        qsState.value = data;
        if (data.status === 'matched' && data.gameUuid) {
            router.visit(show(data.gameUuid).url);
        } else if (data.status === 'queued') {
            startQsPoll();
        }
    } finally {
        qsLoading.value = false;
    }
}

async function leaveQuickStart() {
    stopQsPoll();
    await fetch(qsLeave().url, {
        method: 'DELETE',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            ),
        },
    });
    qsState.value = { status: 'none', queueSize: 0, gameUuid: null };
}

onBeforeUnmount(() => stopQsPoll());
</script>

<template>
    <Head title="Lobbies" />

    <div class="flex flex-col gap-8">
        <Heading
            title="Lobby Overview"
            description="Pick a published map — lobby size matches the map’s team count. Everyone must join before the host can start."
        />

        <!-- Your current lobby -->
        <div v-if="myLobby" class="space-y-3">
            <div class="flex items-center gap-2">
                <div class="wod-swatch bg-wod-yellow" aria-hidden="true" />
                <h2 class="font-bold">Your lobby</h2>
            </div>
            <div class="flex flex-col gap-3 wod-panel p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-bold tracking-widest">{{ myLobby.code }}</span>
                        <Badge variant="outline" class="border-foreground bg-wod-green-lt">
                            {{ myLobby.playerCount }}/{{ myLobby.maxPlayers }}
                        </Badge>
                        <Badge v-if="myLobby.isHost" variant="outline" class="border-foreground">
                            Host
                        </Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">Host: {{ myLobby.hostName }}</p>
                </div>
                <div class="flex w-full gap-2 sm:w-auto">
                    <Button variant="destructive" class="flex-1 sm:flex-none" @click="leaveLobby(myLobby.uuid)">
                        Leave
                    </Button>
                    <Link :href="show(myLobby.uuid).url" class="flex-1 sm:flex-none">
                        <Button variant="outline" class="w-full">View</Button>
                    </Link>
                </div>
            </div>
        </div>

        <!-- Quick Start: queued / matched state (shown when not idle) -->
        <div v-if="!myLobby && qsState.status !== 'none'" class="wod-panel p-5">
            <div class="flex items-center gap-2 mb-3">
                <div class="wod-swatch bg-wod-yellow" aria-hidden="true" />
                <h2 class="font-bold">Quick Start</h2>
            </div>
            <template v-if="qsState.status === 'queued'">
                <div class="flex items-center gap-3 mb-4">
                    <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
                    <div>
                        <p class="text-sm font-semibold">Finding you a game…</p>
                        <p class="text-xs text-muted-foreground">
                            {{ qsState.queueSize }} {{ qsState.queueSize === 1 ? 'person' : 'people' }} in the pool
                        </p>
                    </div>
                </div>
                <Button variant="outline" size="sm" @click="leaveQuickStart">
                    Cancel
                </Button>
            </template>
            <template v-else-if="qsState.status === 'matched'">
                <p class="text-sm font-semibold text-green-600">Match found — redirecting…</p>
            </template>
        </div>

        <!-- Action panels: Create lobby (auth) · Join by code · Quick Start -->
        <div
            v-if="!myLobby && qsState.status === 'none'"
            class="grid gap-4"
            :class="page.props.auth.user ? 'lg:grid-cols-3' : 'lg:grid-cols-2'"
        >
            <div v-if="page.props.auth.user" class="wod-panel flex flex-col gap-4 p-5">
                <div class="flex items-center gap-2">
                    <div class="wod-swatch bg-wod-red" aria-hidden="true" />
                    <h2 class="font-bold">Create lobby</h2>
                </div>
                <div v-if="publishedMaps.length === 0" class="text-sm text-muted-foreground">
                    No published maps yet. Publish one from the Map Builder or explore the gallery.
                </div>
                <template v-else>
                    <div class="flex-1 space-y-2">
                        <Label for="map_uuid">Published map</Label>
                        <Select v-model="createForm.map_uuid">
                            <SelectTrigger id="map_uuid" class="w-full">
                                <SelectValue placeholder="Choose a map…" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="m in publishedMaps"
                                    :key="m.uuid"
                                    :value="m.uuid"
                                >
                                    {{ m.name }} · {{ m.teamCount }} teams · {{ m.ownerName }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="createForm.errors.map_uuid" />
                        <p
                            v-if="selectedMap"
                            class="text-xs text-muted-foreground"
                        >
                            This lobby will hold
                            <strong>{{ selectedMap.teamCount }}</strong>
                            commanders (one per team on the map).
                        </p>
                    </div>
                    <Button
                        :disabled="createForm.processing || !createForm.map_uuid"
                        @click="createLobby"
                    >
                        <Plus class="mr-2 h-4 w-4" />
                        Create lobby
                    </Button>
                </template>
            </div>

            <!-- Quick Start: idle state -->
            <div class="wod-panel flex flex-col gap-4 p-5">
                <div class="flex items-center gap-2">
                    <div class="wod-swatch bg-wod-yellow" aria-hidden="true" />
                    <h2 class="font-bold">Quick Start</h2>
                </div>
                <p class="flex-1 text-sm text-muted-foreground">
                    Don't mind what you play? Join the pool and we'll drop you straight into a lobby the moment there's a fit — no browsing required.
                </p>
                <Button :disabled="qsLoading" @click="joinQuickStart">
                    <Zap class="mr-2 h-4 w-4" />
                    Quick Start
                </Button>
            </div>

            <div class="wod-panel flex flex-col gap-4 p-5">
                <div class="flex items-center gap-2">
                    <div class="wod-swatch bg-wod-blue" aria-hidden="true" />
                    <h2 class="font-bold">Join by code</h2>
                </div>
                <div class="flex-1 space-y-2">
                    <Label for="code">Lobby code</Label>
                    <Input
                        id="code"
                        v-model="joinForm.code"
                        maxlength="6"
                        class="uppercase tracking-widest"
                        placeholder="ABC123"
                    />
                    <InputError :message="joinForm.errors.code" />
                </div>
                <Button
                    variant="outline"
                    :disabled="joinForm.processing"
                    @click="joinLobby"
                >
                    Join lobby
                </Button>
            </div>
        </div>

        <div class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <div class="wod-swatch bg-wod-green-lt" aria-hidden="true" />
                    <h2 class="font-bold">Open lobbies</h2>
                </div>
                <div class="wod-chip" role="status">
                    <span class="relative flex size-2 shrink-0" aria-hidden="true">
                        <span
                            class="absolute inline-flex size-full animate-ping rounded-full bg-wod-green-dk opacity-50"
                        />
                        <span
                            class="relative inline-flex size-2 rounded-full border border-foreground bg-wod-green-dk"
                        />
                    </span>
                    <span class="text-xs font-semibold uppercase tracking-wide text-wod-green-dk">
                        Live
                    </span>
                </div>
            </div>
            <div
                v-if="otherLobbies.length === 0"
                class="wod-panel-dashed p-8 text-center text-muted-foreground"
            >
                No open lobbies. Create one to get started.
            </div>
            <div
                v-for="lobby in otherLobbies"
                :key="lobby.uuid"
                class="flex flex-col gap-3 wod-panel p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-bold tracking-widest">{{ lobby.code }}</span>
                        <Badge
                            variant="outline"
                            class="border-foreground bg-wod-green-lt"
                        >
                            {{ lobby.playerCount }}/{{ lobby.maxPlayers }}
                        </Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Host: {{ lobby.hostName }}
                    </p>
                </div>
                <div class="flex w-full gap-2 sm:w-auto">
                    <Button
                        v-if="lobby.isParticipant"
                        variant="destructive"
                        class="flex-1 sm:flex-none"
                        @click="leaveLobby(lobby.uuid)"
                    >
                        Leave
                    </Button>
                    <Link :href="show(lobby.uuid).url" class="flex-1 sm:flex-none">
                        <Button variant="outline" class="w-full">View</Button>
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
