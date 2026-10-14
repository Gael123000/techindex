<script setup lang="ts">
import { Folder, FolderRoot } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Modal from './Modal.vue';

interface PickerFolder {
    id: number;
    parent_id: number | null;
    name: string;
}

/**
 * Choix d'un dossier de destination (déplacement). Les dossiers exclus
 * (dossier déplacé et ses sous-dossiers) ne peuvent pas être choisis.
 */
const props = withDefaults(
    defineProps<{
        show: boolean;
        title: string;
        folders: PickerFolder[];
        rootLabel: string;
        excluded?: number[];
        processing?: boolean;
    }>(),
    { excluded: () => [], processing: false },
);

const emit = defineEmits<{ close: []; select: [folderId: number | null] }>();

const choice = ref<number | null>(null);

watch(
    () => props.show,
    (show) => {
        if (show) {
            choice.value = null;
        }
    },
);

/**
 * Liste aplatie dans l'ordre de l'arborescence, avec la profondeur.
 */
const flat = computed(() => {
    const result: Array<PickerFolder & { depth: number }> = [];
    const visit = (parentId: number | null, depth: number): void => {
        props.folders
            .filter((folder) => folder.parent_id === parentId)
            .sort((a, b) => a.name.localeCompare(b.name, 'fr', { sensitivity: 'base', numeric: true }))
            .forEach((folder) => {
                result.push({ ...folder, depth });

                if (depth < 20) {
                    visit(folder.id, depth + 1);
                }
            });
    };

    visit(null, 0);

    return result;
});
</script>

<template>
    <Modal :show="show" :title="title" size="sm" @close="emit('close')">
        <fieldset class="max-h-80 overflow-y-auto rounded-md border border-slate-200">
            <legend class="sr-only">Dossier de destination</legend>
            <label class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50" :class="choice === null ? 'bg-turquoise-50' : ''">
                <input v-model="choice" type="radio" name="folder-picker" :value="null" class="h-4 w-4 border-slate-300 text-turquoise-600" />
                <FolderRoot class="h-4 w-4 text-navy-700" aria-hidden="true" />
                <span class="font-medium">{{ rootLabel }}</span>
            </label>
            <label
                v-for="folder in flat"
                :key="folder.id"
                class="flex items-center gap-2 py-1.5 pr-3 text-sm"
                :class="[
                    excluded.includes(folder.id) ? 'cursor-not-allowed text-slate-400' : 'cursor-pointer hover:bg-slate-50',
                    choice === folder.id ? 'bg-turquoise-50' : '',
                ]"
                :style="{ paddingLeft: `${0.75 + (folder.depth + 1) * 1}rem` }"
            >
                <input
                    v-model="choice"
                    type="radio"
                    name="folder-picker"
                    :value="folder.id"
                    :disabled="excluded.includes(folder.id)"
                    class="h-4 w-4 border-slate-300 text-turquoise-600"
                />
                <Folder class="h-4 w-4 shrink-0 text-amber-500" aria-hidden="true" />
                <span class="truncate">{{ folder.name }}</span>
            </label>
        </fieldset>
        <template #footer>
            <button type="button" class="btn-secondary" @click="emit('close')">Annuler</button>
            <button type="button" class="btn-primary" :disabled="processing" @click="emit('select', choice)">Déplacer ici</button>
        </template>
    </Modal>
</template>
