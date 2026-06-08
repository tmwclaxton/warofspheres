<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, ThumbsDown, ThumbsUp, Users } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import MapExplorePreview from '@/components/map-explore/MapExplorePreview.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { MapDataPayload } from '@/lib/mapEditorGrid';
import { login, mapBuilder } from '@/routes';
import { store as createGame } from '@/routes/games';
import { explore as mapsExplore, fork, vote } from '@/routes/maps';
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

export type ExploreFilters = {
    q: string;
    author: string;
    uuid: string;
    sort: string;
    per_page: number;
};

export type ExplorePagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_url: string | null;
    next_url: string | null;
    pages: Array<{ page: number; url: string; active: boolean }>;
};

const props = defineProps<{
    maps: ExploreMapCard[];
    pagination: ExplorePagination;
    filters: ExploreFilters;
}>();

const page = usePage();
const toast = useToastStore();
const auth = computed(() => page.props.auth);

const filterForm = reactive({
    q: props.filters.q,
    author: props.filters.author,
    uuid: props.filters.uuid,
    sort: props.filters.sort,
    per_page: props.filters.per_page,
});

watch(
    () => props.filters,
    (f) => {
        filterForm.q = f.q;
        filterForm.author = f.author;
        filterForm.uuid = f.uuid;
        filterForm.sort = f.sort;
        filterForm.per_page = f.per_page;
    },
    { deep: true },
);

const cards = ref([...props.maps]);

watch(
    () => props.maps,
    (m) => {
        cards.value = m.map((row) => ({ ...row }));
    },
    { deep: true },
);

const hasActiveFilters = computed(() => {
    return (
        filterForm.q.trim() !== ''
        || filterForm.author.trim() !== ''
        || filterForm.uuid.trim() !== ''
        || filterForm.sort !== 'newest'
        || filterForm.per_page !== 12
    );
});

const hasUuidFilter = computed(() => filterForm.uuid.trim() !== '');

const lobbyForm = useForm({
    map_uuid: '',
});

function buildExploreQuery(overrides: Partial<ExploreFilters> & { page?: number } = {}): Record<
    string,
    string | number | boolean
> {
    const q = { ...filterForm, ...overrides };
    const out: Record<string, string | number | boolean> = {};

    if (q.q.trim() !== '') {
        out.q = q.q.trim();
    }

    if (q.author.trim() !== '') {
        out.author = q.author.trim();
    }

    if (q.uuid.trim() !== '') {
        out.uuid = q.uuid.trim();
    }

    if (q.sort !== 'newest') {
        out.sort = q.sort;
    }

    if (q.per_page !== 12) {
        out.per_page = q.per_page;
    }

    const pageNum = overrides.page ?? 1;

    if (pageNum > 1) {
        out.page = pageNum;
    }

    return out;
}

