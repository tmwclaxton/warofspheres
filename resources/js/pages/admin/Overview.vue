<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Activity,
    Clock3,
    Globe2,
    Map,
    Swords,
    TrendingUp,
    Users,
} from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';

type SummaryStats = {
    totalUsers: number;
    activeGames: number;
    matchesToday: number;
    publishedMaps: number;
};

type GameStats = {
    lobby: number;
    playing: number;
    finished: number;
};

type MapStats = {
    total: number;
    published: number;
    draft: number;
    forks: number;
    votes: number;
};

type EngagementStats = {
    avgPlayersPerGame: number;
    avgGameDurationMinutes: number;
    returningPlayersPercent: number;
    newUsersThisWeek: number;
};

type TopMap = {
    name: string;
    plays: number;
    votes: number;
};

type ActivityItem = {
    type: string;
    label: string;
    time: string;
};

defineProps<{
    stats: {
        summary: SummaryStats;
        games: GameStats;
        maps: MapStats;
        engagement: EngagementStats;
        topMaps: TopMap[];
        recentActivity: ActivityItem[];
    };
}>();

function formatNumber(value: number): string {
    return value.toLocaleString();
}

const activityColors: Record<string, string> = {
    game_started: 'bg-wod-red',
    game_finished: 'bg-wod-blue',
    lobby_created: 'bg-wod-green-lt',
    map_published: 'bg-wod-gray-lt',
    map_forked: 'bg-wod-faction-purple',
    user_joined: 'bg-wod-faction-orange',
};
</script>

<template>
    <Head title="Admin Overview" />

    <div class="flex flex-col gap-8">
        <Heading
            title="Admin Overview"
            description="Platform health at a glance. Placeholder figures until live queries are wired up."
        />

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="wod-panel p-5">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-medium text-muted-foreground">
                        Total users
                    </p>
                    <Users class="size-4 text-muted-foreground" />
                </div>
                <p class="font-display mt-2 text-3xl font-bold">
                    {{ formatNumber(stats.summary.totalUsers) }}
                </p>
            </article>

            <article class="wod-panel p-5">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-medium text-muted-foreground">
                        Active games
                    </p>
                    <Swords class="size-4 text-muted-foreground" />
                </div>
                <p class="font-display mt-2 text-3xl font-bold">
                    {{ formatNumber(stats.summary.activeGames) }}
                </p>
            </article>

            <article class="wod-panel p-5">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-medium text-muted-foreground">
                        Matches today
                    </p>
                    <Activity class="size-4 text-muted-foreground" />
                </div>
                <p class="font-display mt-2 text-3xl font-bold">
                    {{ formatNumber(stats.summary.matchesToday) }}
                </p>
            </article>

            <article class="wod-panel p-5">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-medium text-muted-foreground">
                        Published maps
                    </p>
                    <Map class="size-4 text-muted-foreground" />
                </div>
                <p class="font-display mt-2 text-3xl font-bold">
                    {{ formatNumber(stats.summary.publishedMaps) }}
                </p>
            </article>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="wod-panel space-y-4 p-5 lg:col-span-1">
                <div class="flex items-center gap-2">
                    <div class="wod-swatch bg-wod-red" aria-hidden="true" />
                    <h2 class="font-bold">Game status</h2>
                </div>

                <dl class="space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted-foreground">In lobby</dt>
                        <dd class="font-bold">
                            {{ formatNumber(stats.games.lobby) }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted-foreground">In progress</dt>
                        <dd class="font-bold">
                            {{ formatNumber(stats.games.playing) }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted-foreground">Finished</dt>
                        <dd class="font-bold">
                            {{ formatNumber(stats.games.finished) }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="wod-panel space-y-4 p-5 lg:col-span-1">
                <div class="flex items-center gap-2">
                    <div class="wod-swatch bg-wod-blue" aria-hidden="true" />
                    <h2 class="font-bold">Map library</h2>
                </div>

                <dl class="space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted-foreground">Total maps</dt>
                        <dd class="font-bold">
                            {{ formatNumber(stats.maps.total) }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted-foreground">Published</dt>
                        <dd class="font-bold">
                            {{ formatNumber(stats.maps.published) }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted-foreground">Drafts</dt>
                        <dd class="font-bold">
                            {{ formatNumber(stats.maps.draft) }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted-foreground">Forks</dt>
                        <dd class="font-bold">
                            {{ formatNumber(stats.maps.forks) }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted-foreground">Votes cast</dt>
                        <dd class="font-bold">
                            {{ formatNumber(stats.maps.votes) }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="wod-panel space-y-4 p-5 lg:col-span-1">
                <div class="flex items-center gap-2">
                    <div class="wod-swatch bg-wod-green-lt" aria-hidden="true" />
                    <h2 class="font-bold">Engagement</h2>
                </div>

                <dl class="space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted-foreground">
                            Avg players / game
                        </dt>
                        <dd class="font-bold">
                            {{ stats.engagement.avgPlayersPerGame }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted-foreground">
                            Avg match length
                        </dt>
                        <dd class="font-bold">
                            {{ stats.engagement.avgGameDurationMinutes }} min
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted-foreground">
                            Returning players
                        </dt>
                        <dd class="font-bold">
                            {{ stats.engagement.returningPlayersPercent }}%
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted-foreground">
                            New users this week
                        </dt>
                        <dd class="font-bold">
                            {{ formatNumber(stats.engagement.newUsersThisWeek) }}
                        </dd>
                    </div>
                </dl>
            </section>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="wod-panel space-y-4 p-5">
                <div class="flex items-center gap-2">
                    <TrendingUp class="size-4" />
                    <h2 class="font-bold">Top maps by plays</h2>
                </div>

                <div class="space-y-3">
                    <article
                        v-for="(map, index) in stats.topMaps"
                        :key="map.name"
                        class="flex items-center justify-between gap-4 rounded-md border-2 border-foreground/20 px-3 py-2"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                class="font-display flex size-7 shrink-0 items-center justify-center rounded-md border-2 border-foreground bg-card text-sm font-bold"
                            >
                                {{ index + 1 }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-bold">{{ map.name }}</p>
                                <p class="text-sm text-muted-foreground">
                                    {{ formatNumber(map.plays) }} plays
                                </p>
                            </div>
                        </div>
                        <Badge variant="outline">
                            {{ formatNumber(map.votes) }} votes
                        </Badge>
                    </article>
                </div>
            </section>

            <section class="wod-panel space-y-4 p-5">
                <div class="flex items-center gap-2">
                    <Globe2 class="size-4" />
                    <h2 class="font-bold">Recent activity</h2>
                </div>

                <ul class="space-y-3">
                    <li
                        v-for="item in stats.recentActivity"
                        :key="`${item.type}-${item.label}`"
                        class="flex items-start gap-3"
                    >
                        <span
                            class="mt-1.5 size-2.5 shrink-0 rounded-full border-2 border-foreground"
                            :class="activityColors[item.type] ?? 'bg-card'"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ item.label }}</p>
                            <p
                                class="mt-0.5 flex items-center gap-1 text-sm text-muted-foreground"
                            >
                                <Clock3 class="size-3.5" />
                                {{ item.time }}
                            </p>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
