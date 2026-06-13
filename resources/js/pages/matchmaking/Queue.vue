<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onUnmounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { join as joinRoute, leave as leaveRoute, status as statusRoute } from '@/routes/matchmaking';
import { show as showRoute } from '@/routes/games';

const props = defineProps<{
    queued: boolean;
    mmr: number;
}>();

const isQueued = ref(props.queued);
const queueSeconds = ref(0);
const pollingInterval = ref<ReturnType<typeof setInterval> | null>(null);

function csrfToken(): string {
    const meta = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;
    return meta?.content ?? '';
}

async function joinQueue() {
    const res = await fetch(joinRoute().url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': csrfToken(), 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
    });
    if (res.ok) {
        isQueued.value = true;
        queueSeconds.value = 0;
        startPolling();
    }
}

async function leaveQueue() {
    await fetch(leaveRoute().url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': csrfToken() },
    });
    isQueued.value = false;
    stopPolling();
}

function startPolling() {
    pollingInterval.value = setInterval(async () => {
        queueSeconds.value++;
        const res = await fetch(statusRoute().url, { credentials: 'same-origin' });
        if (!res.ok) {
            return;
        }
        const data = (await res.json()) as { status: string; gameUuid?: string };
        if (data.status === 'matched' && data.gameUuid) {
            stopPolling();
            router.visit(showRoute(data.gameUuid).url);
        }
    }, 2000);
}

function stopPolling() {
    if (pollingInterval.value !== null) {
        clearInterval(pollingInterval.value);
        pollingInterval.value = null;
    }
}

if (props.queued) {
    startPolling();
}

onUnmounted(stopPolling);
</script>

<template>
    <Head title="Ranked Matchmaking" />

    <div class="mx-auto flex max-w-md flex-col items-center gap-6 px-4 py-16">
        <div class="text-center">
            <h1 class="text-2xl font-bold">Ranked Matchmaking</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Your MMR: <span class="font-semibold text-foreground">{{ mmr }}</span>
            </p>
        </div>

        <div
            v-if="isQueued"
            class="flex w-full flex-col items-center gap-4 rounded-lg border bg-card p-6 text-center"
        >
            <div class="flex h-12 w-12 items-center justify-center rounded-full border-4 border-primary">
                <span class="text-lg font-bold text-primary animate-pulse">⏳</span>
            </div>
            <div>
                <p class="font-semibold">Searching for an opponent…</p>
                <p class="mt-0.5 text-sm text-muted-foreground">{{ queueSeconds }}s elapsed</p>
            </div>
            <Button
                variant="destructive"
                class="w-full"
                @click="leaveQueue"
            >
                Leave Queue
            </Button>
        </div>

        <div
            v-else
            class="flex w-full flex-col items-center gap-4 rounded-lg border bg-card p-6 text-center"
        >
            <p class="text-sm text-muted-foreground">
                Find a fair match based on your skill rating. You will be redirected to the lobby when a match is found.
            </p>
            <Button
                class="w-full"
                @click="joinQueue"
            >
                Find Match
            </Button>
        </div>
    </div>
</template>
