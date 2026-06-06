<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Clock3 } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { play } from '@/routes/games';

type Match = {
    uuid: string;
    code: string;
    status: string;
    maxPlayers: number;
    playerCount: number;
    hostName: string;
    startedAt: string | null;
    players: Array<{ slot: number; name: string; color: string }>;
};

defineProps<{
    matches: Match[];
}>();
</script>

<template>
    <Head title="Ongoing Matches" />

    <div class="flex flex-col gap-8">
        <Heading
            title="Ongoing Matches"
            description="Battles you are currently fighting. Jump back in before the front line shifts."
        />

        <div
            v-if="matches.length === 0"
            class="rounded-2xl border border-dashed border-[#1a1814]/20 bg-[#f7f1e3]/60 p-10 text-center text-[#5c5346]"
        >
            <Clock3 class="mx-auto mb-3 size-8 opacity-60" />
            <p class="font-bold">No active battles</p>
            <p class="mt-2 text-sm">
                Join a lobby or start a new match to get on the map.
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
                        <Badge class="bg-[#c8d68a] text-[#1a1814]">
                            In progress
                        </Badge>
                        <Badge variant="secondary">
                            {{ match.playerCount }}/{{ match.maxPlayers }}
                        </Badge>
                    </div>
                    <p class="mt-1 text-sm text-[#5c5346]">
                        Host: {{ match.hostName }}
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
                <Link :href="play(match.uuid).url">
                    <Button>Return to battle</Button>
                </Link>
            </article>
        </div>
    </div>
</template>
