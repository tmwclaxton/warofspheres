import { defineStore } from 'pinia';
import { useCameraStore } from '@/stores/cameraStore';

type Point = [number, number];

export type DraftPath = {
    entityId: number;
    points: Point[];
    kind: 'troop' | 'city';
};

export const useDraftStore = defineStore('draft', {
    state: () => ({
        draftPaths: [] as DraftPath[],
        activeDraft: null as DraftPath | null,
        /** IDs of troops currently selected via lasso. Empty = no lasso selection. */
        selectedTroopIds: [] as number[],
    }),
    actions: {
        reset() {
            this.draftPaths = [];
            this.activeDraft = null;
            this.selectedTroopIds = [];
        },
        setSelection(ids: number[]) {
            this.selectedTroopIds = ids;
        },
        clearSelection() {
            this.selectedTroopIds = [];
        },
        beginPath(entityId: number, kind: 'troop' | 'city', start: Point) {
            if (
                this.activeDraft !== null &&
                (this.activeDraft.entityId !== entityId || this.activeDraft.kind !== kind)
            ) {
                this.finishPath();
            }

            this.activeDraft = { entityId, kind, points: [start] };
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

            const camera = useCameraStore();
            /** ~5 CSS px in world units; a fixed world threshold is sub-pixel when zoomed out. */
            const minSeg = Math.max(2, 5 / camera.zoom);

            if (Math.hypot(dx, dy) > minSeg) {
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
            this.selectedTroopIds = [];
        },
    },
});
