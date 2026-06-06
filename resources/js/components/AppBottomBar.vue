<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import FactionSwatches from '@/components/FactionSwatches.vue';
import { edit as editProfile } from '@/routes/profile';

const page = usePage();

const user = computed(() => page.props.auth.user);
</script>

<template>
    <footer class="wod-bar-bottom px-6">
        <div
            class="relative mx-auto flex max-w-6xl flex-wrap items-center justify-center gap-x-4 gap-y-2 text-center sm:justify-between sm:text-left"
        >
            <div class="flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                <FactionSwatches />
                <p>
                    <span class="font-display font-bold text-foreground"
                        >War of Spheres</span
                    >
                    <span v-if="user" class="text-foreground/70"> · </span>
                    <span v-if="user">{{ user.name }}</span>
                </p>
            </div>

            <div class="flex items-center gap-4">
                <p class="hidden text-xs font-semibold text-wod-green-dk md:block">
                    Draw the plan. Win the war.
                </p>
                <Link :href="editProfile().url" class="wod-link text-sm">
                    Settings
                </Link>
            </div>
        </div>
    </footer>
</template>
