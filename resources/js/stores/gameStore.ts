import { defineStore } from 'pinia';
import { createGameEcho } from '@/lib/echo';
import {
    chat as chatRoute,
    cityProduction as cityProductionRoute,
    orders,
    recruit as recruitRoute,
    recruitTank as recruitTankRoute,
} from '@/routes/games';
import { useCameraStore } from '@/stores/cameraStore';
import { useDraftStore } from '@/stores/draftStore';
import { useToastStore } from '@/stores/toastStore';

function csrfHeaders(): Record<string, string> {
    const raw = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(raw ? { 'X-XSRF-TOKEN': decodeURIComponent(raw) } : {}),
    };
}

type Point = [number, number];

type EconomySlot = {
    credits: number;
    incomePerTick: number;
};

type TroopState = {
    position: Point;
    color: number[];
    id: number;
    ownerSlot: number;
    path: Point[];
    health: number;
    morale?: number;
    type?: 'infantry' | 'tank';
    maxHealth?: number;
    isShip?: boolean;
    warmupMultiplier?: number;
    combatMultiplier?: number;
};

type CityState = {
    ownerColor: number[] | null;
    position: Point;
    id: number;
    path: Point[];
    ownerSlot: number | null;
    markerType?: string | null;
    productionType?: 'infantry' | 'tank' | 'none';
    productionTankRatio?: number;
    productionSpeedMultiplier?: number;
};

type ChatMessage = {
    id: number;
    body: string;
    senderName: string;
    senderSlot: number;
    createdAt: string;
};

type GameState = {
    vision: number[][];
    /** Compact territory ownership grid: territory[gx][gy] = player slot (−1 = neutral). */
    territory: number[][];
    /** Player colors indexed by slot: playerColors[slot] = [r, g, b]. */
    playerColors: Record<number, number[]>;
    troops: TroopState[];
    cities: CityState[];
};

export type GameDevDiagnostics = {
    lastSnapshotAt: number | null;
    lastSnapshotDurationMs: number | null;
    lastSnapshotHttpStatus: number | null;
    lastWorldTickDeltaViaSnapshot: number | null;
    lastSnapshotError: string | null;
    lastEchoPushAt: number | null;
    lastEchoWorldTickDelta: number | null;
};

function emptyDevDiagnostics(): GameDevDiagnostics {
    return {
        lastSnapshotAt: null,
        lastSnapshotDurationMs: null,
        lastSnapshotHttpStatus: null,
        lastWorldTickDeltaViaSnapshot: null,
        lastSnapshotError: null,
        lastEchoPushAt: null,
        lastEchoWorldTickDelta: null,
    };
}

function initialWorld() {
    return { width: 1280, height: 700, cellSize: 20 };
}

function coerceTerrainCellsFromSnapshot(
    raw: unknown,
    terrain: number[][] | null,
): string[][] | null {
    if (!terrain || terrain.length === 0 || !terrain[0]?.length) {
        return null;
    }

    if (!Array.isArray(raw) || raw.length !== terrain.length) {
        return null;
    }

    const colCount = terrain[0].length;
    const rows: string[][] = [];

    for (let gx = 0; gx < raw.length; gx++) {
        const row = raw[gx];

        if (!Array.isArray(row) || row.length !== colCount) {
            return null;
        }

        rows.push(row.map((c) => (typeof c === 'string' ? c : 'plains')));
    }

    return rows;
}

function parseEconomy(raw: unknown): EconomySlot[] | null {
    if (!Array.isArray(raw) || raw.length === 0) {
        return null;
    }

    const out: EconomySlot[] = [];

    for (const row of raw) {
        if (!row || typeof row !== 'object') {
            continue;
        }

        const o = row as Record<string, unknown>;
        out.push({
            credits: Number(o.credits ?? 0),
            incomePerTick: Number(o.incomePerTick ?? 0),
        });
    }

    return out.length > 0 ? out : null;
}

