import { generateRandomMap } from '../lib/generateRandomMap';
import type { MapGenerationOptions } from '../lib/generateRandomMap';

export type GenerateMapWorkerInbound = {
    id: number;
    options: MapGenerationOptions;
};

type GenerateMapWorkerOutbound =
    | { id: number; ok: true; data: ReturnType<typeof generateRandomMap> }
    | { id: number; ok: false; error: string };

self.onmessage = (event: MessageEvent<GenerateMapWorkerInbound>): void => {
    const { id, options } = event.data;

    try {
        const data = generateRandomMap(options);
        const message: GenerateMapWorkerOutbound = { id, ok: true, data };
        self.postMessage(message);
    } catch (err) {
        const error = err instanceof Error ? err.message : String(err);
        const message: GenerateMapWorkerOutbound = { id, ok: false, error };
        self.postMessage(message);
    }
};
