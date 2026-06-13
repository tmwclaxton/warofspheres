<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import AppBottomBar from '@/components/AppBottomBar.vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppToast from '@/components/AppToast.vue';
import AppTopBar from '@/components/AppTopBar.vue';
import { play } from '@/routes/games';

const page = usePage<{ activeGame: { uuid: string; status: string } | null }>();
const isLargeScreen = useMediaQuery('(min-width: 1024px)');

const isMapBuilder = computed(() => page.component === 'MapBuilder');
const useMapBuilderChrome = computed(
    () => isMapBuilder.value && isLargeScreen.value,
);

const contentClass = computed(() =>
    useMapBuilderChrome.value
        ? 'mx-auto flex h-full min-h-0 w-full max-w-none flex-1 flex-col overflow-hidden px-2 py-2'
        : 'mx-auto w-full min-w-0 max-w-6xl flex-1 px-4 py-5 sm:px-6 sm:py-8',
);

/** When away from the lobby page, poll for active game status changes and redirect if game starts. */
let lobbyPoll: ReturnType<typeof setInterval> | null = null;

function isOnGameShowPage(): boolean {
    return page.component === 'games/Show';
}

function isOnPlayPage(): boolean {
    return page.component === 'games/Play';
}

function startLobbyPoll(): void {
    if (lobbyPoll !== null) {
        return;
    }
    lobbyPoll = setInterval(() => {
        // Show.vue polls its own 'game' prop; don't double-poll there.
        if (isOnGameShowPage() || isOnPlayPage()) {
            return;
        }
        if (page.props.activeGame?.status === 'lobby') {
            router.reload({ only: ['activeGame'] });
        } else {
            clearInterval(lobbyPoll!);
            lobbyPoll = null;
        }
    }, 3000);
}

watch(
    () => page.props.activeGame,
    (ag, prevAg) => {
        // Only redirect when the game transitions lobby → playing while the user is elsewhere.
        // If they were already in a 'playing' game and chose to navigate away, respect that.
        const justStarted = prevAg?.status === 'lobby' && ag?.status === 'playing';

        if (justStarted && !isOnPlayPage()) {
            router.visit(play(ag.uuid).url);
        } else if (ag?.status === 'lobby' && !isOnGameShowPage()) {
            startLobbyPoll();
        } else if (!ag) {
            if (lobbyPoll !== null) {
                clearInterval(lobbyPoll);
                lobbyPoll = null;
            }
        }
    },
);

onMounted(() => {
    // Start polling if the user already has an active lobby when the layout mounts.
    if (page.props.activeGame?.status === 'lobby' && !isOnGameShowPage()) {
        startLobbyPoll();
    }
});

onBeforeUnmount(() => {
    if (lobbyPoll !== null) {
        clearInterval(lobbyPoll);
        lobbyPoll = null;
    }
});
</script>

<template>
    <AppShell variant="header">
        <div
            :class="
                useMapBuilderChrome
                    ? 'wod-page wod-page-map-builder flex h-svh max-h-svh min-h-0 flex-col overflow-hidden'
                    : 'wod-page flex min-h-svh min-w-0 flex-col overflow-x-hidden'
            "
        >
            <AppTopBar />
            <AppContent variant="header" :class="contentClass">
                <slot />
            </AppContent>
            <AppBottomBar />
            <AppToast />
        </div>
    </AppShell>
</template>
