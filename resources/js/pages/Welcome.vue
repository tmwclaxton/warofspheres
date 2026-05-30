<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';
import { index as gamesIndex } from '@/routes/games';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);

const page = usePage();
const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);
</script>

<template>
    <Head title="War of Dots">
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link
            href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60;600&display=swap"
            rel="stylesheet"
        />
    </Head>

    <div
        class="min-h-screen bg-[#e8dfc8] text-[#1a1814]"
        style="font-family: 'Source Serif 4', Georgia, serif"
    >
        <header class="mx-auto flex max-w-6xl items-center justify-between px-6 py-6">
            <p
                class="text-lg tracking-[0.2em] uppercase"
                style="font-family: Cinzel, serif"
            >
                War of Dots
            </p>
            <nav class="flex gap-3">
                <Link v-if="page.props.auth.user" :href="gamesIndex().url">
                    <Button>Play Now</Button>
                </Link>
                <Link v-else :href="login()">
                    <Button>Log in to play</Button>
                </Link>
            </nav>
        </header>

        <main class="mx-auto grid max-w-6xl gap-10 px-6 pb-16 lg:grid-cols-2 lg:items-center">
            <section class="space-y-6">
                <p class="text-sm uppercase tracking-[0.35em] text-[#5c5346]">
                    Strategic multiplayer
                </p>
                <h1
                    class="text-4xl leading-tight md:text-5xl"
                    style="font-family: Cinzel, serif"
                >
                    Command. Conquer. Dominate.
                </h1>
                <p class="max-w-xl text-lg text-[#3d362b]">
                    A barebones real-time strategy game inspired by
                    <em>War of Dots</em> and the tactical clarity of Historia Civilis.
                    Plan your advance like a campaign map, then commit your orders and
                    watch the dots clash across procedurally generated terrain.
                </p>
                <ul class="space-y-2 text-[#3d362b]">
                    <li>2–6 players per match</li>
                    <li>Draw attack paths before you execute</li>
                    <li>Fog of war, cities, borders, and terrain that matters</li>
                </ul>
                <div class="flex flex-wrap gap-3">
                    <Link v-if="page.props.auth.user" :href="gamesIndex().url">
                        <Button size="lg">Enter the battlefield</Button>
                    </Link>
                    <Link v-else :href="login()">
                        <Button size="lg">Log in with WorkOS</Button>
                    </Link>
                    <Link v-if="page.props.auth.user" :href="dashboardUrl">
                        <Button size="lg" variant="outline">Dashboard</Button>
                    </Link>
                </div>
            </section>

            <section
                class="relative aspect-[16/10] overflow-hidden rounded-2xl border-2 border-[#1a1814]/20 bg-[#c8d68a] shadow-[8px_8px_0_#1a1814]/10"
                aria-hidden="true"
            >
                <svg viewBox="0 0 640 400" class="h-full w-full">
                    <rect width="640" height="400" fill="#c8d68a" />
                    <ellipse cx="120" cy="280" rx="90" ry="55" fill="#3d6b45" />
                    <ellipse cx="500" cy="120" rx="110" ry="70" fill="#3d6b45" />
                    <path
                        d="M0 220 C160 180, 220 260, 320 200 S520 120, 640 160 L640 240 C520 260, 420 300, 320 280 S120 320, 0 300 Z"
                        fill="#4a90d9"
                    />
                    <polygon
                        points="300,60 340,120 260,120"
                        fill="#5a5a5a"
                    />
                    <circle cx="180" cy="180" r="6" fill="#c0392b" />
                    <circle cx="200" cy="190" r="6" fill="#c0392b" />
                    <circle cx="220" cy="185" r="6" fill="#c0392b" />
                    <circle cx="420" cy="210" r="6" fill="#2980b9" />
                    <circle cx="440" cy="220" r="6" fill="#2980b9" />
                    <circle cx="460" cy="215" r="6" fill="#2980b9" />
                    <polygon
                        points="310,250 318,266 302,266"
                        fill="#f1c40f"
                        stroke="#1a1a1a"
                        stroke-width="1"
                    />
                    <path
                        d="M200 185 Q260 150, 310 250"
                        fill="none"
                        stroke="#1a1a1a"
                        stroke-width="3"
                        marker-end="url(#arrow)"
                    />
                    <path
                        d="M440 215 Q380 170, 330 255"
                        fill="none"
                        stroke="#1a1a1a"
                        stroke-width="3"
                    />
                    <defs>
                        <marker
                            id="arrow"
                            markerWidth="8"
                            markerHeight="8"
                            refX="6"
                            refY="3"
                            orient="auto"
                        >
                            <path d="M0,0 L6,3 L0,6 Z" fill="#1a1a1a" />
                        </marker>
                    </defs>
                </svg>
            </section>
        </main>
    </div>
</template>
