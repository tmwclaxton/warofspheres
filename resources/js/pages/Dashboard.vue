<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index as gamesIndex } from '@/routes/games';
import type { Team } from '@/types';

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: props.currentTeam
                    ? dashboard(props.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div
            class="relative overflow-hidden rounded-xl border border-sidebar-border/70 bg-[#f7f1e3] p-8 dark:border-sidebar-border dark:bg-[#1a1814]"
        >
            <p class="text-xs uppercase tracking-[0.3em] text-muted-foreground">
                War of Spheres
            </p>
            <h2 class="mt-2 text-2xl font-semibold">Ready for battle?</h2>
            <p class="mt-2 max-w-xl text-muted-foreground">
                Create a lobby, invite up to five rivals, and command your spheres
                across procedurally generated terrain.
            </p>
            <Link :href="gamesIndex().url" class="mt-6 inline-block">
                <Button size="lg">Play War of Spheres</Button>
            </Link>
        </div>
    </div>
</template>
