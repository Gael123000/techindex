<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { TagRef } from '@/types';

/**
 * Saisie d'étiquettes : choix parmi les étiquettes existantes ou création
 * d'une nouvelle (le serveur crée les noms inconnus). Valeur : liste de noms.
 */
const model = defineModel<string[]>({ required: true });
const props = defineProps<{ id: string; available: TagRef[]; describedBy?: string }>();

const draft = ref('');
const listId = computed(() => `${props.id}-suggestions`);
const suggestions = computed(() => props.available.filter((tag) => !model.value.includes(tag.name)));

function add(): void {
    const name = draft.value.trim().replace(/\s+/g, ' ').slice(0, 60);

    if (name !== '' && !model.value.some((existing) => existing.toLowerCase() === name.toLowerCase())) {
        const known = props.available.find((tag) => tag.name.toLowerCase() === name.toLowerCase());
        model.value = [...model.value, known?.name ?? name];
    }

    draft.value = '';
}

function remove(name: string): void {
    model.value = model.value.filter((existing) => existing !== name);
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' || event.key === ',') {
        event.preventDefault();
        add();
    } else if (event.key === 'Backspace' && draft.value === '' && model.value.length > 0) {
        model.value = model.value.slice(0, -1);
    }
}
</script>

<template>
    <div class="rounded-field border border-slate-300 bg-white px-2 py-1.5 focus-within:border-turquoise-600 focus-within:ring-1 focus-within:ring-turquoise-600">
        <ul class="flex flex-wrap items-center gap-1.5">
            <li v-for="name in model" :key="name" class="inline-flex items-center gap-1 rounded-full bg-navy-50 py-0.5 pr-1 pl-2 text-xs font-medium text-navy-800">
                {{ name }}
                <button type="button" class="rounded-full p-0.5 hover:bg-navy-100" @click="remove(name)">
                    <X class="h-3 w-3" aria-hidden="true" />
                    <span class="sr-only">Retirer l'étiquette {{ name }}</span>
                </button>
            </li>
            <li class="min-w-[8rem] flex-1">
                <input
                    :id="id"
                    v-model="draft"
                    type="text"
                    :list="listId"
                    class="w-full border-0 p-1 text-sm focus:ring-0"
                    placeholder="Ajouter une étiquette…"
                    :aria-describedby="describedBy"
                    @keydown="onKeydown"
                    @change="add"
                />
                <datalist :id="listId">
                    <option v-for="tag in suggestions" :key="tag.id" :value="tag.name" />
                </datalist>
            </li>
        </ul>
    </div>
</template>
