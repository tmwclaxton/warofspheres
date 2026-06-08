/** Max procedural generations per browser profile (logged-in user id or guest). */
export const MAP_PROCEDURAL_GENERATION_LIMIT = 5;

const STORAGE_KEY = 'wod_procedural_map_generation_count';

type CountStore = Record<string, number>;

function storageKeyForUser(userId: number | undefined | null): string {
    if (userId != null && Number.isFinite(userId)) {
        return `u:${userId}`;
    }

    return 'guest';
}

function readStore(): CountStore {
    if (typeof localStorage === 'undefined') {
        return {};
    }

    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return {};
        }

        const parsed = JSON.parse(raw) as unknown;

        if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
            return {};
        }

        return parsed as CountStore;
    } catch {
        return {};
    }
}

function writeStore(store: CountStore): void {
    if (typeof localStorage === 'undefined') {
        return;
    }

    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(store));
    } catch {
        // Quota or private mode — limit cannot be enforced persistently.
    }
}

export function readProceduralMapGenerationCount(userId: number | undefined | null): number {
    const store = readStore();
    const key = storageKeyForUser(userId);
    const n = store[key];

    return typeof n === 'number' && Number.isFinite(n) && n >= 0 ? Math.floor(n) : 0;
}

/** Increments count for the profile and returns the new total. */
export function incrementProceduralMapGenerationCount(userId: number | undefined | null): number {
    const store = { ...readStore() };
    const key = storageKeyForUser(userId);
    const prev = typeof store[key] === 'number' && store[key]! >= 0 ? store[key]! : 0;
    const next = prev + 1;
    store[key] = next;
    writeStore(store);

    return next;
}
