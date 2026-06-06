<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { History, Trophy } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { show } from '@/routes/games';

type Match = {
    uuid: string;
    code: string;
    status: string;
    maxPlayers: number;
    playerCount: number;
    hostName: string;
    winnerName: string | null;
    isWinner: boolean;
    finishedAt: string | null;
    players: Array<{ slot: number; name: string; color: string }>;
};

defineProps<{
    matches: Match[];
}>();

function formatDate(value: string | null): string {
    if (!value) {
        return 'Unknown';
    }

    return new Date(value).toLocaleString();
}
</script>

<template>
    <Head title="Past Matches" />

    <div class="flex flex-col gap-8">
        <Heading
            title="Past Matches"
            description="Your campaign history — wins, losses, and hard-fought draws."
        />

        <div
            v-if="matches.length === 0"
            class="rounded-2xl border border-dashed border-[#1a1814]/20 bg-[#f7f1e3]/60 p-10 text-center text-[#5c5346]"
        >
            <History class="mx-auto mb-3 size-8 opacity-60" />
            <p class="font-bold">No completed matches yet</p>
            <p class="mt-2 text-sm">
                Finish your first battle and it will appear here.
            </p>
        </div>

        <div v-else class="space-y-3">
            <article
                v-for="match in matches"
                :key="match.uuid"
                class="flex flex-col gap-4 rounded-xl border border-[#1a1814]/15 bg-[#f7f1e3]/80 p-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xl font-bold tracking-widest">{{
                            match.code
                        }}</span>
                        <Badge
                            v-if="match.isWinner"
                            class="gap-1 bg-[#c8d68a] text-[#1a1814]"
                        >
                            <Trophy class="size-3" />
                            Victory
                        </Badge>
                        <Badge v-else variant="secondary">Defeat</Badge>
                    </div>
                    <p class="mt-1 text-sm text-[#5c5346]">
                        Winner:
                        <span class="font-bold text-[#1a1814]">{{
                            match.winnerName ?? 'Unknown'
                        }}</span>
                    </p>
                    <p class="text-sm text-[#5c5346]">
                        Finished {{ formatDate(match.finishedAt) }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span
                            v-for="player in match.players"
                            :key="player.slot"
                            class="inline-flex items-center gap-1 rounded-full border border-[#1a1814]/10 px-2 py-1 text-xs"
                        >
                            <span
                                class="size-2 rounded-full"
                                :style="{ backgroundColor: player.color }"
                            />
                            {{ player.name }}
                        </span>
                    </div>
                </div>
                <Link :href="show(match.uuid).url">
                    <Button variant="outline">View summary</Button>
                </Link>
            </article>
        </div>
    </div>
</template>
