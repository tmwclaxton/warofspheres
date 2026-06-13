import { defineStore } from 'pinia';

export const GAME_VIEW_ZOOM_MIN = 0.04;
export const GAME_VIEW_ZOOM_MAX = 10;

export const useCameraStore = defineStore('camera', {
    state: () => ({
        camX: 0,
        camY: 0,
        zoom: 1,
    }),
    actions: {
        reset() {
            this.camX = 0;
            this.camY = 0;
            this.zoom = 1;
        },
        /**
         * Fit the whole battlefield in the view. Matches GameCanvas transform:
         * screen = zoom * (world + cam).
         */
        fitCameraToView(
            worldWidth: number,
            worldHeight: number,
            cssWidth: number,
            cssHeight: number,
            margin = 0.94,
        ): void {
            if (!(worldWidth > 0 && worldHeight > 0 && cssWidth > 0 && cssHeight > 0)) {
                return;
            }

            const z = Math.min(
                (cssWidth * margin) / worldWidth,
                (cssHeight * margin) / worldHeight,
            );
            this.zoom = Math.min(GAME_VIEW_ZOOM_MAX, Math.max(GAME_VIEW_ZOOM_MIN, z));
            this.camX = cssWidth / (2 * this.zoom) - worldWidth / 2;
            this.camY = cssHeight / (2 * this.zoom) - worldHeight / 2;
        },
    },
});
