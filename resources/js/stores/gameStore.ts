import { router } from '@inertiajs/vue3';
import { defineStore } from 'pinia';
import { createGameEcho } from '@/lib/echo';
import { orders, pause as pauseRoute } from '@/routes/games';

type Point = [number, number];

type TroopState = {
    position: Point;
    color: number[];
    id: number;
    ownerSlot: number;
    path: Point[];
    health: number;
};

type CityState = {
    ownerColor: number[] | null;
    position: Point;
    id: number;
    path: Point[];
    ownerSlot: number | null;
};

type GameState = {
    vision: number[][];
    border: number[][];
    troops: TroopState[];
    cities: CityState[];
};

type DraftPath = {
    entityId: number;
    points: Point[];
    kind: 'troop' | 'city';
};

function initialWorld() {
    return { width: 1280, height: 700, cellSize: 20 };
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
        cityPositions: [] as Point[],
        world: initialWorld(),
        latestState: null as GameState | null,
        draftPaths: [] as DraftPath[],
        activeDraft: null as DraftPath | null,
        camX: 0,
        camY: 0,
        zoom: 1,
        paused: false,
        winnerUserId: null as number | null,
        echo: null as ReturnType<typeof createGameEcho> | null,
    }),
    actions: {
        reset() {
            this.connected = false;
            this.initialized = false;
            this.gameUuid = '';
            this.slot = 0;
            this.color = '#c0392b';
            this.terrain = null;
            this.forest = null;
            this.cityPositions = [];
            this.world = initialWorld();
            this.latestState = null;
            this.draftPaths = [];
            this.activeDraft = null;
            this.camX = 0;
            this.camY = 0;
            this.zoom = 1;
            this.paused = false;
            this.winnerUserId = null;
        },
        connect(gameUuid: string, userId: number, slot: number, color: string) {
            this.disconnect();
            this.gameUuid = gameUuid;
            this.slot = slot;
            this.color = color;
            this.echo = createGameEcho();

            this.echo
                .private(`game.${gameUuid}.${userId}`)
                .subscribed(() => {
                    this.connected = true;
                })
                .listen('.GameInitialized', (payload: Record<string, unknown>) => {
                    this.applySnapshotPayload(payload);
                })
                .listen('.GameStateUpdated', (payload: Record<string, unknown>) => {
                    this.latestState = payload.state as GameState;
                })
                .listen('.GameEnded', (payload: Record<string, unknown>) => {
                    this.winnerUserId = payload.winnerUserId as number | null;
                });
        },
        applySnapshotPayload(payload: Record<string, unknown>) {
            this.terrain = payload.terrain as number[][];
            this.forest = payload.forest as number[][];
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

            this.initialized = true;
        },
        async fetchSnapshotIfNeeded(url: string) {
            if (this.initialized) {
                return;
            }

            try {
                const raw = document.cookie
                    .split('; ')
                    .find((row) => row.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1];
                const res = await fetch(url, {
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

                if (!res.ok) {
                    return;
                }

                const data = (await res.json()) as Record<string, unknown>;
                this.applySnapshotPayload(data);
            } catch {
                // Echo may still deliver GameInitialized
            }
        },
        disconnect() {
            this.echo?.disconnect();
            this.echo = null;
            this.reset();
        },
        beginPath(entityId: number, kind: 'troop' | 'city', start: Point) {
            this.activeDraft = {
                entityId,
                kind,
                points: [start],
            };
        },
        extendPath(point: Point) {
            if (!this.activeDraft) {
                return;
            }

            const last = this.activeDraft.points.at(-1);

            if (!last) {
                return;
            }

            const dx = point[0] - last[0];
            const dy = point[1] - last[1];

            if (Math.hypot(dx, dy) > 8) {
                this.activeDraft.points.push(point);
            }
        },
        finishPath() {
            if (!this.activeDraft) {
                return;
            }

            if (this.activeDraft.points.length > 1) {
                this.draftPaths = this.draftPaths.filter(
                    (p) =>
                        !(
                            p.entityId === this.activeDraft!.entityId &&
                            p.kind === this.activeDraft!.kind
                        ),
                );
                this.draftPaths.push({ ...this.activeDraft });
            }

            this.activeDraft = null;
        },
        clearDrafts() {
            this.draftPaths = [];
            this.activeDraft = null;
        },
        submitOrders(gameUuid: string) {
            const troopOrders = this.draftPaths
                .filter((p) => p.kind === 'troop')
                .map((p) => [p.entityId, p.points] as [number, Point[]]);
            const cityOrders = this.draftPaths
                .filter((p) => p.kind === 'city')
                .map((p) => [p.entityId, p.points] as [number, Point[]]);

            router.post(
                orders(gameUuid).url,
                {
                    troop_orders: troopOrders,
                    city_orders: cityOrders,
                },
                { preserveScroll: true },
            );

            this.clearDrafts();
        },
        togglePause(gameUuid: string) {
            this.paused = !this.paused;
            router.post(
                pauseRoute(gameUuid).url,
                { paused: this.paused },
                { preserveScroll: true },
            );
        },
    },
});
