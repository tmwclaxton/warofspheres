<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Github, Swords } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { GITHUB_REPOSITORY_URL } from '@/lib/site';
import { login, wiki } from '@/routes';
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

    <div class="wod-page">
        <div class="relative flex min-h-svh flex-col">
            <header class="wod-bar-top relative shrink-0">
                <div
                    class="relative mx-auto flex max-w-6xl items-center justify-between px-6 py-4"
                >
                    <div class="flex items-center gap-3">
                        <div class="wod-logo-terrain size-9">
                            <Swords class="size-4" />
                        </div>
                        <div>
                            <p class="font-display text-lg font-bold leading-tight">
                                Clash of Dots
                            </p>
                            <p class="wod-tagline">Plan first, fight second</p>
                        </div>
                    </div>
                    <nav class="flex items-center gap-2">
                        <ThemeToggle />
                        <Button variant="ghost" as-child>
                            <a
                                :href="GITHUB_REPOSITORY_URL"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Github class="size-4" />
                                <span class="hidden sm:inline">GitHub</span>
                            </a>
                        </Button>
                        <Link :href="wiki().url">
                            <Button variant="ghost">Wiki</Button>
                        </Link>
                        <Link v-if="page.props.auth.user" :href="lobbiesIndex().url">
                            <Button>Play Now</Button>
                        </Link>
                        <Link v-else :href="login()">
                            <Button variant="outline">Enter Battle</Button>
                        </Link>
                    </nav>
                </div>
            </header>

            <section class="relative flex flex-1 flex-col justify-center">
                <div class="mx-auto w-full max-w-6xl px-6 py-10">
                    <div class="max-w-3xl">
                        <a
                            :href="GITHUB_REPOSITORY_URL"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mb-4 inline-flex items-center gap-2 rounded-full border-2 border-foreground bg-card/80 px-3 py-1 text-sm font-semibold text-foreground transition-colors hover:bg-card"
                        >
                            <Github class="size-4" />
                            Open source on GitHub
                        </a>
                        <h1
                            class="font-display text-5xl leading-[1.05] font-bold md:text-6xl"
                        >
                            Draw the plan. Win the war.
                        </h1>
                        <p
                            class="mt-5 text-lg leading-relaxed whitespace-nowrap text-muted-foreground"
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
                        <div class="mt-7 flex flex-wrap gap-3">
                            <Link
                                v-if="page.props.auth.user"
                                :href="lobbiesIndex().url"
                            >
                                <Button size="lg">Enter the battlefield</Button>
                            </Link>
                            <Link v-else :href="login()">
                                <Button size="lg" variant="outline">
                                    Enter Battle
                                </Button>
                            </Link>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <main class="mx-auto max-w-6xl px-6 pb-16">
            <section class="grid gap-4 sm:grid-cols-2">
                <article
                    v-for="feature in features"
                    :key="feature.title"
                    class="wod-panel flex gap-4 p-5"
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

            <section class="wod-panel-dark mt-12 p-8 md:p-10">
                <h2 class="font-display text-2xl font-bold md:text-3xl">
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
                class="mt-12 flex flex-col items-start justify-between gap-5 wod-panel p-6 md:flex-row md:items-center"
            >
                <div>
                    <h2 class="font-display text-xl font-bold md:text-2xl">
                        Ready when you are.
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Log in, find a lobby, and start drawing your advance.
                    </p>
                </div>
                <Link
                    v-if="page.props.auth.user"
                    :href="lobbiesIndex().url"
                >
                    <Button size="lg">Browse lobbies</Button>
                </Link>
                <Link v-else :href="login()">
                    <Button size="lg" variant="outline">Enter Battle</Button>
                </Link>
            </section>
        </main>

        <footer class="wod-bar-bottom px-6 text-center">
            <p class="font-display font-bold text-foreground">
                <a
                    :href="GITHUB_REPOSITORY_URL"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="wod-link"
                >Open source on GitHub</a>
                <span class="text-muted-foreground"> · </span>
                Built with Irish Love ☘️
            </p>
        </footer>
    </div>
</template>
