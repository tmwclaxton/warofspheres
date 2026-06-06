<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Castle,
    Eye,
    PencilLine,
    Swords,
    Users,
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';
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
        icon: Users,
        title: '2–6 commanders',
        description:
            'Create a lobby or join with a code. Every player gets a color and a corner of the map.',
    },
    {
        icon: PencilLine,
        title: 'Plan, then strike',
        description:
            'Draw movement paths for troops and cities, then commit your orders in one decisive push.',
    },
    {
        icon: Eye,
        title: 'Fog of war',
        description:
            'Terrain, borders, and vision shift as armies move. You only see what your forces reveal.',
    },
    {
        icon: Castle,
        title: 'Cities & terrain',
        description:
            'Hills slow you down, mountains block you, water weakens attacks. Cities are worth fighting for.',
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
    <Head title="War of Spheres" />

    <div class="relative overflow-hidden bg-[#e8dfc8] text-[#1a1814]">
        <div
            class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,#f7f1e3_0%,transparent_45%),radial-gradient(circle_at_80%_0%,#d4c4a0_0%,transparent_35%),radial-gradient(circle_at_50%_100%,#c8d68a_0%,transparent_40%)]"
            aria-hidden="true"
        />
        <div class="relative flex min-h-svh flex-col">
        <header
            class="relative shrink-0 border-b border-[#1a1814]/10 bg-[#f7f1e3]/80 backdrop-blur-sm"
        >
            <div
                class="mx-auto flex max-w-6xl items-center justify-between px-6 py-5"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-10 items-center justify-center rounded-full border-2 border-[#1a1814]/15 bg-[#e8dfc8] shadow-[3px_3px_0_#1a1814]/10"
                    >
                        <Swords class="size-5" />
                    </div>
                    <div>
                        <p class="text-lg font-bold tracking-[0.15em] uppercase">
                            War of Spheres
                        </p>
                        <p class="text-xs tracking-[0.2em] text-[#5c5346] uppercase">
                            Tactical multiplayer RTS
                        </p>
                    </div>
                </div>
                <nav class="flex gap-3">
                    <Link v-if="page.props.auth.user" :href="lobbiesIndex().url">
                        <Button>Play Now</Button>
                    </Link>
                    <Link v-else :href="login()">
                        <Button>Log in to play</Button>
                    </Link>
                </nav>
            </div>
        </header>

        <section class="relative flex flex-1 flex-col justify-center">
            <div class="mx-auto w-full max-w-6xl px-6 py-10">
                <div class="max-w-3xl">
                    <p
                        class="inline-flex rounded-full border border-[#1a1814]/15 bg-[#f7f1e3] px-4 py-1.5 text-xs font-bold tracking-[0.3em] text-[#5c5346] uppercase"
                    >
                        Strategic multiplayer
                    </p>
                    <h1
                        class="mt-6 text-5xl leading-[1.05] font-bold tracking-tight md:text-7xl"
                    >
                        Draw the plan.
                        <span class="block text-[#5c5346]">Win the war.</span>
                    </h1>
                    <p class="mt-6 max-w-2xl text-lg leading-relaxed text-[#3d362b] md:text-xl">
                        A real-time strategy game inspired by
                        <em>War of Dots</em> and the tactical clarity of
                        Historia Civilis. Commit your orders, then watch spheres
                        clash across procedurally generated battlefields.
                    </p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <Link v-if="page.props.auth.user" :href="lobbiesIndex().url">
                            <Button size="lg" class="h-12 px-8 text-base">
                                Enter the battlefield
                            </Button>
                        </Link>
                        <Link v-else :href="login()">
                            <Button size="lg" class="h-12 px-8 text-base">
                                Log in with WorkOS
                            </Button>
                        </Link>
                    </div>
                </div>
            </div>
        </section>
        </div>

        <main class="relative mx-auto max-w-6xl px-6 pb-20">
            <section class="grid gap-4 sm:grid-cols-2">
                <article
                    v-for="feature in features"
                    :key="feature.title"
                    class="rounded-2xl border border-[#1a1814]/15 bg-[#f7f1e3]/80 p-6 shadow-[6px_6px_0_#1a1814]/8 transition-transform hover:-translate-y-0.5"
                >
                    <div
                        class="mb-4 flex size-11 items-center justify-center rounded-xl border border-[#1a1814]/10 bg-[#e8dfc8]"
                    >
                        <component :is="feature.icon" class="size-5" />
                    </div>
                    <h2 class="text-xl font-bold">{{ feature.title }}</h2>
                    <p class="mt-2 text-[#3d362b] leading-relaxed">
                        {{ feature.description }}
                    </p>
                </article>
            </section>

            <section
                class="mt-16 rounded-3xl border border-[#1a1814]/15 bg-[#1a1814] p-8 text-[#f7f1e3] md:p-12"
            >
                <div class="max-w-2xl">
                    <p class="text-xs font-bold tracking-[0.35em] text-[#e8dfc8]/70 uppercase">
                        How it plays
                    </p>
                    <h2 class="mt-3 text-3xl font-bold md:text-4xl">
                        Three moves to your first campaign
                    </h2>
                </div>
                <ol class="mt-10 grid gap-8 md:grid-cols-3">
                    <li
                        v-for="step in steps"
                        :key="step.number"
                        class="border-t border-[#f7f1e3]/15 pt-6"
                    >
                        <p class="text-sm font-bold tracking-[0.25em] text-[#c8d68a]">
                            {{ step.number }}
                        </p>
                        <h3 class="mt-3 text-xl font-bold">{{ step.title }}</h3>
                        <p class="mt-2 text-[#e8dfc8]/80 leading-relaxed">
                            {{ step.description }}
                        </p>
                    </li>
                </ol>
            </section>

            <section
                class="mt-16 flex flex-col items-start justify-between gap-6 rounded-2xl border-2 border-[#1a1814]/15 bg-[#f7f1e3] p-8 md:flex-row md:items-center"
            >
                <div>
                    <h2 class="text-2xl font-bold md:text-3xl">
                        Ready when you are.
                    </h2>
                    <p class="mt-2 max-w-xl text-[#3d362b]">
                        No downloads, no installs — just log in, find a lobby,
                        and start drawing your advance.
                    </p>
                </div>
                <Link
                    v-if="page.props.auth.user"
                    :href="lobbiesIndex().url"
                >
                    <Button size="lg" class="h-12 px-8 text-base">
                        Browse lobbies
                    </Button>
                </Link>
                <Link v-else :href="login()">
                    <Button size="lg" class="h-12 px-8 text-base">
                        Get started
                    </Button>
                </Link>
            </section>
        </main>

        <footer
            class="relative border-t border-[#1a1814]/10 bg-[#f7f1e3]/60 px-6 py-6 text-center text-sm text-[#5c5346]"
        >
            <p>War of Spheres — plan first, fight second.</p>
        </footer>
    </div>
</template>