export const useGameStore = defineStore('game', {
    state: () => ({
        connected: false,
        initialized: false,
        gameUuid: '' as string,
        slot: 0,
        color: '#c0392b',
        terrain: null as number[][] | null,
        forest: null as number[][] | null,
        terrainCells: null as string[][] | null,
        cityPositions: [] as Point[],
        world: initialWorld(),
        latestState: null as GameState | null,
        economy: null as EconomySlot[] | null,
        worldTick: 0,
        winnerUserId: null as number | null,
        winnerSlot: null as number | null,
        winnerName: null as string | null,
        matchEnded: false,
        chatMessages: [] as ChatMessage[],
        unreadChatCount: 0,
        echo: null as ReturnType<typeof createGameEcho> | null,
        devDiagnostics: emptyDevDiagnostics(),
    }),
    actions: {
        reset() {
            this.connected = false;
            this.initialized = false;
            this.gameUuid = '';
            this.slot = 0;
            this.color = '#c0392b';
            this.chatMessages = [];
            this.unreadChatCount = 0;
            this.terrain = null;
            this.forest = null;
            this.terrainCells = null;
            this.cityPositions = [];
            this.world = initialWorld();
            this.latestState = null;
            this.economy = null;
            this.worldTick = 0;
            this.winnerUserId = null;
            this.winnerSlot = null;
            this.winnerName = null;
            this.matchEnded = false;
            this.devDiagnostics = emptyDevDiagnostics();

            useCameraStore().reset();
            useDraftStore().reset();
        },
        connect(gameUuid: string, broadcastConnection: string, slot: number, color: string) {
            this.disconnect();
            this.gameUuid = gameUuid;
            this.slot = slot;
            this.color = color;
            this.echo = createGameEcho();

            this.echo
                .private(`game.${gameUuid}.${broadcastConnection}`)
                .subscribed(() => {
                    this.connected = true;
                })
                .listen('.GameInitialized', (payload: Record<string, unknown>) => {
                    this.applySnapshotPayload(payload);
                })
                .listen('.GameStateUpdated', (payload: Record<string, unknown>) => {
                    const prevTick = this.worldTick;
                    this.latestState = payload.state as GameState;
                    const eco = parseEconomy(payload.economy);

                    if (eco) {
                        this.economy = eco;
                    }

                    if (payload.worldTick !== undefined && payload.worldTick !== null) {
                        this.worldTick = Number(payload.worldTick);
                        this.devDiagnostics.lastEchoPushAt = Date.now();
                        this.devDiagnostics.lastEchoWorldTickDelta = this.worldTick - prevTick;
                    }
                })
                .listen('.GameEnded', (payload: Record<string, unknown>) => {
                    this.matchEnded = true;
                    this.winnerUserId =
                        payload.winnerUserId === undefined || payload.winnerUserId === null
                            ? null
                            : Number(payload.winnerUserId);
                    this.winnerSlot =
                        payload.winnerSlot === undefined || payload.winnerSlot === null
                            ? null
                            : Number(payload.winnerSlot);
                    this.winnerName =
                        typeof payload.winnerName === 'string' ? payload.winnerName : null;
                })
                .listen('.ChatMessageSent', (payload: Record<string, unknown>) => {
                    this.chatMessages.push(payload as unknown as ChatMessage);
                    this.unreadChatCount++;
                });
        },
        applySnapshotPayload(payload: Record<string, unknown>) {
            this.terrain = payload.terrain as number[][];
            this.forest = payload.forest as number[][];
            this.terrainCells = coerceTerrainCellsFromSnapshot(payload.terrainCells, this.terrain);
            this.cityPositions = payload.cityPositions as Point[];

            if (payload.world && typeof payload.world === 'object') {
                this.world = payload.world as typeof this.world;
            }

            if (payload.slot !== undefined && payload.slot !== null) {
                this.slot = Number(payload.slot);
            }

            if (typeof payload.color === 'string') {
                this.color = payload.color;
            }

            if (payload.state && typeof payload.state === 'object') {
                this.latestState = payload.state as GameState;
            }

            const eco = parseEconomy(payload.economy);

            if (eco) {
                this.economy = eco;
            }

            if (payload.worldTick !== undefined && payload.worldTick !== null) {
                this.worldTick = Number(payload.worldTick);
            }

            this.initialized = true;
        },
        async pullSnapshot(url: string, options?: { treat404AsEnded?: boolean }) {
            const prevTick = this.worldTick;
            const t0 = typeof performance !== 'undefined' ? performance.now() : 0;

            try {
                const raw = document.cookie
                    .split('; ')
                    .find((row) => row.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1];
                const res = await fetch(url, {
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(raw
                            ? {
                                  'X-XSRF-TOKEN': decodeURIComponent(raw),
                              }
                            : {}),
                    },
                });

                const elapsedMs =
                    typeof performance !== 'undefined' ? Math.round(performance.now() - t0) : null;

                if (!res.ok) {
                    if (options?.treat404AsEnded && res.status === 404) {
                        this.matchEnded = true;
                    }

                    this.devDiagnostics.lastSnapshotAt = Date.now();
                    this.devDiagnostics.lastSnapshotDurationMs = elapsedMs;
                    this.devDiagnostics.lastSnapshotHttpStatus = res.status;
                    this.devDiagnostics.lastWorldTickDeltaViaSnapshot = null;
                    this.devDiagnostics.lastSnapshotError = `HTTP ${res.status}`;

                    return;
                }

                const data = (await res.json()) as Record<string, unknown>;
                this.applySnapshotPayload(data);

                this.devDiagnostics.lastSnapshotAt = Date.now();
                this.devDiagnostics.lastSnapshotDurationMs = elapsedMs;
                this.devDiagnostics.lastSnapshotHttpStatus = res.status;
                this.devDiagnostics.lastWorldTickDeltaViaSnapshot = this.worldTick - prevTick;
                this.devDiagnostics.lastSnapshotError = null;
            } catch (e) {
                this.devDiagnostics.lastSnapshotAt = Date.now();
                this.devDiagnostics.lastSnapshotError =
                    e instanceof Error ? e.message : 'Snapshot fetch failed';
            }
        },
        disconnect() {
            this.echo?.disconnect();
            this.echo = null;
            this.reset();
        },
        /** Sends empty-path orders for every own troop, stopping them in place. */
        async stopAllTroops(gameUuid: string, snapshotFetchUrl?: string) {
            const toast = useToastStore();
            const troopOrders = (this.latestState?.troops ?? [])
                .filter((t) => t.ownerSlot === this.slot)
                .map((t) => [t.id, []] as [number, []]);

            if (troopOrders.length === 0) {
                return;
            }

            try {
                const res = await fetch(orders(gameUuid).url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: csrfHeaders(),
                    body: JSON.stringify({ troop_orders: troopOrders, city_orders: [] }),
                });

                if (!res.ok) {
                    toast.error('Could not send stop orders.');
                    return;
                }

                if (snapshotFetchUrl !== undefined && snapshotFetchUrl.length > 0) {
                    await this.pullSnapshot(snapshotFetchUrl);
                }
            } catch {
                toast.error('Network error — stop failed.');
            }
        },

        async submitOrders(gameUuid: string, options?: { snapshotFetchUrl?: string }) {
            const drafts = useDraftStore();
            const toast = useToastStore();

            const troopOrders = drafts.draftPaths
                .filter((p) => p.kind === 'troop')
                .map((p) => [p.entityId, p.points] as [number, Point[]]);
            const cityOrders = drafts.draftPaths
                .filter((p) => p.kind === 'city')
                .map((p) => [p.entityId, p.points] as [number, Point[]]);

            try {
                const res = await fetch(orders(gameUuid).url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: csrfHeaders(),
                    body: JSON.stringify({ troop_orders: troopOrders, city_orders: cityOrders }),
                });

                if (!res.ok) {
                    const data = (await res.json().catch(() => ({}))) as Record<string, unknown>;
                    const errors = data.errors as Record<string, string> | undefined;
                    const message =
                        errors?.troop_orders ??
                        errors?.city_orders ??
                        (typeof data.message === 'string' ? data.message : null) ??
                        'Orders could not be submitted.';
                    toast.error(message);
                    return;
                }

                drafts.clearDrafts();

                const snapshotUrl = options?.snapshotFetchUrl;
                if (snapshotUrl !== undefined && snapshotUrl.length > 0) {
                    await this.pullSnapshot(snapshotUrl);
                }
            } catch {
                toast.error('Network error — orders not submitted.');
            }
        },
        async recruitInfantry(gameUuid: string, options?: { snapshotFetchUrl?: string }) {
            await this._recruit(recruitRoute({ game: gameUuid }).url, 'Infantry recruited.', options);
        },

        async recruitTank(gameUuid: string, options?: { snapshotFetchUrl?: string }) {
            await this._recruit(recruitTankRoute({ game: gameUuid }).url, 'Tank deployed.', options);
        },

        clearUnreadChat() {
            this.unreadChatCount = 0;
        },

        async sendChatMessage(gameUuid: string, body: string) {
            const toast = useToastStore();

            if (!body.trim()) {
                return;
            }

            try {
                const res = await fetch(chatRoute({ game: gameUuid }).url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { ...csrfHeaders(), 'Content-Type': 'application/json' },
                    body: JSON.stringify({ body }),
                });

                if (!res.ok) {
                    toast.error('Could not send message.');
                }
            } catch {
                toast.error('Network error — chat failed.');
            }
        },

        async setCityProduction(
            gameUuid: string,
            cityId: number,
            productionType: 'infantry' | 'tank' | 'none',
            tankRatio?: number,
            speedMultiplier?: number,
            options?: { snapshotFetchUrl?: string },
        ) {
            const toast = useToastStore();

            try {
                const body: Record<string, unknown> = {
                    city_id: cityId,
                };

                if (tankRatio !== undefined) {
                    body.production_tank_ratio = tankRatio;
                }

                if (speedMultiplier !== undefined) {
                    body.production_speed_multiplier = speedMultiplier;
                }

                const res = await fetch(cityProductionRoute({ game: gameUuid }).url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { ...csrfHeaders(), 'Content-Type': 'application/json' },
                    body: JSON.stringify(body),
                });

                if (!res.ok) {
                    const data = (await res.json().catch(() => ({}))) as Record<string, unknown>;
                    const message =
                        typeof data.message === 'string' ? data.message : 'Could not change production.';
                    toast.error(message);
                    return;
                }

                const snapshotUrl = options?.snapshotFetchUrl;
                if (snapshotUrl !== undefined && snapshotUrl.length > 0) {
                    await this.pullSnapshot(snapshotUrl);
                }
            } catch {
                toast.error('Network error — production change failed.');
            }
        },

        async _recruit(url: string, successMessage: string, options?: { snapshotFetchUrl?: string }) {
            const toast = useToastStore();

            try {
                const res = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: csrfHeaders(),
                    body: JSON.stringify({}),
                });

                if (!res.ok) {
                    const data = (await res.json().catch(() => ({}))) as Record<string, unknown>;
                    const message =
                        typeof data.message === 'string'
                            ? data.message
                            : 'Could not recruit — check your credits and army size.';
                    toast.error(message);
                    return;
                }

                toast.success(successMessage);

                const snapshotUrl = options?.snapshotFetchUrl;
                if (snapshotUrl !== undefined && snapshotUrl.length > 0) {
                    await this.pullSnapshot(snapshotUrl);
                }
            } catch {
                toast.error('Network error — recruit failed.');
            }
        },
    },
});
