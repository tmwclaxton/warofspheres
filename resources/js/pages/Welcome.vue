<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { BookOpen, Github, Swords, Trophy, Users } from 'lucide-vue-next';
import { Button, buttonVariants } from '@/components/ui/button';
import GameLogoMark from '@/components/GameLogoMark.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { GITHUB_REPOSITORY_URL } from '@/lib/site';
import { cn } from '@/lib/utils';
import { login, wiki } from '@/routes';
import { index as leaderboardIndex } from '@/routes/leaderboard';
import { index as lobbiesIndex } from '@/routes/lobbies';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);

const page = usePage();

const features = [
    {
        title: '2–6 commanders',
        description:
            'Create a lobby or join with a code. Every player gets a color and a corner of the map.',
        swatch: 'bg-wod-red',
    },
    {
        title: 'Plan, then strike',
        description:
            'Draw movement paths for troops and cities, then commit your orders in one decisive push.',
        swatch: 'bg-wod-blue',
    },
    {
        title: 'Fog of war',
        description:
            'Terrain, borders, and vision shift as armies move. You only see what your forces reveal.',
        swatch: 'bg-wod-green-dk',
    },
    {
        title: 'Cities & terrain',
        description:
            'Hills slow you down, mountains block you, water weakens attacks. Cities are worth fighting for.',
        swatch: 'bg-wod-gray-dk',
    },
] as const;

const steps = [
    {
        number: '01',
        title: 'Join a lobby',
        description: 'Host a match or enter a six-character code to rally your rivals.',
    },
    {
        number: '02',
        title: 'Draft your advance',
        description: 'Sketch paths across the map before anyone moves a single sphere.',
    },
    {
        number: '03',
        title: 'Execute & adapt',
        description: 'Commit orders, watch the clash unfold, and redraw as the front line shifts.',
    },
] as const;
</script>