function visitExplore(overrides: Partial<ExploreFilters> & { page?: number } = {}): void {
    router.visit(mapsExplore.url({ query: buildExploreQuery(overrides) }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function applyFilters(): void {
    visitExplore({ page: 1 });
}

function resetFilters(): void {
    filterForm.q = '';
    filterForm.author = '';
    filterForm.uuid = '';
    filterForm.sort = 'newest';
    filterForm.per_page = 12;
    visitExplore({ page: 1 });
}

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

const sortOptions = [
    { value: 'newest', label: 'Newest published' },
    { value: 'oldest', label: 'Oldest published' },
    { value: 'name_az', label: 'Name A–Z' },
    { value: 'name_za', label: 'Name Z–A' },
    { value: 'most_likes', label: 'Most likes' },
    { value: 'most_forks', label: 'Most forks' },
    { value: 'most_games', label: 'Most games' },
] as const;
</script>

<template>
    <Head title="Explore maps" />

    <div class="flex flex-col gap-8">
        <h1 class="font-display text-2xl font-bold tracking-tight md:text-3xl">
            Explore maps
        </h1>

        <div
            v-if="hasUuidFilter"
            class="flex flex-wrap items-center justify-between gap-3 wod-panel px-4 py-3"
        >
            <p class="text-sm text-muted-foreground">
                Showing one published map from your builder link.
            </p>
            <Button type="button" size="sm" variant="outline" @click="resetFilters">
                Show all maps
            </Button>
        </div>

        <form
            class="flex flex-col gap-4 wod-panel p-4"
            @submit.prevent="applyFilters"
        >
            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                Search &amp; sort
            </p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="flex flex-col gap-1.5">
                    <label class="text-xs font-medium text-foreground" for="explore-q">Map name</label>
                    <Input
                        id="explore-q"
                        v-model="filterForm.q"
                        type="search"
                        maxlength="120"
                        placeholder="Contains…"
                        autocomplete="off"
                        class="h-9"
                    />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-xs font-medium text-foreground" for="explore-author">Author</label>
                    <Input
                        id="explore-author"
                        v-model="filterForm.author"
                        type="search"
                        maxlength="80"
                        placeholder="Creator name…"
                        autocomplete="off"
                        class="h-9"
                    />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-xs font-medium text-foreground" for="explore-sort">Sort by</label>
                    <select
                        id="explore-sort"
                        v-model="filterForm.sort"
                        class="wod-field h-9 rounded-md border-2 border-foreground px-2 text-sm"
                    >
                        <option
                            v-for="opt in sortOptions"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label }}
                        </option>
                    </select>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-xs font-medium text-foreground" for="explore-per">Per page</label>
                    <select
                        id="explore-per"
                        v-model.number="filterForm.per_page"
                        class="wod-field h-9 rounded-md border-2 border-foreground px-2 text-sm"
                    >
                        <option :value="12">
                            12
                        </option>
                        <option :value="24">
                            24
                        </option>
                        <option :value="48">
                            48
                        </option>
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button type="submit" size="sm">
                    Apply filters
                </Button>
                <Button
                    v-if="hasActiveFilters"
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="resetFilters"
                >
                    Reset
                </Button>
            </div>
        </form>

        <p v-if="pagination.total > 0" class="text-sm text-muted-foreground">
            Showing
            <span class="font-medium text-foreground">{{ pagination.from ?? 0 }}</span>
            –
            <span class="font-medium text-foreground">{{ pagination.to ?? 0 }}</span>
            of
            <span class="font-medium text-foreground">{{ pagination.total }}</span>
            published maps
        </p>

        <div
            v-if="cards.length === 0"
            class="wod-panel-dashed p-10 text-center text-muted-foreground"
        >
            <template v-if="hasActiveFilters">
                <p>No published maps match your filters.</p>
                <button
                    type="button"
                    class="mt-3 font-medium text-foreground underline underline-offset-2"
                    @click="resetFilters"
                >
                    Clear filters
                </button>
            </template>
            <template v-else-if="pagination.total === 0">
                No published maps yet. Publish yours from the map builder when it is ready.
            </template>
            <template v-else>
                <p>No maps on this page.</p>
                <Button type="button" variant="link" class="mt-2 h-auto p-0 text-foreground" @click="visitExplore({ page: 1 })">
                    Back to first page
                </Button>
            </template>
        </div>

        <div v-else class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="m in cards"
                :key="m.uuid"
                class="flex flex-col gap-3 wod-panel p-4"
            >
                <Link
                    :href="mapBuilder.url(m.uuid)"
                    class="group block overflow-hidden rounded-md ring-offset-background outline-none transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    title="View in map builder (read-only)"
                >
                    <MapExplorePreview class="transition group-hover:opacity-95" :data="m.data" />
                </Link>

                <div>
                    <h2 class="font-bold leading-tight">
                        <Link
                            :href="mapBuilder.url(m.uuid)"
                            class="rounded-sm outline-none ring-offset-background transition hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        >
                            {{ m.name }}
                        </Link>
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
                    <Button type="button" size="sm" variant="outline" class="gap-1" as-child>
                        <Link :href="mapBuilder.url(m.uuid)" title="View in map builder (read-only)">
                            View in builder
                        </Link>
                    </Button>
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

        <nav
            v-if="pagination.last_page > 1"
            class="flex flex-col items-center gap-3 sm:flex-row sm:justify-center"
            aria-label="Pagination"
        >
            <div class="flex flex-wrap items-center justify-center gap-2">
                <Button
                    v-if="pagination.prev_url"
                    variant="outline"
                    size="sm"
                    class="min-w-[5.5rem]"
                    as-child
                >
                    <Link :href="pagination.prev_url" preserve-scroll>
                        Previous
                    </Link>
                </Button>
                <Button
                    v-else
                    variant="outline"
                    size="sm"
                    class="min-w-[5.5rem]"
                    disabled
                >
                    Previous
                </Button>
                <div class="flex flex-wrap items-center justify-center gap-1">
                    <Button
                        v-for="p in pagination.pages"
                        :key="p.page"
                        size="sm"
                        :variant="p.active ? 'default' : 'outline'"
                        class="min-w-9 px-2"
                        as-child
                    >
                        <Link :href="p.url" preserve-scroll>{{ p.page }}</Link>
                    </Button>
                </div>
                <Button
                    v-if="pagination.next_url"
                    variant="outline"
                    size="sm"
                    class="min-w-[5.5rem]"
                    as-child
                >
                    <Link :href="pagination.next_url" preserve-scroll>
                        Next
                    </Link>
                </Button>
                <Button
                    v-else
                    variant="outline"
                    size="sm"
                    class="min-w-[5.5rem]"
                    disabled
                >
                    Next
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">
                Page {{ pagination.current_page }} of {{ pagination.last_page }}
            </p>
        </nav>

        <p v-if="!auth.user" class="text-center text-sm text-muted-foreground">
            <Link :href="login().url" class="font-medium text-foreground underline underline-offset-2">
                Sign in
            </Link>
            to fork maps, vote, or start lobbies.
        </p>
    </div>
</template>
