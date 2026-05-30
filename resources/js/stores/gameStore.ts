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
        world: { width: 1280, height: 700, cellSize: 20 },
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
        connect(gameUuid: string, userId: number, slot: number, color: string) {
            this.disconnect();
            this.gameUuid = gameUuid;
            this.slot = slot;
            this.color = color;
            this.echo = createGameEcho();

            this.echo
                .private(`game.${gameUuid}.${userId}`)
                .listen('.GameInitialized', (payload: Record<string, unknown>) => {
                    this.terrain = payload.terrain as number[][];
                    this.forest = payload.forest as number[][];
                    this.cityPositions = payload.cityPositions as Point[];
                    this.world = payload.world as typeof this.world;
                    this.initialized = true;
                })
                .listen('.GameStateUpdated', (payload: Record<string, unknown>) => {
                    this.latestState = payload.state as GameState;
                })
                .listen('.GameEnded', (payload: Record<string, unknown>) => {
                    this.winnerUserId = payload.winnerUserId as number | null;
                });

            this.connected = true;
        },
        disconnect() {
            this.echo?.disconnect();
            this.echo = null;
            this.connected = false;
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
