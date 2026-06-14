<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { MessageSquare, Building2, ChevronDown, ChevronUp } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import GameCanvas from '@/components/game/GameCanvas.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index as lobbies } from '@/routes/lobbies';
import { useDraftStore } from '@/stores/draftStore';
import { useGameStore } from '@/stores/gameStore';
import { useToastStore } from '@/stores/toastStore';

/** Vite build mode; exposed for Dev HUD (cannot use `import.meta` inside Vue templates). */
const viteMode = import.meta.env.MODE;

type GamePayload = {
    uuid: string;
    code: string;
    maxPlayers: number;
    slot: number;
    color: string;
    players: Array<{ slot: number; name: string; color: string; teamIndex: number }>;
};

type GameConstants = {
    recruitCost: number;
    recruitCostTank: number;
    maxArmyPerPlayer: number;
    tickRate: number;
};

const props = withDefaults(
    defineProps<{
        game: GamePayload;
        snapshotUrl: string;
        spectatorMode?: boolean;
        gameConstants: GameConstants;
    }>(),
    {
        spectatorMode: false,
    },
);

const TICK_RATE = computed(() => props.gameConstants.tickRate);

const page = usePage();
const store = useGameStore();
const draftStore = useDraftStore();
const toast = useToastStore();

/** How often we pull JSON snapshots during a live match (backs up Reverb / shows tick progress). */
const MATCH_SNAPSHOT_POLL_MS = 1800;

/** Consecutive polls with no `worldTick` advance ⇒ likely tick worker missing. */
const SIMULATION_STALL_POLL_THRESHOLD = 5;

const spectatePollTimer = ref<ReturnType<typeof setInterval> | null>(null);
const participatePollTimer = ref<ReturnType<typeof setInterval> | null>(null);
const participateLivePollTimer = ref<ReturnType<typeof setInterval> | null>(null);
const showSlowLoadHint = ref(false);
const lastCityOwners = ref<Record<number, number | null>>({});
const simulationStallPolls = ref(0);
let slowLoadHintTimer: ReturnType<typeof setTimeout> | null = null;

const devHudOpen = ref(false);

function hasDevQuery(url: string): boolean {
    const i = url.indexOf('?');
    if (i === -1) {
        return false;
    }

    try {
        return new URLSearchParams(url.slice(i + 1)).get('dev') === '1';
    } catch {
        return false;
    }
}

const devHudEligible = computed((): boolean => {
    if (page.props.appDebug === true) {
        return true;
    }

    if (import.meta.env.DEV) {
        return true;
    }

    return hasDevQuery(page.url);
});

const snapshotPath = computed((): string => {
    const u = props.snapshotUrl;

    if (u.startsWith('http://') || u.startsWith('https://')) {
        try {
            const parsed = new URL(u);

            return parsed.pathname + parsed.search;
        } catch {
            return u;
        }
    }

    return u;
});

function formatTickTime(ms: number | null): string {
    if (ms === null) {
        return '—';
    }

    try {
        return new Date(ms).toLocaleTimeString(undefined, {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        });
    } catch {
        return '—';
    }
}

watch(devHudOpen, (open) => {
    if (typeof sessionStorage === 'undefined') {
        return;
    }

    sessionStorage.setItem('wod_dev_hud_open', open ? '1' : '0');
});

const broadcastConnection = computed(() => {
    const uid = page.props.auth.user?.id;

    if (uid !== undefined && uid !== null) {
        return `u${uid}`;
    }

    const g = page.props.guestBroadcast;

    return typeof g === 'string' && g.length > 0 ? g : null;
});

const victoryTitle = computed(() => {
    if (props.spectatorMode) {
        return store.winnerName ? `${store.winnerName} wins` : 'Match over';
    }

    if (store.matchEnded && store.winnerSlot === null && store.winnerUserId === null) {
        return store.winnerName ?? 'Match ended';
    }

    if (store.winnerSlot !== null && store.winnerSlot === props.game.slot) {
        return 'Victory!';
    }

    if (store.winnerUserId !== null && store.winnerUserId === page.props.auth.user?.id) {
        return 'Victory!';
    }

    return 'Defeat';
});