<template>
    <Head title="Clash of Dots" />

    <div class="wod-page min-w-0 overflow-x-hidden">
        <div class="relative flex min-h-svh min-w-0 flex-col">
            <header class="wod-bar-top relative shrink-0">
                <div
                    class="relative mx-auto flex w-full min-w-0 max-w-6xl flex-col gap-4 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-3 sm:px-6 sm:py-4"
                >
                    <div class="flex min-w-0 items-center gap-2.5 sm:gap-3">
                        <GameLogoMark class="size-8 sm:size-9" />
                        <div class="min-w-0">
                            <p class="font-display text-base font-bold leading-tight sm:text-lg">
                                Clash of Dots
                            </p>
                            <p class="wod-tagline text-xs sm:text-sm">Plan first, fight second</p>
                        </div>
                    </div>
                    <nav class="flex w-full min-w-0 flex-wrap items-center gap-1.5 sm:w-auto sm:justify-end sm:gap-2">
                        <ThemeToggle />
                        <Button
                            variant="ghost"
                            size="sm"
                            as-child
                            class="wod-nav-ghost shrink-0 px-2 sm:px-3"
                        >
                            <a
                                :href="GITHUB_REPOSITORY_URL"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Github class="size-4" />
                                <span class="hidden sm:inline">GitHub</span>
                            </a>
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            as-child
                            class="wod-nav-ghost shrink-0 px-2 sm:px-3"
                        >
                            <Link :href="wiki().url">
                                <BookOpen class="size-4" />
                                Wiki
                            </Link>
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            as-child
                            class="wod-nav-ghost shrink-0 px-2 sm:px-3"
                        >
                            <Link :href="leaderboardIndex().url">
                                <Trophy class="size-4" />
                                Leaderboard
                            </Link>
                        </Button>
                        <Button size="sm" as-child class="shrink-0 sm:h-10 sm:px-4 sm:text-sm">
                            <Link :href="lobbiesIndex().url">
                                <Users class="size-4" />
                                Play Now
                            </Link>
                        </Button>
                        <Link
                            v-if="!page.props.auth.user"
                            :href="login().url"
                            :class="
                                cn(
                                    buttonVariants({ variant: 'outline', size: 'sm' }),
                                    'shrink-0 sm:h-10 sm:px-4 sm:text-sm',
                                )
                            "
                        >
                            <GameLogoMark class="size-4 rounded-sm sm:size-5" />
                            <span class="hidden min-[380px]:inline">Sign in</span>
                            <span class="min-[380px]:hidden">Login</span>
                        </Link>
                    </nav>
                </div>
            </header>

            <section class="relative flex min-w-0 flex-1 flex-col justify-center">
                <div class="mx-auto w-full min-w-0 max-w-6xl px-4 py-8 sm:px-6 sm:py-10">
                    <div class="max-w-3xl">
                        <a
                            :href="GITHUB_REPOSITORY_URL"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mb-4 inline-flex max-w-full items-center gap-2 rounded-full border-2 border-foreground bg-card/80 px-3 py-1.5 text-xs font-semibold text-foreground transition-colors hover:bg-card sm:text-sm"
                        >
                            <Github class="size-4 shrink-0" />
                            <span class="truncate">Open source on GitHub</span>
                        </a>
                        <h1
                            class="font-display text-3xl font-bold leading-[1.08] tracking-tight text-balance sm:text-5xl sm:leading-[1.05] md:text-6xl"
                        >
                            Draw the plan. Win the war.
                        </h1>
                        <p
                            class="mt-4 text-pretty text-base leading-relaxed text-muted-foreground sm:mt-5 sm:text-lg"
                        >
                            A real-time strategy game inspired by
                            <em class="font-display not-italic">
                                <a
                                    href="https://warofdots.net/"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="wod-link text-foreground"
                                >War of Dots</a>
                            </em>
                            and the tactical clarity of
                            <em class="font-display not-italic">
                                <a
                                    href="https://www.youtube.com/c/HistoriaCivilis"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="wod-link text-foreground"
                                >Historia Civilis</a>
                            </em>.
                        </p>
                        <div class="mt-6 flex w-full min-w-0 flex-col gap-3 sm:mt-7 sm:flex-row sm:flex-wrap">
                            <Button as-child class="w-full sm:w-auto" size="lg">
                                <Link :href="lobbiesIndex().url">
                                    <Swords class="size-5" />
                                    Enter the battlefield
                                </Link>
                            </Button>
                            <Link
                                v-if="!page.props.auth.user"
                                :href="login().url"
                                :class="
                                    cn(
                                        buttonVariants({ variant: 'outline', size: 'lg' }),
                                        'w-full sm:w-auto',
                                    )
                                "
                            >
                                <GameLogoMark class="size-5 rounded-sm" />
                                Sign in for maps & history
                            </Link>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <main class="mx-auto w-full min-w-0 max-w-6xl px-4 pb-12 sm:px-6 sm:pb-16">
            <section class="grid gap-4 sm:grid-cols-2">
                <article
                    v-for="feature in features"
                    :key="feature.title"
                    class="wod-panel flex gap-3 p-4 sm:gap-4 sm:p-5"
                >
                    <div
                        :class="['wod-swatch mt-1', feature.swatch]"
                        aria-hidden="true"
                    />
                    <div>
                        <h2 class="font-display text-lg font-bold">
                            {{ feature.title }}
                        </h2>
                        <p class="mt-1 text-sm leading-relaxed text-muted-foreground">
                            {{ feature.description }}
                        </p>
                    </div>
                </article>
            </section>

            <section class="wod-panel-dark mt-10 p-5 sm:mt-12 sm:p-8 md:p-10">
                <h2 class="font-display text-xl font-bold sm:text-2xl md:text-3xl">
                    How it plays
                </h2>
                <ol class="mt-8 grid gap-6 md:grid-cols-3">
                    <li
                        v-for="step in steps"
                        :key="step.number"
                        class="border-t-2 border-card/25 pt-5"
                    >
                        <p class="text-sm font-bold text-wod-green-lt">
                            {{ step.number }}
                        </p>
                        <h3 class="font-display mt-2 text-lg font-bold">
                            {{ step.title }}
                        </h3>
                        <p class="mt-1 text-sm leading-relaxed text-card/80">
                            {{ step.description }}
                        </p>
                    </li>
                </ol>
            </section>

            <section
                class="mt-10 flex w-full min-w-0 flex-col gap-5 wod-panel p-5 sm:mt-12 sm:flex-row sm:items-center sm:justify-between sm:p-6"
            >
                <div class="min-w-0">
                    <h2 class="font-display text-xl font-bold md:text-2xl">
                        Ready when you are.
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Open a lobby with a code, or sign in to publish maps and track past matches.
                    </p>
                </div>
                <div class="flex w-full min-w-0 shrink-0 flex-col gap-2 sm:w-auto sm:items-end">
                    <Button as-child class="w-full sm:w-auto" size="lg">
                        <Link :href="lobbiesIndex().url">
                            <Users class="size-5" />
                            Browse lobbies
                        </Link>
                    </Button>
                    <Link
                        v-if="!page.props.auth.user"
                        :href="login().url"
                        :class="
                            cn(
                                buttonVariants({ variant: 'outline', size: 'lg' }),
                                'w-full sm:w-auto',
                            )
                        "
                    >
                        <GameLogoMark class="size-5 rounded-sm" />
                        Sign in
                    </Link>
                </div>
            </section>
        </main>

        <footer class="wod-bar-bottom px-4 py-3 text-center text-sm sm:px-6">
            <p class="font-display text-pretty font-bold leading-snug text-foreground">
                <a
                    :href="GITHUB_REPOSITORY_URL"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="wod-link break-words"
                >Open source on GitHub</a>
                <span class="text-muted-foreground"> · </span>
                <span class="whitespace-nowrap">Built with Irish Love ☘️</span>
            </p>
        </footer>
    </div>
</template>
