import { generateRandomMap } from '@/lib/generateRandomMap';
import type { GeneratedMapData, MapGenerationOptions } from '@/lib/generateRandomMap';

import workerEntryUrl from '../workers/generateMap.worker.ts?worker&url';

type GenerateMapWorkerInbound = {
    id: number;
    options: MapGenerationOptions;
};

type WorkerSuccess = { id: number; ok: true; data: GeneratedMapData };
type WorkerFailure = { id: number; ok: false; error: string };
type WorkerResponse = WorkerSuccess | WorkerFailure;

let worker: Worker | null = null;
/** Revoked when the worker instance is torn down. */
let workerBootstrapBlobUrl: string | null = null;
let nextId = 0;
const pending = new Map<number, { resolve: (data: GeneratedMapData) => void; reject: (err: Error) => void }>();

function rejectAllPending(message: string): void {
    const err = new Error(message);

    for (const [, entry] of pending) {
        entry.reject(err);
    }

    pending.clear();
}

function disposeWorker(): void {
    if (worker !== null) {
        worker.terminate();
        worker = null;
    }

    if (workerBootstrapBlobUrl !== null) {
        URL.revokeObjectURL(workerBootstrapBlobUrl);
        workerBootstrapBlobUrl = null;
    }
}

function attachWorkerHandlers(w: Worker): void {
    w.onmessage = (event: MessageEvent<WorkerResponse>): void => {
        const msg = event.data;
        const entry = pending.get(msg.id);

        if (!entry) {
            return;
        }

        pending.delete(msg.id);

        if (msg.ok) {
            entry.resolve(msg.data);
        } else {
            entry.reject(new Error(msg.error));
        }
    };

    w.onmessageerror = (): void => {
        rejectAllPending('Map generation worker message error.');
        disposeWorker();
    };

    w.onerror = (event): void => {
        rejectAllPending(event.message || 'Map generation worker failed.');
        disposeWorker();
    };
}

/**
 * Classic `new Worker(viteUrl)` fails under Laravel + Vite dev because the document is served
 * from one origin (e.g. http://localhost) and the worker script from another (e.g. http://127.0.0.1:5175).
 * Bootstrapping with a blob URL makes the worker's initial script same-origin; the real module is
 * loaded via `import()` from the URL Vite provides (`?worker&url`). See vitejs/vite#13680.
 */
function getWorker(): Worker | null {
    if (typeof Worker === 'undefined' || typeof window === 'undefined' || typeof URL.createObjectURL === 'undefined') {
        return null;
    }

    if (worker) {
        return worker;
    }

    try {
        const moduleHref = new URL(workerEntryUrl, import.meta.url).href;
        const bootstrapSource = `import ${JSON.stringify(moduleHref)};\n`;
        workerBootstrapBlobUrl = URL.createObjectURL(
            new Blob([bootstrapSource], { type: 'application/javascript' }),
        );
        worker = new Worker(workerBootstrapBlobUrl, { type: 'module' });
        attachWorkerHandlers(worker);
    } catch {
        disposeWorker();

        return null;
    }

    return worker;
}

/**
 * Runs procedural map generation in a dedicated worker when possible so the UI can show a spinner.
 * Falls back to synchronous generation on the main thread when workers are unavailable.
 */
export function runProceduralMapGeneration(options: MapGenerationOptions): Promise<GeneratedMapData> {
    const w = getWorker();

    if (!w) {
        return Promise.resolve(generateRandomMap(options));
    }

    return new Promise((resolve, reject) => {
        const id = ++nextId;
        pending.set(id, { resolve, reject });

        const payload: GenerateMapWorkerInbound = { id, options };

        try {
            w.postMessage(payload);
        } catch (err) {
            pending.delete(id);
            const message = err instanceof Error ? err.message : String(err);
            reject(new Error(message));
        }
    });
}
