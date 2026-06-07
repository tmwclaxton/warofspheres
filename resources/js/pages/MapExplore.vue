<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, ThumbsDown, ThumbsUp, Users } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import MapExplorePreview from '@/components/map-explore/MapExplorePreview.vue';
import { Button } from '@/components/ui/button';
import type { MapDataPayload } from '@/lib/mapEditorGrid';
import { login, mapBuilder } from '@/routes';
import { store as createGame } from '@/routes/games';
import { fork, vote } from '@/routes/maps';
import { useToastStore } from '@/stores/toastStore';

export type ExploreMapCard = {
    uuid: string;
    name: string;
    ownerName: string;
    ownerId: number;
    data: MapDataPayload;
    gamesCount: number;
    likesCount: number;
    dislikesCount: number;
    forksCount: number;
    publishedAt: string | null;
    forkAttribution: null | {
        parentName: string;
        parentAuthorName: string;
        parentUuid: string;
    };
    viewerVote: 'like' | 'dislike' | null;
};

const props = defineProps<{
    maps: ExploreMapCard[];
}>();

const page = usePage();
const toast = useToastStore();
const auth = computed(() => page.props.auth);

const cards = ref([...props.maps]);

watch(
    () => props.maps,
    (m) => {
        cards.value = m.map((row) => ({ ...row }));
    },
    { deep: true },
);

const lobbyForm = useForm({
    max_players: 4,
    map_uuid: '',
});

function getCookie(name: string): string {
    const match = document.cookie.match(new RegExp(`(^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[2] ?? '') : '';
}

function csrfToken(): string {
    return (
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ??
        getCookie('XSRF-TOKEN')
    );
}

async function jsonPost(url: string, body: Record<string, unknown>): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': decodeURIComponent(getCookie('XSRF-TOKEN')),
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body),
    });
}

function mergeCard(uuid: string, next: ExploreMapCard): void {
    const i = cards.value.findIndex((c) => c.uuid === uuid);

    if (i !== -1) {
        cards.value[i] = next;
    }
}

async function submitVote(mapUuid: string, choice: 'like' | 'dislike' | 'clear'): Promise<void> {
    const res = await jsonPost(vote.url(mapUuid), { vote: choice });

    if (!res.ok) {
        const t = await res.text();
        toast.error(t.slice(0, 400));

        return;
    }

    const body = (await res.json()) as { map: ExploreMapCard };
    mergeCard(mapUuid, body.map);
}

function toggleLike(m: ExploreMapCard): void {
    if (!auth.value.user) {
        toast.info('Sign in to rate maps.');

        return;
    }

    const next = m.viewerVote === 'like' ? 'clear' : 'like';
    void submitVote(m.uuid, next);
}

function toggleDislike(m: ExploreMapCard): void {
    if (!auth.value.user) {
        toast.info('Sign in to rate maps.');

        return;
    }

    const next = m.viewerVote === 'dislike' ? 'clear' : 'dislike';
    void submitVote(m.uuid, next);
}

function startLobby(m: ExploreMapCard): void {
    if (!auth.value.user) {
        toast.info('Sign in to start a lobby from this map.');

        return;
    }

    const tc = typeof m.data.teamCount === 'number' && Number.isFinite(m.data.teamCount) ? m.data.teamCount : 2;
    lobbyForm.max_players = Math.min(6, Math.max(2, tc));
    lobbyForm.map_uuid = m.uuid;
    lobbyForm.post(createGame().url);
}

function copyToBuilder(m: ExploreMapCard): void {
    if (!auth.value.user) {
        toast.info('Sign in to copy a map into your builder.');

        return;
    }

    void (async () => {
        const res = await jsonPost(fork.url(m.uuid), {});

        if (!res.ok) {
            const t = await res.text();
            toast.error(t.slice(0, 400));

            return;
        }

        const body = (await res.json()) as { map: { uuid: string } };
        toast.success('Map copied to your library.');
        router.visit(mapBuilder.url(body.map.uuid));
    })();
}
</script>

<template>
    <Head title="Explore maps" />

    <div class="flex flex-col gap-8">
        <div>
            <h1 class="font-display text-2xl font-bold tracking-tight md:text-3xl">
                Explore maps
            </h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                Published designs from the community. Copy one to edit your own version, or start a
                lobby (the match still uses procedural terrain for now — your chosen map is linked for
                attribution and future play modes).
            </p>
        </div>

        <div
            v-if="cards.length === 0"
            class="wod-panel-dashed p-10 text-center text-muted-foreground"
        >
            No published maps yet. Publish yours from the map builder when it is ready.
        </div>

        <div v-else class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="m in cards"
                :key="m.uuid"
                class="flex flex-col gap-3 wod-panel p-4"
            >
                <MapExplorePreview :data="m.data" />

                <div>
                    <h2 class="font-bold leading-tight">
                        {{ m.name }}
                    </h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        By {{ m.ownerName }}
                        <span v-if="m.publishedAt" class="text-muted-foreground/80">
                            · published {{ new Date(m.publishedAt).toLocaleDateString() }}
                        </span>
                    </p>
                    <p
                        v-if="m.forkAttribution"
                        class="mt-2 rounded border border-dashed border-foreground/20 bg-muted/40 px-2 py-1.5 text-xs text-muted-foreground"
                    >
                        Fork of
                        <span class="font-medium text-foreground">{{ m.forkAttribution.parentName }}</span>
                        by {{ m.forkAttribution.parentAuthorName }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2 text-xs text-muted-foreground">
                    <span>{{ m.gamesCount }} games</span>
                    <span>·</span>
                    <span>{{ m.forksCount }} forks</span>
                    <span>·</span>
                    <span>{{ m.likesCount }} likes / {{ m.dislikesCount }} dislikes</span>
                </div>

                <div class="mt-auto flex flex-wrap gap-2 border-t border-foreground/10 pt-3">
                    <Button type="button" size="sm" variant="outline" class="gap-1" @click="copyToBuilder(m)">
                        <Copy class="size-3.5" />
                        Copy to my maps
                    </Button>
                    <Button type="button" size="sm" class="gap-1" @click="startLobby(m)">
                        <Users class="size-3.5" />
                        Start lobby
                    </Button>
                    <div class="flex flex-1 items-center justify-end gap-1">
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="size-8"
                            :class="m.viewerVote === 'like' ? 'text-green-700 dark:text-green-400' : ''"
                            :title="auth.user ? 'Like' : 'Sign in to like'"
                            @click="toggleLike(m)"
                        >
                            <ThumbsUp class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="size-8"
                            :class="m.viewerVote === 'dislike' ? 'text-red-700 dark:text-red-400' : ''"
                            :title="auth.user ? 'Dislike' : 'Sign in to dislike'"
                            @click="toggleDislike(m)"
                        >
                            <ThumbsDown class="size-4" />
                        </Button>
                    </div>
                </div>
            </article>
        </div>

        <p v-if="!auth.user" class="text-center text-sm text-muted-foreground">
            <Link :href="login().url" class="font-medium text-foreground underline underline-offset-2">
                Sign in
            </Link>
            to copy maps, vote, or start a lobby.
        </p>

        <div class="flex justify-center">
            <Button variant="outline" as-child>
                <Link :href="mapBuilder().url">Open map builder</Link>
            </Button>
        </div>
    </div>
</template>