const myEconomy = computed(() => store.economy?.[props.game.slot] ?? null);

const myCredits = computed(() => myEconomy.value?.credits ?? null);

/** Cities owned by this player, for the production-control panel. */
const ownedCities = computed(() => {
    const cities = store.latestState?.cities ?? [];
    return cities.filter((c) => c.ownerSlot === props.game.slot);
});

const productionPanelOpen = ref(false);
const chatPanelOpen = ref(false);
const chatInput = ref('');

/** Local slider state — keyed by city id. Avoids slider snapping back during async saves. */
type CitySliders = { tankRatio: number; speedMultiplier: number };
const localCitySliders = ref<Record<number, CitySliders>>({});

function citySliders(cityId: number): CitySliders {
    if (!localCitySliders.value[cityId]) {
        const city = ownedCities.value.find((c) => c.id === cityId);
        localCitySliders.value[cityId] = {
            tankRatio: city?.productionTankRatio ?? 0,
            speedMultiplier: city?.productionSpeedMultiplier ?? 1.0,
        };
    }
    return localCitySliders.value[cityId];
}

watch(
    () => store.latestState?.cities,
    (cities) => {
        if (!cities) return;
        for (const city of cities) {
            // Only sync from server if there is no local entry yet (first load)
            if (localCitySliders.value[city.id] === undefined) {
                localCitySliders.value[city.id] = {
                    tankRatio: city.productionTankRatio ?? 0,
                    speedMultiplier: city.productionSpeedMultiplier ?? 1.0,
                };
            }
        }
    },
    { deep: true, immediate: true },
);

function saveCitySliders(cityId: number) {
    const sliders = localCitySliders.value[cityId];
    if (!sliders) return;
    store.setCityProduction(
        props.game.uuid,
        cityId,
        sliders.speedMultiplier <= 0 ? 'none' : 'infantry',
        sliders.tankRatio,
        sliders.speedMultiplier,
        // No snapshot pull — avoids slider snapping back; polling will sync eventually
    );
}

function openChat() {
    chatPanelOpen.value = true;
    productionPanelOpen.value = false;
    store.clearUnreadChat();
}

function openProduction() {
    productionPanelOpen.value = true;
    chatPanelOpen.value = false;
}

async function submitChat() {
    const body = chatInput.value.trim();
    if (!body) {
        return;
    }
    await store.sendChatMessage(props.game.uuid, body);
    chatInput.value = '';
}

watch(chatPanelOpen, (open) => {
    if (open) {
        store.clearUnreadChat();
    }
});

const incomePerTick = computed(() => myEconomy.value?.incomePerTick ?? 0);

const incomePerSecondHint = computed(
    () => Math.round((incomePerTick.value / TICK_RATE.value) * 100) / 100,
);

const showSimulationStallHint = computed(
    () =>
        !props.spectatorMode
        && store.initialized
        && !store.matchEnded
        && simulationStallPolls.value >= SIMULATION_STALL_POLL_THRESHOLD,
);

watch(
    () => store.worldTick,
    () => {
        simulationStallPolls.value = 0;
    },
);

watch(
    () => store.latestState?.cities,
    (cities) => {
        if (!cities || props.spectatorMode) {
            return;
        }

        for (const c of cities) {
            const cur = c.ownerSlot ?? null;
            const prev = lastCityOwners.value[c.id];

            if (prev !== undefined && prev !== cur) {
                const isCapital = c.markerType === 'capital';
                const label = isCapital ? 'Capital' : 'Flag';

                if (cur === props.game.slot) {
                    toast.success(`${label} captured.`);
                } else if (prev === props.game.slot) {
                    toast.warning(`${label} lost.`);
                }
            }

            lastCityOwners.value[c.id] = cur;
        }
    },
    { deep: true },
);

