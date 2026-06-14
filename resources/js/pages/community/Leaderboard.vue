<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Trophy } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { avatarUrl } from '@/composables/useAvatar';
import { getInitials } from '@/composables/useInitials';
import { show as profileShow } from '@/routes/profiles';

type Row = {
    rank: number;
    profileUuid: string;
    name: string;
    avatar: string;
    wins: number;
    losses: number;
    matchesPlayed: number;
    winRate: number;
    publishedMapCount: number;
};

defineProps<{
    leaderboard: Row[];
}>();
</script>

<template>
    <Head title="Leaderboard" />

    <div class="flex flex-col gap-8">
        <Heading
            title="Leaderboard"
            description="Ranked by match wins, then total finished matches. Only players with at least one finished game appear."
        />

        <div
            v-if="leaderboard.length === 0"
            class="wod-panel-dashed p-10 text-center text-muted-foreground"
        >
            <Trophy class="mx-auto mb-2 size-8 opacity-60" />
            <p class="font-bold">No ranked commanders yet</p>
            <p class="mt-1 text-sm">Finish a match to appear here.</p>
        </div>

        <div v-else class="overflow-x-auto wod-panel">
            <table class="w-full min-w-[32rem] text-left text-sm">
                <thead class="border-b-2 border-foreground bg-muted/40">
                    <tr>
                        <th class="px-4 py-3 font-bold">#</th>
                        <th class="px-4 py-3 font-bold">Commander</th>
                        <th class="px-4 py-3 font-bold">Wins</th>
                        <th class="px-4 py-3 font-bold">Losses</th>
                        <th class="px-4 py-3 font-bold">Played</th>
                        <th class="px-4 py-3 font-bold">Win %</th>
                        <th class="px-4 py-3 font-bold">Maps</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in leaderboard"
                        :key="row.profileUuid"
                        class="border-b border-foreground/15 transition-colors hover:bg-muted/30"
                    >
                        <td class="px-4 py-3 font-mono font-bold">{{ row.rank }}</td>
                        <td class="px-4 py-3">
                            <Link
                                :href="profileShow.url(row.profileUuid)"
                                class="flex items-center gap-3 font-medium text-foreground underline-offset-4 hover:underline"
                            >
                                <Avatar class="size-9 border-2 border-foreground bg-black">
                                    <AvatarImage
                                        :src="avatarUrl(row.profileUuid)"
                                        :alt="row.name"
                                    />
                                    <AvatarFallback class="text-xs font-bold">
                                        {{ getInitials(row.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                {{ row.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                v-if="row.rank <= 3"
                                variant="outline"
                                class="border-foreground bg-wod-green-lt"
                            >
                                {{ row.wins }}
                            </Badge>
                            <span v-else>{{ row.wins }}</span>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">{{ row.losses }}</td>
                        <td class="px-4 py-3">{{ row.matchesPlayed }}</td>
                        <td class="px-4 py-3">{{ row.winRate }}%</td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ row.publishedMapCount }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
