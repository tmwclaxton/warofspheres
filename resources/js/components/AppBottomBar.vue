<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { edit as editProfile } from '@/routes/profile';

const page = usePage();

const currentTeam = computed(() => page.props.currentTeam);
const user = computed(() => page.props.auth.user);
</script>

<template>
    <footer
        class="mt-auto border-t border-[#1a1814]/10 bg-[#f7f1e3]/80 px-6 py-4 text-sm text-[#5c5346]"
    >
        <div
            class="mx-auto flex max-w-6xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="font-bold tracking-[0.1em] text-[#1a1814] uppercase">
                War of Spheres
            </p>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                <span v-if="currentTeam">
                    Team:
                    <span class="font-bold text-[#1a1814]">{{
                        currentTeam.name
                    }}</span>
                </span>
                <span v-if="user">
                    Commander:
                    <span class="font-bold text-[#1a1814]">{{ user.name }}</span>
                </span>
            </div>

            <div class="flex items-center gap-4">
                <Link
                    :href="editProfile().url"
                    class="hover:text-[#1a1814] hover:underline"
                >
                    Settings
                </Link>
                <span class="text-xs tracking-[0.15em] uppercase">
                    Plan first, fight second
                </span>
            </div>
        </div>
    </footer>
</template>