onMounted(() => {
    if (typeof sessionStorage !== 'undefined' && sessionStorage.getItem('wod_dev_hud_open') === '1') {
        devHudOpen.value = true;
    }

    if (props.spectatorMode) {
        store.disconnect();
        void store.pullSnapshot(props.snapshotUrl, { treat404AsEnded: true });
        spectatePollTimer.value = setInterval(() => {
            void store.pullSnapshot(props.snapshotUrl, { treat404AsEnded: true });
        }, 1500);

        return;
    }

    const conn = broadcastConnection.value;

    if (conn) {
        store.connect(props.game.uuid, conn, props.game.slot, props.game.color);
    } else {
        store.gameUuid = props.game.uuid;
        store.slot = props.game.slot;
        store.color = props.game.color;
    }

    void store.pullSnapshot(props.snapshotUrl);

    participatePollTimer.value = setInterval(() => {
        if (store.initialized || store.matchEnded) {
            if (participatePollTimer.value !== null) {
                clearInterval(participatePollTimer.value);
                participatePollTimer.value = null;
            }

            return;
        }

        void store.pullSnapshot(props.snapshotUrl);
    }, 1200);

    participateLivePollTimer.value = setInterval(() => {
        void (async () => {
            if (store.matchEnded) {
                return;
            }

            if (!store.initialized) {
                return;
            }

            const tickBefore = store.worldTick;
            await store.pullSnapshot(props.snapshotUrl);

            if (store.matchEnded) {
                return;
            }

            if (store.worldTick === tickBefore && store.initialized) {
                simulationStallPolls.value += 1;
            } else {
                simulationStallPolls.value = 0;
            }
        })();
    }, MATCH_SNAPSHOT_POLL_MS);

    slowLoadHintTimer = setTimeout(() => {
        showSlowLoadHint.value = true;
    }, 8000);
});

onUnmounted(() => {
    if (spectatePollTimer.value !== null) {
        clearInterval(spectatePollTimer.value);
        spectatePollTimer.value = null;
    }

    if (participatePollTimer.value !== null) {
        clearInterval(participatePollTimer.value);
        participatePollTimer.value = null;
    }

    if (participateLivePollTimer.value !== null) {
        clearInterval(participateLivePollTimer.value);
        participateLivePollTimer.value = null;
    }

    if (slowLoadHintTimer !== null) {
        clearTimeout(slowLoadHintTimer);
        slowLoadHintTimer = null;
    }

    store.disconnect();
});
</script>

