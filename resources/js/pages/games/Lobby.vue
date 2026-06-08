<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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

type PublishedMap = {
    uuid: string;
    name: string;
    teamCount: number;
    ownerName: string;
};

const props = defineProps<{
    lobbies: Lobby[];
    publishedMaps: PublishedMap[];
}>();

const createForm = useForm({
    map_uuid: '',
});

const joinForm = useForm({
    code: '',
});

const selectedMap = computed(() =>
    props.publishedMaps.find((m) => m.uuid === createForm.map_uuid),
);

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
            description="Pick a published map — lobby size matches the map’s team count. Everyone must join before the host can start."
        />

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="wod-panel space-y-4 p-5">
                <div class="flex items-center gap-2">
                    <div class="wod-swatch bg-wod-red" aria-hidden="true" />
                    <h2 class="font-bold">Create lobby</h2>
                </div>
                <div v-if="publishedMaps.length === 0" class="text-sm text-muted-foreground">
                    No published maps yet. Publish one from the Map Builder or explore the gallery.
                </div>
                <template v-else>
                    <div class="space-y-2">
                        <Label for="map_uuid">Published map</Label>
                        <Select v-model="createForm.map_uuid">
                            <SelectTrigger id="map_uuid" class="w-full">
                                <SelectValue placeholder="Choose a map…" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="m in publishedMaps"
                                    :key="m.uuid"
                                    :value="m.uuid"
                                >
                                    {{ m.name }} · {{ m.teamCount }} teams · {{ m.ownerName }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="createForm.errors.map_uuid" />
                        <p
                            v-if="selectedMap"
                            class="text-xs text-muted-foreground"
                        >
                            This lobby will hold
                            <strong>{{ selectedMap.teamCount }}</strong>
                            commanders (one per team on the map).
                        </p>
                    </div>
                    <Button
                        :disabled="createForm.processing || !createForm.map_uuid"
                        @click="createLobby"
                    >
                        <Plus class="mr-2 h-4 w-4" />
                        Create lobby
                    </Button>
                </template>
            </div>

            <div class="wod-panel space-y-4 p-5">
                <div class="flex items-center gap-2">
                    <div class="wod-swatch bg-wod-blue" aria-hidden="true" />
                    <h2 class="font-bold">Join by code</h2>
                </div>
                <div class="space-y-2">
                    <Label for="code">Lobby code</Label>
                    <Input
                        id="code"
                        v-model="joinForm.code"
                        maxlength="6"
                        class="uppercase tracking-widest"
                        placeholder="ABC123"
                    />
                    <InputError :message="joinForm.errors.code" />
                </div>
                <Button
                    variant="outline"
                    :disabled="joinForm.processing"
                    @click="joinLobby"
                >
                    Join lobby
                </Button>
            </div>
        </div>

        <div class="space-y-3">
            <h2 class="font-bold">Open lobbies</h2>
            <div
                v-if="lobbies.length === 0"
                class="wod-panel-dashed p-8 text-center text-muted-foreground"
            >
                No open lobbies. Create one to get started.
            </div>
            <div
                v-for="lobby in lobbies"
                :key="lobby.uuid"
                class="flex items-center justify-between wod-panel p-4"
            >
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-bold tracking-widest">{{
                            lobby.code
                        }}</span>
                        <Badge
                            variant="outline"
                            class="border-foreground bg-wod-green-lt"
                        >
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
