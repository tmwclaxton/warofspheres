const STYLE = 'pixel-art';
const BASE = `https://api.dicebear.com/10.x/${STYLE}/svg`;

/**
 * Returns a deterministic DiceBear pixel-art avatar URL for a given seed.
 * The seed should be a stable unique identifier such as a profile_uuid.
 */
export function avatarUrl(seed: string): string {
    return `${BASE}?seed=${encodeURIComponent(seed)}`;
}
