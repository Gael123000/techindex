<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Sélection multiple filtrable (équipements, services liés...).
 */
const model = defineModel<number[]>({ required: true });
const props = defineProps<{ id: string; options: Array<{ value: number; label: string }>; emptyText?: string }>();

const filter = ref('');
const visible = computed(() => {
    const term = filter.value.trim().toLowerCase();

    return term === '' ? props.options : props.options.filter((option) => option.label.toLowerCase().includes(term));
});

function toggle(value: number, checked: boolean): void {
    model.value = checked ? [...new Set([...model.value, value])] : model.value.filter((item) => item !== value);
}
</script>

<template>
    <div class="rounded-md border border-slate-300 bg-white">
        <div class="border-b border-slate-200 p-2">
            <input
                v-model="filter"
                type="search"
                class="form-input py-1.5"
                :aria-label="`Filtrer la liste`"
                :placeholder="`Filtrer (${options.length} élément(s), ${model.length} sélectionné(s))`"
            />
        </div>
        <fieldset :id="id" class="max-h-56 overflow-y-auto p-2">
            <legend class="sr-only">Sélection</legend>
            <p v-if="options.length === 0" class="px-1 py-2 text-sm text-slate-500">{{ emptyText ?? 'Aucun élément disponible.' }}</p>
            <label v-for="option in visible" :key="option.value" class="flex items-center gap-2 rounded px-1 py-1 text-sm hover:bg-slate-50">
                <input
                    type="checkbox"
                    class="h-4 w-4 rounded border-slate-300"
                    :checked="model.includes(option.value)"
                    @change="toggle(option.value, ($event.target as HTMLInputElement).checked)"
                />
                {{ option.label }}
            </label>
        </fieldset>
    </div>
</template>
