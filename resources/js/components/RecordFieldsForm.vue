<script setup lang="ts">
import { computed } from 'vue';
import { fieldsOfSection, gridClass, isFieldVisible, spanClass } from '@/lib/records';
import type { RecordFormDefinition, RecordValue } from '@/types';
import CheckboxInput from './CheckboxInput.vue';
import FormField from './FormField.vue';
import RecordFieldInput from './RecordFieldInput.vue';

/**
 * Champs d'une fiche du constructeur, rangés par section selon la
 * disposition définie par l'administrateur. Les champs dont la condition
 * d'affichage n'est pas remplie sont masqués (et ignorés par le serveur).
 *
 * - variant « cards » : une carte par section (pages de formulaire) ;
 * - variant « embedded » : sections à l'intérieur d'un formulaire existant
 *   (fenêtres des contacts et sous-réseaux).
 */
const props = withDefaults(
    defineProps<{
        definition: RecordFormDefinition;
        values: Record<string, RecordValue>;
        errors?: Record<string, string | undefined>;
        builtin?: Record<string, unknown>;
        idPrefix?: string;
        variant?: 'cards' | 'embedded';
    }>(),
    { errors: () => ({}), builtin: () => ({}), idPrefix: 'field', variant: 'cards' },
);

const sections = computed(() =>
    props.definition.sections
        .map((section) => ({
            ...section,
            fields: fieldsOfSection(section, props.definition.sections, props.definition.fields).filter((field) => isFieldVisible(field, props.values, props.builtin)),
        }))
        .filter((section) => section.fields.length > 0),
);

function inputId(key: string): string {
    return `${props.idPrefix}-${key}`;
}

function errorFor(key: string): string | undefined {
    return props.errors[`fields.${key}`] ?? Object.entries(props.errors).find(([name]) => name.startsWith(`fields.${key}.`))?.[1];
}
</script>

<template>
    <component
        :is="variant === 'cards' ? 'section' : 'div'"
        v-for="section in sections"
        :key="section.key"
        :class="variant === 'cards' ? 'card' : 'sm:col-span-full'"
        :aria-label="section.title"
    >
        <header :class="variant === 'cards' ? 'border-b border-slate-200 px-5 py-3.5' : 'border-t border-slate-200 pt-4'">
            <h2 class="text-sm font-semibold text-navy-900">{{ section.title }}</h2>
            <p v-if="section.description" class="mt-0.5 text-xs whitespace-pre-line text-slate-500">{{ section.description }}</p>
        </header>
        <div class="grid grid-cols-1 gap-4" :class="[gridClass(section), variant === 'cards' ? 'p-5' : 'pt-3']">
            <div v-for="field in section.fields" :key="field.key" :class="spanClass(field.width, section.columns)">
                <div v-if="field.type === 'boolean'" class="pt-6">
                    <CheckboxInput
                        :id="inputId(field.key)"
                        :model-value="Boolean(values[field.key])"
                        :label="field.label"
                        :description="field.help_text ?? undefined"
                        @update:model-value="values[field.key] = $event ?? false"
                    />
                    <p v-if="errorFor(field.key)" class="mt-1 text-xs font-medium text-red-700" role="alert">{{ errorFor(field.key) }}</p>
                </div>
                <FormField
                    v-else
                    :id="inputId(field.key)"
                    :label="field.label"
                    :error="errorFor(field.key)"
                    :hint="field.help_text ?? undefined"
                    :required="field.is_required"
                >
                    <template #default="{ describedBy, invalid }">
                        <RecordFieldInput
                            :id="inputId(field.key)"
                            v-model="values[field.key]"
                            :field="field"
                            :references="definition.references"
                            :users="definition.users"
                            :invalid="invalid"
                            :described-by="describedBy"
                        />
                    </template>
                </FormField>
            </div>
        </div>
    </component>
</template>
