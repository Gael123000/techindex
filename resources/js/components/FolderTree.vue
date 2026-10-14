<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { FolderNode } from '@/types';
import FolderTreeNode from './FolderTreeNode.vue';

/**
 * Arborescence de dossiers d'un client. Les dossiers parents de la sélection
 * sont dépliés automatiquement.
 */
const props = withDefaults(defineProps<{ folders: FolderNode[]; selectedId: number | null; hrefFor: (id: number) => string; depth?: number }>(), { depth: 0 });

const expanded = ref(new Set<number>());

const roots = computed(() => props.folders.filter((folder) => folder.parent_id === null));

function expandAncestors(): void {
    const next = new Set(expanded.value);
    let current = props.folders.find((folder) => folder.id === props.selectedId);

    // La limite évite toute boucle sur des données incohérentes.
    for (let guard = 0; current && guard < 50; guard++) {
        next.add(current.id);
        const parentId = current.parent_id;
        current = parentId === null ? undefined : props.folders.find((folder) => folder.id === parentId);
    }

    expanded.value = next;
}

function toggle(id: number): void {
    const next = new Set(expanded.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    expanded.value = next;
}

watch(() => [props.selectedId, props.folders.length], expandAncestors, { immediate: true });
</script>

<template>
    <ul v-if="roots.length">
        <FolderTreeNode
            v-for="folder in roots"
            :key="folder.id"
            :folder="folder"
            :folders="folders"
            :selected-id="selectedId"
            :expanded="expanded"
            :href-for="hrefFor"
            :depth="depth"
            @toggle="toggle"
        />
    </ul>
</template>
