<script setup lang="ts">
import { FilePlus2, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AppModal from '@/components/AppModal.vue';
import { Button } from '@/components/ui/button';
import type { MapEditorInstance } from '@/composables/useMapEditor';
import { cn } from '@/lib/utils';

export type MapSummary = {
    id: number;
    uuid: string;
    name: string;
    updated_at: string | null;
};

const props = defineProps<{
    editor: MapEditorInstance;
    maps: MapSummary[];
}>();

const emit = defineEmits<{
    requestNewMap: [];
    mapsListUpdated: [maps: MapSummary[]];
    openMap: [uuid: string];
}>();

const error = ref<string | null>(null);
const deleteDialogOpen = ref(false);
const deleteDialogMap = ref<{ uuid: string; name: string } | null>(null);
const deleting = ref(false);

const deleteDialogDescription = computed(() => {
    const name = deleteDialogMap.value?.name ?? 'this map';

    return `Delete “${name}”? This cannot be undone.`;
});

function openMap(uuid: string): void {
    if (
        props.editor.dirty.value
        && !window.confirm('Discard unsaved changes and open this map?')
    ) {
        return;
    }

    emit('openMap', uuid);
}

function requestNewMap(): void {
    emit('requestNewMap');
}

function requestRemoveMap(uuid: string, name: string): void {
    deleteDialogMap.value = { uuid, name };
    deleteDialogOpen.value = true;
}

function closeDeleteDialog(): void {
    deleteDialogOpen.value = false;
}

async function confirmRemoveMap(): Promise<void> {
    const map = deleteDialogMap.value;

    if (!map) {
        return;
    }

    error.value = null;
    deleting.value = true;

    try {
        const next = await props.editor.deleteMap(map.uuid);
        deleteDialogOpen.value = false;

        if (next) {
            emit('mapsListUpdated', next);
        }
    } catch {
        error.value = 'Could not delete map.';
    } finally {
        deleting.value = false;
    }
}

function formatUpdated(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    try {
        return new Date(iso).toLocaleString(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    } catch {
        return iso;
    }
}
</script>

<template>
    <div
        class="flex max-h-full min-h-0 w-56 shrink-0 flex-col gap-2 rounded-lg border-2 border-foreground bg-wod-paper p-2 shadow-sm"
    >
        <div class="flex items-center justify-between gap-1">
            <p class="font-display text-xs font-bold uppercase tracking-wide text-muted-foreground">
                Maps
            </p>
            <Button size="sm" variant="outline" class="h-7 gap-1 px-2 text-xs" @click="requestNewMap">
                <FilePlus2 class="size-3.5" />
                New
            </Button>
        </div>
        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
        <ul class="min-h-0 flex-1 space-y-1 overflow-y-auto overscroll-contain pr-0.5 text-sm">
            <li v-if="maps.length === 0" class="px-1 py-2 text-xs text-muted-foreground">
                No saved maps yet. Paint terrain — maps autosave while you work.
            </li>
            <li v-for="m in maps" :key="m.uuid">
                <div
                    :class="
                        cn(
                            'flex items-center gap-1 rounded-md border border-transparent px-1 py-0.5',
                            editor.currentUuid.value === m.uuid && 'border-foreground/40 bg-muted/50',
                        )
                    "
                >
                    <button
                        type="button"
                        class="min-w-0 flex-1 truncate rounded px-1 py-1 text-left text-xs font-medium hover:bg-muted/80"
                        :title="m.name"
                        @click="openMap(m.uuid)"
                    >
                        <span class="block truncate">{{ m.name }}</span>
                        <span class="block truncate text-[10px] font-normal text-muted-foreground">
                            {{ formatUpdated(m.updated_at) }}
                        </span>
                    </button>
                    <Button
                        size="icon"
                        variant="ghost"
                        class="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                        type="button"
                        :aria-label="`Delete ${m.name}`"
                        @click="requestRemoveMap(m.uuid, m.name)"
                    >
                        <Trash2 class="size-3.5" />
                    </Button>
                </div>
            </li>
        </ul>

        <AppModal
            v-model:open="deleteDialogOpen"
            title="Delete this map?"
            :description="deleteDialogDescription"
        >
            <template #footer>
                <Button type="button" variant="outline" :disabled="deleting" @click="closeDeleteDialog">
                    Cancel
                </Button>
                <Button type="button" variant="destructive" :disabled="deleting" @click="confirmRemoveMap">
                    Delete map
                </Button>
            </template>
        </AppModal>
    </div>
</template>