<template>
    <Head title="Battlefield" />

    <div class="flex h-svh min-h-0 flex-col overflow-hidden bg-background text-foreground">
        <!-- Slim top bar -->
        <header class="wod-bar-top shrink-0 flex items-center gap-3 border-b border-foreground/10 px-3 py-2 sm:px-4">
            <div class="min-w-0 shrink-0">
                <p class="text-[0.55rem] font-semibold uppercase tracking-widest text-muted-foreground">
                    War of Dots
                </p>
                <p class="font-mono text-sm font-bold leading-none tracking-widest">
                    {{ game.code }}
                    <span v-if="spectatorMode" class="ml-1 text-xs font-normal text-muted-foreground">
                        · spectating
                    </span>
                </p>
            </div>

            <div
                class="flex min-w-0 flex-1 gap-1.5 overflow-x-auto [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            >
                <span
                    v-for="player in [...game.players].sort((a, b) => a.slot - b.slot)"
                    :key="player.slot"
                    class="wod-chip shrink-0"
                >
                    <span
                        class="inline-block size-2 rounded-full"
                        :style="{ backgroundColor: player.color }"
                    />
                    {{ player.name }}
                </span>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <Badge
                    v-if="store.initialized"
                    variant="outline"
                    class="hidden font-mono text-[0.65rem] sm:inline-flex"
                    title="Server simulation tick"
                >
                    T{{ store.worldTick }}
                </Badge>
                <Button
                    v-if="devHudEligible"
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="font-mono text-[0.65rem]"
                    @click="devHudOpen = !devHudOpen"
                >
                    Dev
                </Button>
                <ThemeToggle />
                <Link :href="lobbies().url">
                    <Button size="sm" variant="ghost">Exit</Button>
                </Link>
            </div>
        </header>

        <!-- Canvas area — fills all remaining height -->
        <div class="relative min-h-0 flex-1">
            <!-- Loading overlays -->
            <div
                v-if="!store.initialized && !store.matchEnded && store.winnerUserId === null && store.winnerSlot === null"
                class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 bg-background/90 text-center"
            >
                <p class="text-sm font-semibold text-muted-foreground">
                    Loading battlefield…
                </p>
                <p v-if="!spectatorMode" class="max-w-xs text-xs text-muted-foreground">
                    Map data loads over HTTP even without websockets.
                </p>
                <p
                    v-if="!spectatorMode && showSlowLoadHint && !store.initialized"
                    class="max-w-xs text-xs text-muted-foreground"
                >
                    Still loading? Refresh or run
                    <code class="rounded bg-muted px-1 font-mono">php artisan reverb:start</code>.
                </p>
            </div>

            <GameCanvas
                :read-only="spectatorMode"
                :snapshot-fetch-url="spectatorMode ? '' : snapshotUrl"
            />

            <!-- Victory / defeat overlay -->
            <div
                v-if="!spectatorMode && (store.matchEnded || store.winnerUserId !== null || store.winnerSlot !== null)"
                class="absolute inset-0 z-20 flex items-center justify-center bg-foreground/50"
            >
                <div class="wod-panel px-6 py-6 text-center shadow-2xl sm:px-10 sm:py-8">
                    <p class="font-display text-3xl font-bold">{{ victoryTitle }}</p>
                    <Link :href="lobbies().url" class="mt-5 inline-block">
                        <Button size="lg">Return to lobbies</Button>
                    </Link>
                </div>
            </div>
            <div
                v-else-if="spectatorMode && store.matchEnded"
                class="absolute inset-0 z-20 flex items-center justify-center bg-foreground/50"
            >
                <div class="wod-panel px-6 py-6 text-center shadow-2xl sm:px-10 sm:py-8">
                    <p class="font-display text-3xl font-bold">Match ended</p>
                    <p class="mt-2 text-sm text-muted-foreground">The live state is no longer available.</p>
                    <Link :href="lobbies().url" class="mt-5 inline-block">
                        <Button size="lg">Return to lobbies</Button>
                    </Link>
                </div>
            </div>

            <!-- Simulation stall alert (canvas overlay) -->
            <div
                v-if="showSimulationStallHint"
                class="absolute left-3 right-3 top-3 z-10 rounded-md border border-destructive/60 bg-background/95 px-3 py-2 text-xs text-destructive shadow-md backdrop-blur-sm"
                role="alert"
            >
                World time is not advancing — ensure
                <code class="rounded bg-muted px-1 font-mono text-foreground">game:tick --daemon</code>
                is running.
            </div>

            <!-- Left-side panel toggles (Chat & Cities) -->
            <div
                v-if="!spectatorMode && store.initialized && !store.matchEnded"
                class="pointer-events-none absolute left-0 top-0 p-3"
            >
                <div class="pointer-events-auto flex flex-col gap-1.5">
                    <Button
                        size="sm"
                        variant="outline"
                        class="relative gap-1.5"
                        @click="chatPanelOpen ? (chatPanelOpen = false) : openChat()"
                    >
                        <MessageSquare class="size-3.5" />
                        <span class="hidden sm:inline">Chat</span>
                        <Badge
                            v-if="store.unreadChatCount > 0 && !chatPanelOpen"
                            class="absolute -right-1.5 -top-1.5 size-4 justify-center p-0 text-[0.55rem]"
                        >
                            {{ store.unreadChatCount }}
                        </Badge>
                    </Button>
                    <Button
                        v-if="ownedCities.length > 0"
                        size="sm"
                        variant="outline"
                        class="gap-1.5"
                        @click="productionPanelOpen ? (productionPanelOpen = false) : openProduction()"
                    >
                        <Building2 class="size-3.5" />
                        <span class="hidden sm:inline">Cities</span>
                    </Button>
                </div>
            </div>

            <!-- Bottom HUD (only in active play, floats over canvas) -->
            <div
                v-if="!spectatorMode && store.initialized && !store.matchEnded"
                class="pointer-events-none absolute inset-x-0 bottom-0 p-3"
            >
                <div class="flex items-end gap-2">
                    <!-- Economy card -->
                    <div
                        class="pointer-events-auto shrink-0 rounded-xl border border-border/60 bg-background/90 px-3 py-2.5 shadow-lg backdrop-blur-sm"
                    >
                        <p class="text-[0.55rem] font-semibold uppercase tracking-widest text-muted-foreground">Credits</p>
                        <p class="font-mono text-xl font-bold leading-none">{{ myCredits ?? '—' }}</p>
                        <p v-if="incomePerTick > 0" class="mt-0.5 text-[0.6rem] text-muted-foreground">
                            +{{ incomePerTick }}/tick
                        </p>
                    </div>

                    <!-- Orders -->
                    <div class="pointer-events-auto flex flex-1 justify-center gap-1.5">
                        <Button
                            v-if="draftStore.draftPaths.length > 0"
                            size="sm"
                            variant="outline"
                            class="whitespace-nowrap"
                            title="Clear all drafted paths (C)"
                            @click="draftStore.clearDrafts()"
                        >
                            Clear
                        </Button>
                        <Button
                            size="sm"
                            class="whitespace-nowrap"
                            title="Send drafted orders to the server (Space)"
                            @click="store.submitOrders(game.uuid, { snapshotFetchUrl: snapshotUrl })"
                        >
                            Execute Orders
                            <span class="ml-1.5 opacity-60">(Spacebar)</span>
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Spectator footer hint -->
            <div
                v-if="spectatorMode && store.initialized"
                class="pointer-events-none absolute inset-x-0 bottom-0 p-3 text-center text-[0.65rem] text-muted-foreground"
            >
                Spectating · slot&nbsp;1 vision · auto-refresh
            </div>

            <!-- Chat floating panel -->
            <div
                v-if="chatPanelOpen && store.initialized"
                class="absolute left-3 top-28 z-10 w-72 rounded-xl border border-border/60 bg-background/95 shadow-xl backdrop-blur-sm"
            >
                <div class="flex items-center justify-between border-b border-border/40 px-3 py-2">
                    <span class="text-xs font-semibold">Chat</span>
                    <button
                        class="text-muted-foreground hover:text-foreground"
                        @click="chatPanelOpen = false"
                    >
                        <ChevronDown class="size-4" />
                    </button>
                </div>
                <div class="flex flex-col gap-2 p-3">
                    <div class="h-36 overflow-y-auto rounded-lg bg-muted/40 p-2 text-xs">
                        <p v-if="store.chatMessages.length === 0" class="text-muted-foreground">
                            No messages yet.
                        </p>
                        <div
                            v-for="msg in store.chatMessages"
                            :key="msg.id"
                            class="mb-1 leading-snug"
                        >
                            <span class="font-semibold">{{ msg.senderName }}: </span>
                            <span class="text-muted-foreground">{{ msg.body }}</span>
                        </div>
                    </div>
                    <form
                        v-if="!spectatorMode"
                        class="flex gap-1.5"
                        @submit.prevent="submitChat"
                    >
                        <input
                            v-model="chatInput"
                            maxlength="200"
                            placeholder="Message…"
                            class="min-w-0 flex-1 rounded-md border border-border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-ring"
                        />
                        <Button type="submit" size="sm">Send</Button>
                    </form>
                </div>
            </div>

            <!-- Troop production floating panel -->
            <div
                v-if="productionPanelOpen && !spectatorMode && store.initialized && ownedCities.length > 0"
                class="absolute left-3 top-28 z-10 w-80 rounded-xl border border-border/60 bg-background/95 shadow-xl backdrop-blur-sm"
            >
                <div class="flex items-center justify-between border-b border-border/40 px-3 py-2">
                    <span class="text-xs font-semibold">Troop Production</span>
                    <button
                        class="text-muted-foreground hover:text-foreground"
                        @click="productionPanelOpen = false"
                    >
                        <ChevronDown class="size-4" />
                    </button>
                </div>
                <div class="flex max-h-96 flex-col gap-4 overflow-y-auto p-4">
                    <div
                        v-for="city in [...ownedCities].sort((a, b) => (a.markerType === 'capital' ? -1 : b.markerType === 'capital' ? 1 : 0))"
                        :key="city.id"
                        class="space-y-3 rounded-lg border border-border/40 bg-muted/20 p-3"
                    >
                        <span class="text-[0.7rem] font-semibold text-foreground">
                            {{ city.markerType === 'capital' ? '★ Capital' : '⬠ Outpost' }}
                        </span>

                        <!-- Tank/Infantry Ratio Slider -->
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between">
                                <label class="text-[0.65rem] font-medium text-muted-foreground">Tank Ratio</label>
                                <span class="text-[0.65rem] font-semibold text-foreground">
                                    {{ citySliders(city.id).tankRatio }}%
                                </span>
                            </div>
                            <input
                                type="range"
                                min="0"
                                max="100"
                                step="10"
                                :value="citySliders(city.id).tankRatio"
                                class="h-2 w-full cursor-pointer appearance-none rounded-lg bg-muted accent-primary"
                                @input="(e) => { citySliders(city.id).tankRatio = parseInt((e.target as HTMLInputElement).value) }"
                                @change="saveCitySliders(city.id)"
                            />
                            <div class="flex justify-between text-[0.6rem] text-muted-foreground">
                                <span>Infantry</span>
                                <span>Tanks</span>
                            </div>
                        </div>

                        <!-- Spawn Speed Slider -->
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between">
                                <label class="text-[0.65rem] font-medium text-muted-foreground">Spawn Speed</label>
                                <span class="text-[0.65rem] font-semibold" :class="citySliders(city.id).speedMultiplier <= 0 ? 'text-muted-foreground' : 'text-foreground'">
                                    {{ citySliders(city.id).speedMultiplier <= 0 ? 'Idle' : citySliders(city.id).speedMultiplier.toFixed(1) + 'x' }}
                                </span>
                            </div>
                            <input
                                type="range"
                                min="0"
                                max="3"
                                step="0.1"
                                :value="citySliders(city.id).speedMultiplier"
                                class="h-2 w-full cursor-pointer appearance-none rounded-lg bg-muted accent-primary"
                                @input="(e) => { citySliders(city.id).speedMultiplier = parseFloat((e.target as HTMLInputElement).value) }"
                                @change="saveCitySliders(city.id)"
                            />
                            <div class="flex justify-between text-[0.6rem] text-muted-foreground">
                                <span>Idle</span>
                                <span>Slower (3x)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dev HUD -->
            <template v-if="devHudEligible">
                <div
                    v-if="devHudOpen"
                    class="absolute bottom-20 left-3 z-10 max-h-[min(24rem,55vh)] w-[min(22rem,calc(100vw-1.5rem))] overflow-auto rounded-xl border border-border bg-background/95 p-3 font-mono text-[0.65rem] shadow-xl backdrop-blur-sm sm:left-4"
                    role="complementary"
                    aria-label="Developer diagnostics"
                >
                    <div class="mb-2 flex items-center justify-between gap-2 border-b border-border pb-2">
                        <span class="font-semibold text-foreground">Sim / net</span>
                        <Button type="button" size="sm" variant="ghost" class="h-7 px-2 text-xs" @click="devHudOpen = false">
                            Close
                        </Button>
                    </div>
                    <dl class="grid grid-cols-[minmax(0,7.5rem)_1fr] gap-x-2 gap-y-1.5 text-muted-foreground">
                        <dt class="text-foreground/80">worldTick</dt>
                        <dd>{{ store.worldTick }}</dd>
                        <dt class="text-foreground/80">stall polls</dt>
                        <dd>{{ simulationStallPolls }} / {{ SIMULATION_STALL_POLL_THRESHOLD }}</dd>
                        <dt class="text-foreground/80">snapshot poll</dt>
                        <dd>{{ MATCH_SNAPSHOT_POLL_MS }} ms</dd>
                        <dt class="text-foreground/80">Echo</dt>
                        <dd>{{ store.connected ? 'connected' : 'offline' }}</dd>
                        <dt class="text-foreground/80">initialized</dt>
                        <dd>{{ store.initialized }}</dd>
                        <dt class="text-foreground/80">matchEnded</dt>
                        <dd>{{ store.matchEnded }}</dd>
                        <dt class="text-foreground/80">game</dt>
                        <dd class="break-all">{{ game.uuid }}</dd>
                        <dt class="text-foreground/80">broadcast</dt>
                        <dd class="break-all">{{ broadcastConnection ?? '—' }}</dd>
                        <dt class="text-foreground/80">snapshot</dt>
                        <dd class="break-all">{{ snapshotPath }}</dd>
                        <dt class="text-foreground/80">snap Δtick</dt>
                        <dd>{{ store.devDiagnostics.lastWorldTickDeltaViaSnapshot ?? '—' }}</dd>
                        <dt class="text-foreground/80">snap RTT</dt>
                        <dd>
                            {{
                                store.devDiagnostics.lastSnapshotDurationMs !== null
                                    ? `${store.devDiagnostics.lastSnapshotDurationMs} ms`
                                    : '—'
                            }}
                        </dd>
                        <dt class="text-foreground/80">snap HTTP</dt>
                        <dd>{{ store.devDiagnostics.lastSnapshotHttpStatus ?? '—' }}</dd>
                        <dt class="text-foreground/80">snap @</dt>
                        <dd>{{ formatTickTime(store.devDiagnostics.lastSnapshotAt) }}</dd>
                        <dt class="text-foreground/80">snap err</dt>
                        <dd class="break-words text-destructive">
                            {{ store.devDiagnostics.lastSnapshotError ?? '—' }}
                        </dd>
                        <dt class="text-foreground/80">echo Δtick</dt>
                        <dd>{{ store.devDiagnostics.lastEchoWorldTickDelta ?? '—' }}</dd>
                        <dt class="text-foreground/80">echo @</dt>
                        <dd>{{ formatTickTime(store.devDiagnostics.lastEchoPushAt) }}</dd>
                        <dt class="text-foreground/80">Vite</dt>
                        <dd>{{ viteMode }}</dd>
                        <dt class="text-foreground/80">appDebug</dt>
                        <dd>{{ page.props.appDebug === true }}</dd>
                    </dl>
                    <p class="mt-2 border-t border-border pt-2 text-[0.6rem] leading-snug text-muted-foreground">
                        Open with <code class="rounded bg-muted px-1 text-foreground">?dev=1</code> or local Vite / APP_DEBUG.
                    </p>
                </div>
            </template>
        </div>

        <!-- Thin controls hint bar -->
        <footer class="wod-bar-bottom shrink-0 px-3 py-1.5 text-center text-[0.6rem] text-muted-foreground sm:px-4 sm:text-left">
            <template v-if="!spectatorMode">
                <span class="hidden sm:inline">
                    Drag to plan paths · Right-click to pan · Scroll to zoom · Space to execute · C to clear
                </span>
                <span class="sm:hidden">Tap-drag to plan · Two-finger pan · Pinch zoom</span>
            </template>
        </footer>
    </div>
</template>
