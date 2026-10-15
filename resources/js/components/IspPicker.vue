<script setup lang="ts">
import { Check, ChevronsUpDown, Search } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import type { IspOption } from '@/types';
import IspLogo from './IspLogo.vue';

/**
 * Choix d'un opérateur du catalogue, avec recherche et logos.
 * La valeur nulle correspond à un opérateur hors catalogue.
 */
const model = defineModel<number | null>({ required: true });
const props = defineProps<{ id: string; options: IspOption[]; invalid?: boolean; describedBy?: string }>();

const open = ref(false);
const filter = ref('');
const active = ref(0);
const search = ref<HTMLInputElement | null>(null);

const selected = computed(() => props.options.find((option) => option.value === model.value) ?? null);

function normalize(value: string): string {
    return value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const visible = computed(() => {
    const term = normalize(filter.value.trim());

    return term === '' ? props.options : props.options.filter((option) => normalize(option.label).includes(term));
});

function toggle(): void {
    open.value = !open.value;

    if (open.value) {
        filter.value = '';
        active.value = Math.max(0, visible.value.findIndex((option) => option.value === model.value));
        void nextTick(() => search.value?.focus());
    }
}

function choose(value: number | null): void {
    model.value = value;
    open.value = false;
    void nextTick(() => document.getElementById(props.id)?.focus());
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(visible.value.length - 1, active.value + 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(0, active.value - 1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const option = visible.value[active.value];

        if (option) {
            choose(option.value);
        }
    } else if (event.key === 'Escape') {
        event.preventDefault();
        open.value = false;
        document.getElementById(props.id)?.focus();
    } else {
        return;
    }

    void nextTick(() => document.getElementById(`${props.id}-option-${active.value}`)?.scrollIntoView({ block: 'nearest' }));
}

function onFocusOut(event: FocusEvent): void {
    const next = event.relatedTarget as Node | null;

    if (!next || !(event.currentTarget as HTMLElement).contains(next)) {
        open.value = false;
    }
}
</script>

<template>
    <div class="relative" @focusout="onFocusOut">
        <button
            :id="id"
            type="button"
            class="form-input flex items-center gap-2 text-left"
            :class="invalid ? 'border-red-500' : ''"
            aria-haspopup="listbox"
            :aria-expanded="open"
            :aria-describedby="describedBy"
            :aria-invalid="invalid ? 'true' : undefined"
            @click="toggle"
        >
            <IspLogo v-if="selected" :url="selected.logo_url" :name="selected.label" size="xs" />
            <span class="min-w-0 flex-1 truncate" :class="selected ? 'text-slate-900' : 'text-slate-500'">
                {{ selected ? selected.label : 'Opérateur hors catalogue / non choisi' }}
            </span>
            <ChevronsUpDown class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
        </button>

        <div v-if="open" class="absolute inset-x-0 top-full z-30 mt-1 rounded-md border border-slate-200 bg-white shadow-lg">
            <div class="relative border-b border-slate-200 p-2">
                <Search class="pointer-events-none absolute top-1/2 left-4 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                <input
                    ref="search"
                    v-model="filter"
                    type="search"
                    class="form-input py-1.5 pl-8"
                    placeholder="Rechercher un opérateur…"
                    role="combobox"
                    aria-autocomplete="list"
                    :aria-controls="`${id}-listbox`"
                    :aria-expanded="true"
                    :aria-activedescendant="visible.length ? `${id}-option-${active}` : undefined"
                    @input="active = 0"
                    @keydown="onKeydown"
                />
            </div>
            <ul :id="`${id}-listbox`" role="listbox" aria-label="Opérateurs du catalogue" class="max-h-72 overflow-y-auto py-1">
                <li
                    v-for="(option, index) in visible"
                    :id="`${id}-option-${index}`"
                    :key="option.value"
                    role="option"
                    :aria-selected="option.value === model"
                    class="flex cursor-pointer items-center gap-2.5 px-3 py-1.5 text-sm"
                    :class="index === active ? 'bg-turquoise-50' : 'hover:bg-slate-50'"
                    @mousedown.prevent
                    @mouseenter="active = index"
                    @click="choose(option.value)"
                >
                    <IspLogo :url="option.logo_url" :name="option.label" size="sm" />
                    <span class="min-w-0 flex-1 truncate">
                        {{ option.label }}
                        <span v-if="!option.active" class="text-xs text-slate-500">(masqué du catalogue)</span>
                    </span>
                    <Check v-if="option.value === model" class="h-4 w-4 text-turquoise-700" aria-hidden="true" />
                </li>
                <li v-if="visible.length === 0" class="px-3 py-2 text-sm text-slate-500">Aucun opérateur ne correspond.</li>
            </ul>
            <button type="button" class="block w-full border-t border-slate-200 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50" @mousedown.prevent @click="choose(null)">
                Opérateur hors catalogue (saisir son nom)
            </button>
        </div>
    </div>
</template>
