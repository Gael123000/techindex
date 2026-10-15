<script setup lang="ts">
import { computed } from 'vue';
import type { Option, RecordFieldDefinition, RecordValue } from '@/types';
import RichTextEditor from './RichTextEditor.vue';
import SelectInput from './SelectInput.vue';
import TextArea from './TextArea.vue';
import TextInput from './TextInput.vue';

/**
 * Saisie d'un champ du constructeur de fiches, adaptée à son type.
 * Les cases à cocher sont rendues par RecordFieldsForm (libellé à droite).
 */
const model = defineModel<RecordValue>();
const props = defineProps<{
    id: string;
    field: RecordFieldDefinition;
    references?: Record<string, Option<number>[]>;
    users?: Option<number>[];
    invalid?: boolean;
    describedBy?: string;
}>();

const inputTypes: Record<string, string> = {
    email: 'email',
    url: 'url',
    phone: 'tel',
    integer: 'number',
    decimal: 'number',
    currency: 'number',
    date: 'date',
    datetime: 'datetime-local',
};

const steps: Record<string, string> = { integer: '1', decimal: '0.0001', currency: '0.01' };

// Exemples affichés selon le type, si l'administrateur n'a pas défini de texte d'exemple.
const EXAMPLES: Record<string, string> = { url: 'https://', ip: '192.168.10.1', cidr: '192.168.10.0/24', email: 'nom@exemple.fr' };

const placeholder = computed(() => props.field.options.placeholder ?? EXAMPLES[props.field.type]);
const choices = computed<Option[]>(() => (props.field.options.choices ?? []).map((choice) => ({ value: choice, label: choice })));
const referenceOptions = computed<Option<number>[]>(() => props.references?.[props.field.options.target ?? ''] ?? []);

const text = computed<string | number | null>({
    get: () => (typeof model.value === 'string' || typeof model.value === 'number' ? model.value : null),
    set: (value) => (model.value = value === '' ? null : value),
});

const str = computed<string | null>({
    get: () => (typeof model.value === 'string' ? model.value : null),
    set: (value) => (model.value = value === '' ? null : value),
});

const num = computed<number | null>({
    get: () => (typeof model.value === 'number' ? model.value : null),
    set: (value) => (model.value = value),
});

const html = computed<string>({
    get: () => (typeof model.value === 'string' ? model.value : ''),
    set: (value) => (model.value = value),
});

const selected = computed<string[]>(() => (Array.isArray(model.value) ? model.value : []));

function toggle(choice: string, checked: boolean): void {
    const next = checked ? [...new Set([...selected.value, choice])] : selected.value.filter((item) => item !== choice);
    model.value = (props.field.options.choices ?? []).filter((item) => next.includes(item));
}

const numeric = computed(() => ['integer', 'decimal', 'currency'].includes(props.field.type));
const unit = computed(() => (props.field.type === 'currency' ? '€' : (props.field.options.unit ?? '')));
</script>

<template>
    <TextArea
        v-if="field.type === 'textarea'"
        :id="id"
        v-model="str"
        :rows="field.options.rows ?? 4"
        :placeholder="placeholder"
        :invalid="invalid"
        :described-by="describedBy"
    />

    <RichTextEditor v-else-if="field.type === 'richtext'" :id="id" v-model="html" :invalid="invalid" :described-by="describedBy" />

    <fieldset v-else-if="field.type === 'select' && field.options.display === 'radio'" :id="id" class="flex flex-wrap gap-x-4 gap-y-1.5 pt-1" :aria-describedby="describedBy">
        <legend class="sr-only">{{ field.label }}</legend>
        <label v-for="choice in field.options.choices ?? []" :key="choice" class="flex items-center gap-2 text-sm">
            <input v-model="model" type="radio" :name="id" :value="choice" class="h-4 w-4 border-slate-300 focus:ring-turquoise-600" />
            {{ choice }}
        </label>
        <button v-if="model !== null && !field.is_required" type="button" class="text-xs text-slate-500 underline" @click="model = null">Effacer</button>
    </fieldset>

    <SelectInput
        v-else-if="field.type === 'select'"
        :id="id"
        v-model="str"
        :options="choices"
        placeholder="— Choisir —"
        :invalid="invalid"
        :described-by="describedBy"
    />

    <fieldset v-else-if="field.type === 'multiselect'" :id="id" class="flex flex-wrap gap-2 pt-1" :aria-describedby="describedBy">
        <legend class="sr-only">{{ field.label }}</legend>
        <label
            v-for="choice in field.options.choices ?? []"
            :key="choice"
            class="flex cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1 text-sm"
            :class="selected.includes(choice) ? 'border-turquoise-500 bg-turquoise-50 text-turquoise-900' : 'border-slate-300 text-slate-700 hover:bg-slate-50'"
        >
            <input type="checkbox" class="sr-only" :checked="selected.includes(choice)" @change="toggle(choice, ($event.target as HTMLInputElement).checked)" />
            {{ choice }}
        </label>
    </fieldset>

    <SelectInput
        v-else-if="field.type === 'reference'"
        :id="id"
        v-model="num"
        :options="referenceOptions"
        :placeholder="referenceOptions.length ? '— Aucun —' : '— Aucun élément disponible —'"
        :invalid="invalid"
        :described-by="describedBy"
    />

    <SelectInput v-else-if="field.type === 'user'" :id="id" v-model="num" :options="users ?? []" placeholder="— Aucun —" :invalid="invalid" :described-by="describedBy" />

    <div v-else-if="numeric" class="relative">
        <input
            :id="id"
            v-model="text"
            type="number"
            :name="id"
            :step="steps[field.type]"
            :min="field.options.min"
            :max="field.options.max"
            :placeholder="placeholder"
            inputmode="decimal"
            class="form-input"
            :class="[invalid ? 'border-red-500 focus:border-red-600 focus:ring-red-600' : '', unit ? 'pr-12' : '']"
            :aria-invalid="invalid ? 'true' : undefined"
            :aria-describedby="describedBy"
        />
        <span v-if="unit" class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-slate-500">{{ unit }}</span>
    </div>

    <TextInput
        v-else
        :id="id"
        v-model="text"
        :type="inputTypes[field.type] ?? 'text'"
        :placeholder="placeholder"
        :maxlength="field.type === 'text' ? (field.options.max_length ?? 255) : undefined"
        :inputmode="field.type === 'ip' ? 'decimal' : undefined"
        :invalid="invalid"
        :described-by="describedBy"
    />
</template>
