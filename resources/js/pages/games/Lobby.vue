<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { joinCode, show, store } from '@/routes/games';

type Lobby = {
    uuid: string;
    code: string;
    status: string;
    maxPlayers: number;
    playerCount: number;
    isHost: boolean;
    isParticipant: boolean;
    canStart: boolean;
    hostName: string;
    players: Array<{ slot: number; name: string; color: string }>;
};

defineProps<{
    lobbies: Lobby[];
}>();

const createForm = useForm({
    max_players: 2,
});

const joinForm = useForm({
    code: '',
});

function createLobby() {
    createForm.post(store().url);
}

function joinLobby() {
    joinForm.post(joinCode().url);
}
</script>

<template>
    <Head title="Lobbies" />

    <div class="flex flex-col gap-8">
        <Heading
            title="Lobby Overview"
            description="Create or join a quick match. Up to six commanders per battle."
        />

        <div class="grid gap-6 lg:grid-cols-2">
            <div
                class="space-y-4 rounded-xl border border-[#1a1814]/15 bg-[#f7f1e3]/80 p-6"
            >
                <h2 class="text-lg font-semibold">Create lobby</h2>
                <div class="space-y-2">
                    <Label for="max_players">Players</Label>
                    <Select v-model="createForm.max_players">
                        <SelectTrigger id="max_players">
                            <SelectValue placeholder="Select players" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="n in 5"
                                :key="n + 1"
                                :value="n + 1"
                            >
                                {{ n + 1 }} players
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <Button :disabled="createForm.processing" @click="createLobby">
                    <Plus class="mr-2 h-4 w-4" />
                    Create lobby
                </Button>
            </div>

            <div
                class="space-y-4 rounded-xl border border-[#1a1814]/15 bg-[#f7f1e3]/80 p-6"
            >
                <h2 class="text-lg font-semibold">Join by code</h2>
                <div class="space-y-2">
                    <Label for="code">Lobby code</Label>
                    <Input
                        id="code"
                        v-model="joinForm.code"
                        maxlength="6"
                        class="uppercase tracking-widest"
                        placeholder="ABC123"
                    />
                </div>
                <Button
                    variant="secondary"
                    :disabled="joinForm.processing"
                    @click="joinLobby"
                >
                    Join lobby
                </Button>
            </div>
        </div>

        <div class="space-y-3">
            <h2 class="text-lg font-semibold">Open lobbies</h2>
            <div
                v-if="lobbies.length === 0"
                class="rounded-xl border border-dashed border-[#1a1814]/20 bg-[#f7f1e3]/40 p-8 text-center text-[#5c5346]"
            >
                No open lobbies. Create one to get started.
            </div>
            <div
                v-for="lobby in lobbies"
                :key="lobby.uuid"
                class="flex items-center justify-between rounded-xl border border-[#1a1814]/15 bg-[#f7f1e3]/80 p-4"
            >
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-lg tracking-widest">{{
                            lobby.code
                        }}</span>
                        <Badge variant="secondary">
                            {{ lobby.playerCount }}/{{ lobby.maxPlayers }}
                        </Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Host: {{ lobby.hostName }}
                    </p>
                </div>
                <Link :href="show(lobby.uuid).url">
                    <Button variant="outline">View</Button>
                </Link>
            </div>
        </div>
    </div>
</template>
