<script setup lang="ts">
import { ref } from 'vue';
import type { MapEditorInstance } from '@/composables/useMapEditor';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { FilePlus2, Trash2 } from 'lucide-vue-next';

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
}>();

const loadingUuid = ref<string | null>(null);
const error = ref<string | null>(null);

async function openMap(uuid: string): Promise<void> {
    if (props.editor.currentUuid.value === uuid && !props.editor.dirty.value) {
        return;
    }
    if (
        props.editor.dirty.value
        && !window.confirm('Discard unsaved changes and open this map?')
    ) {
        return;
    }
    error.value = null;
    loadingUuid.value = uuid;
    try {
        await props.editor.loadMap(uuid);
    } catch {
        error.value = 'Could not load map.';
    } finally {
        loadingUuid.value = null;
    }
}

function requestNewMap(): void {
    emit('requestNewMap');
}

async function removeMap(uuid: string, name: string): Promise<void> {
    if (!window.confirm(`Delete “${name}”? This cannot be undone.`)) {
        return;
    }
    error.value = null;
    try {
        await props.editor.deleteMap(uuid);
    } catch {
        error.value = 'Could not delete map.';
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
                No saved maps yet. Paint terrain, then Save.
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
                        class="min-w-0 flex-1 truncate rounded px-1 py-1 text-left text-xs font-medium hover:bg-muted/80 disabled:opacity-50"
                        :disabled="loadingUuid === m.uuid"
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
                        @click="removeMap(m.uuid, m.name)"
                    >
                        <Trash2 class="size-3.5" />
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
