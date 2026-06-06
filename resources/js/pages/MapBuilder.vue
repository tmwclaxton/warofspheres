<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Map, Mountain, Trees, Waves } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';

const terrainTypes = [
    {
        icon: Waves,
        name: 'Water',
        description: 'Slows movement and weakens attacks.',
    },
    {
        icon: Trees,
        name: 'Forest',
        description: 'Moderate cover with reduced speed.',
    },
    {
        icon: Mountain,
        name: 'Mountains',
        description: 'Impassable barriers that shape the front line.',
    },
];
</script>

<template>
    <Head title="Map Builder" />

    <div class="flex flex-col gap-8">
        <Heading
            title="Map Builder"
            description="Design custom battlefields with terrain, cities, and spawn zones."
        />

        <div
            class="rounded-2xl border-2 border-dashed border-[#1a1814]/20 bg-[#f7f1e3]/80 p-10 text-center"
        >
            <div
                class="mx-auto mb-4 flex size-16 items-center justify-center rounded-2xl border border-[#1a1814]/10 bg-[#e8dfc8]"
            >
                <Map class="size-8 text-[#5c5346]" />
            </div>
            <h2 class="text-2xl font-bold">Coming soon</h2>
            <p class="mx-auto mt-3 max-w-lg text-[#3d362b]">
                The map builder will let you paint terrain, place cities, and
                publish scenarios for your team to fight over.
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <article
                v-for="terrain in terrainTypes"
                :key="terrain.name"
                class="rounded-xl border border-[#1a1814]/15 bg-[#f7f1e3]/60 p-5"
            >
                <component :is="terrain.icon" class="mb-3 size-5 text-[#5c5346]" />
                <h3 class="font-bold">{{ terrain.name }}</h3>
                <p class="mt-1 text-sm text-[#3d362b]">
                    {{ terrain.description }}
                </p>
            </article>
        </div>
    </div>
</template>
