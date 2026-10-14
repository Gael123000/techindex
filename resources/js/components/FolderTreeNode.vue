<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Folder, FolderOpen } from '@lucide/vue';
import { computed } from 'vue';
import type { FolderNode } from '@/types';

/**
 * Nœud d'une arborescence de dossiers (composant récursif).
 */
defineOptions({ name: 'FolderTreeNode' });

const props = defineProps<{
    folder: FolderNode;
    folders: FolderNode[];
    selectedId: number | null;
    expanded: Set<number>;
    hrefFor: (id: number) => string;
    depth: number;
}>();

const emit = defineEmits<{ toggle: [id: number] }>();

const children = computed(() => props.folders.filter((item) => item.parent_id === props.folder.id));
const open = computed(() => props.expanded.has(props.folder.id));
const selected = computed(() => props.selectedId === props.folder.id);
</script>

<template>
    <li>
        <div
            class="flex items-center rounded-md text-sm"
            :class="selected ? 'bg-turquoise-50 font-medium text-navy-900' : 'text-slate-700 hover:bg-slate-100'"
            :style="{ paddingLeft: `${depth * 0.875}rem` }"
        >
            <button
                v-if="children.length"
                type="button"
                class="shrink-0 rounded p-0.5 text-slate-400 hover:text-slate-700"
                :aria-expanded="open"
                @click="emit('toggle', folder.id)"
            >
                <ChevronRight class="h-3.5 w-3.5 transition-transform" :class="open ? 'rotate-90' : ''" aria-hidden="true" />
                <span class="sr-only">{{ open ? 'Replier' : 'Déplier' }} {{ folder.name }}</span>
            </button>
            <span v-else class="w-4.5 shrink-0" aria-hidden="true" />
            <Link
                :href="hrefFor(folder.id)"
                class="flex min-w-0 flex-1 items-center gap-1.5 py-1 pr-2 pl-0.5"
                :aria-current="selected ? 'page' : undefined"
                preserve-scroll
            >
                <FolderOpen v-if="selected || open" class="h-4 w-4 shrink-0 text-amber-500" aria-hidden="true" />
                <Folder v-else class="h-4 w-4 shrink-0 text-amber-500" aria-hidden="true" />
                <span class="truncate">{{ folder.name }}</span>
                <span v-if="folder.count" class="ml-auto pl-1 text-xs text-slate-400">{{ folder.count }}</span>
            </Link>
        </div>
        <ul v-if="children.length && open">
            <FolderTreeNode
                v-for="child in children"
                :key="child.id"
                :folder="child"
                :folders="folders"
                :selected-id="selectedId"
                :expanded="expanded"
                :href-for="hrefFor"
                :depth="depth + 1"
                @toggle="emit('toggle', $event)"
            />
        </ul>
    </li>
</template>
