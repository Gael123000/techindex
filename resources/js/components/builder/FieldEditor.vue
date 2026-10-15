<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Database, Trash2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import CheckboxInput from '@/components/CheckboxInput.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DynamicIcon from '@/components/DynamicIcon.vue';
import FormField from '@/components/FormField.vue';
import SelectInput from '@/components/SelectInput.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import { CONDITION_OPERATORS, suggestIdentifier } from '@/lib/records';
import type { ConditionSource, FieldTypeDefinition, Option, RecordFieldCondition, RecordFieldDefinition, RecordSection } from '@/types';

/**
 * Propriétés d'un champ du constructeur (ajout ou modification). Chaque
 * enregistrement modifie immédiatement la structure de la table.
 */

const props = defineProps<{
    recordTypeId: number;
    tableName: string;
    field: RecordFieldDefinition | null;
    newType: string;
    newSection: string;
    sections: RecordSection[];
    fields: RecordFieldDefinition[];
    fieldTypes: FieldTypeDefinition[];
    referenceTargets: Option[];
    conditionSources: Record<string, ConditionSource>;
    reservedColumns: string[];
}>();

const emit = defineEmits<{ close: []; saved: [] }>();

const widthOptions: Option[] = [
    { value: '1', label: 'Une colonne' },
    { value: '2', label: 'Deux colonnes' },
    { value: 'full', label: 'Toute la largeur' },
];

const typeOptions = computed<Option[]>(() => props.fieldTypes.map((type) => ({ value: type.value, label: type.label })));
const sectionOptions = computed<Option[]>(() => props.sections.map((section) => ({ value: section.key, label: section.title || 'Section sans titre' })));

type OptionValue = string | number | boolean | null | string[];

const form = useForm({
    label: '',
    key: '',
    type: 'text',
    help_text: '',
    is_required: false,
    is_unique: false,
    in_list: false,
    is_searchable: false,
    section: '',
    width: '1' as string,
    options: {} as Record<string, OptionValue>,
    visibility: null as RecordFieldCondition | null,
});

const keyEdited = ref(false);
const choicesText = ref('');
const conditionValueText = ref('');
const confirmDelete = ref(false);

function reset(): void {
    const f = props.field;
    form.clearErrors();
    form.label = f?.label ?? '';
    form.key = f?.key ?? '';
    form.type = f?.type ?? props.newType;
    form.help_text = f?.help_text ?? '';
    form.is_required = f?.is_required ?? false;
    form.is_unique = f?.is_unique ?? false;
    form.in_list = f?.in_list ?? false;
    form.is_searchable = f?.is_searchable ?? ['text', 'textarea', 'select', 'email', 'phone', 'ip'].includes(props.newType);
    form.section = f?.section ?? props.newSection;
    form.width = f?.width ?? (['textarea', 'richtext', 'multiselect'].includes(props.newType) ? 'full' : '1');
    form.options = { ...((f?.options ?? {}) as Record<string, OptionValue>) };
    form.visibility = f?.visibility ? { ...f.visibility, value: [...(f.visibility.value ?? [])] } : null;
    choicesText.value = ((f?.options.choices as string[] | undefined) ?? []).join('\n');
    conditionValueText.value = (f?.visibility?.value ?? []).join(', ');
    keyEdited.value = false;
}

watch(() => [props.field?.id, props.newType, props.newSection], reset, { immediate: true });

// Nom technique proposé à partir du libellé, tant qu'il n'a pas été saisi.
watch(
    () => form.label,
    (label) => {
        if (!props.field && !keyEdited.value) {
            form.key = suggestIdentifier(label);
        }
    },
);

const typeDefinition = computed(() => props.fieldTypes.find((type) => type.value === form.type));
const isNumeric = computed(() => ['integer', 'decimal', 'currency'].includes(form.type));
const hasChoices = computed(() => ['select', 'multiselect'].includes(form.type));
const canBeUnique = computed(() => !['boolean', 'multiselect', 'textarea', 'richtext'].includes(form.type));
const canBeListed = computed(() => !['textarea', 'richtext'].includes(form.type));
const choices = computed(() => choicesText.value.split('\n').map((line) => line.trim()).filter(Boolean));
const keyConflict = computed(() => !props.field && (props.reservedColumns.includes(form.key) || props.fields.some((f) => f.key === form.key)));

const typeChanged = computed(() => props.field !== null && props.field.type !== form.type);

// Sources possibles d'une condition : autres champs de la fiche, puis champs d'origine.
const conditionFields = computed(() => props.fields.filter((f) => f.key !== props.field?.key));
const conditionSourceOptions = computed<Option[]>(() => [
    ...Object.entries(props.conditionSources).map(([value, source]) => ({ value, label: `${source.label} (champ d'origine)` })),
    ...conditionFields.value.map((f) => ({ value: f.key, label: f.label })),
]);

const conditionChoices = computed<Option[] | null>(() => {
    const source = form.visibility?.field;

    if (!source) {
        return null;
    }

    if (props.conditionSources[source]) {
        return props.conditionSources[source].options;
    }

    const field = props.fields.find((f) => f.key === source);

    return field && (field.type === 'select' || field.type === 'multiselect') ? (field.options.choices ?? []).map((choice) => ({ value: choice, label: choice })) : null;
});

const sourceIsBoolean = computed(() => props.fields.find((f) => f.key === form.visibility?.field)?.type === 'boolean');
const operatorOptions = computed<Option[]>(() =>
    CONDITION_OPERATORS.filter((operator) => (sourceIsBoolean.value ? ['checked', 'unchecked'].includes(operator.value) : !['checked', 'unchecked'].includes(operator.value))).map(
        (operator) => ({ value: operator.value, label: operator.label }),
    ),
);
const operatorNeedsValue = computed(() => CONDITION_OPERATORS.find((operator) => operator.value === form.visibility?.operator)?.needsValue ?? false);

function toggleCondition(enabled: boolean): void {
    form.visibility = enabled ? { field: conditionSourceOptions.value[0]?.value ?? '', operator: 'equals', value: [] } : null;
    conditionValueText.value = '';
}

watch(
    () => form.visibility?.field,
    () => {
        if (form.visibility && sourceIsBoolean.value && !['checked', 'unchecked'].includes(form.visibility.operator)) {
            form.visibility.operator = 'checked';
        }
    },
);

function toggleConditionValue(value: string, checked: boolean): void {
    if (!form.visibility) {
        return;
    }

    const current = form.visibility.value ?? [];
    form.visibility.value = checked ? [...new Set([...current, value])] : current.filter((item) => item !== value);
}

function submit(): void {
    form.transform((data) => {
        const options: Record<string, OptionValue> = { ...data.options };

        if (hasChoices.value) {
            options.choices = choices.value;
        }

        const visibility = data.visibility
            ? {
                  ...data.visibility,
                  value: conditionChoices.value
                      ? data.visibility.value
                      : conditionValueText.value.split(',').map((item) => item.trim()).filter(Boolean),
              }
            : null;

        const result: Record<string, unknown> = { ...data, options, visibility };

        if (props.field) {
            delete result.key;
        }

        return result;
    });

    const options = { preserveScroll: true, preserveState: true, onSuccess: () => emit('saved') };

    if (props.field) {
        form.put(route('admin.record-types.fields.update', [props.recordTypeId, props.field.id]), options);
    } else {
        form.post(route('admin.record-types.fields.store', props.recordTypeId), options);
    }
}

function destroy(): void {
    if (!props.field) {
        return;
    }

    router.delete(route('admin.record-types.fields.destroy', [props.recordTypeId, props.field.id]), {
        data: { confirmation: props.field.label },
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('saved'),
        onFinish: () => (confirmDelete.value = false),
    });
}

function err(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

function optionString(name: string): string {
    const value = form.options[name];

    return value === undefined || value === null ? '' : String(value);
}

function setOption(name: string, value: OptionValue | undefined): void {
    form.options = { ...form.options, [name]: value === '' || value === undefined ? null : value };
}
</script>

<template>
    <aside class="card flex flex-col" aria-labelledby="field-editor-title">
        <header class="flex items-start gap-3 border-b border-slate-200 px-4 py-3">
            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-turquoise-50 text-turquoise-700">
                <DynamicIcon :name="typeDefinition?.icon" class="h-4 w-4" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 id="field-editor-title" class="truncate text-sm font-semibold text-navy-900">{{ field ? `Champ « ${field.label} »` : 'Nouveau champ' }}</h2>
                <p class="text-xs text-slate-500">{{ typeDefinition?.description }}</p>
            </div>
            <button type="button" class="btn-ghost btn-sm" @click="emit('close')"><X class="h-4 w-4" aria-hidden="true" /><span class="sr-only">Fermer</span></button>
        </header>

        <form class="space-y-4 overflow-y-auto p-4" novalidate @submit.prevent="submit">
            <FormField id="fe_label" label="Libellé" :error="form.errors.label" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="fe_label" v-model="form.label" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>

            <div>
                <p class="mb-1 flex items-center gap-1.5 text-sm font-medium text-slate-700"><Database class="h-3.5 w-3.5" aria-hidden="true" /> Stockage</p>
                <div v-if="field" class="rounded-md bg-slate-50 px-3 py-2 font-mono text-xs text-slate-600">
                    {{ tableName }}.{{ field.column_name }} <span class="text-slate-400">· {{ typeDefinition?.sql }}</span>
                </div>
                <template v-else>
                    <div class="flex items-stretch">
                        <span class="inline-flex items-center rounded-l-md border border-r-0 border-slate-300 bg-slate-50 px-2 font-mono text-xs text-slate-500">{{ tableName }}.</span>
                        <input
                            id="fe_key"
                            v-model="form.key"
                            class="form-input rounded-l-none font-mono text-sm"
                            :class="form.errors.key || keyConflict ? 'border-red-500' : ''"
                            maxlength="40"
                            spellcheck="false"
                            aria-label="Nom de la colonne"
                            aria-describedby="fe_key_hint"
                            @input="keyEdited = true"
                        />
                    </div>
                    <p id="fe_key_hint" class="mt-1 text-xs" :class="form.errors.key || keyConflict ? 'font-medium text-red-700' : 'text-slate-500'">
                        {{ form.errors.key ?? (keyConflict ? 'Nom déjà utilisé ou réservé.' : `Colonne ${typeDefinition?.sql} créée à l'enregistrement ; nom figé ensuite.`) }}
                    </p>
                </template>
            </div>

            <FormField id="fe_type" label="Type de valeur" :error="form.errors.type" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="fe_type" v-model="form.type" :options="typeOptions" :described-by="describedBy" :invalid="invalid" />
                    <p v-if="typeChanged" class="mt-1 text-xs text-amber-800">
                        La colonne sera convertie. Si des valeurs déjà saisies risquent d'être perdues, le changement sera refusé.
                    </p>
                </template>
            </FormField>

            <!-- Options propres au type -->
            <FormField v-if="hasChoices" id="fe_choices" label="Choix possibles (un par ligne)" :error="err('options.choices') ?? err('options.choices.0')" required>
                <template #default="{ describedBy, invalid }">
                    <TextArea id="fe_choices" v-model="choicesText" :rows="5" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <div v-if="form.type === 'select'" class="grid grid-cols-2 gap-3">
                <FormField id="fe_display" label="Présentation">
                    <template #default="{ describedBy }">
                        <SelectInput
                            id="fe_display"
                            :model-value="optionString('display') || 'select'"
                            :options="[{ value: 'select', label: 'Liste déroulante' }, { value: 'radio', label: 'Boutons' }]"
                            :described-by="describedBy"
                            @update:model-value="setOption('display', $event)"
                        />
                    </template>
                </FormField>
                <FormField id="fe_default_choice" label="Valeur par défaut" :error="err('options.default')">
                    <template #default="{ describedBy, invalid }">
                        <SelectInput
                            id="fe_default_choice"
                            :model-value="optionString('default') || null"
                            :options="choices.map((choice) => ({ value: choice, label: choice }))"
                            placeholder="— Aucune —"
                            :described-by="describedBy"
                            :invalid="invalid"
                            @update:model-value="setOption('default', $event ?? null)"
                        />
                    </template>
                </FormField>
            </div>

            <FormField v-if="form.type === 'reference'" id="fe_target" label="Fiche visée par le lien" :error="err('options.target')" hint="Seuls les éléments du même client sont proposés." required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput
                        id="fe_target"
                        :model-value="optionString('target') || null"
                        :options="referenceTargets"
                        placeholder="— Choisir —"
                        :described-by="describedBy"
                        :invalid="invalid"
                        @update:model-value="setOption('target', $event ?? null)"
                    />
                </template>
            </FormField>

            <div v-if="isNumeric" class="grid grid-cols-2 gap-3">
                <FormField id="fe_min" label="Minimum" :error="err('options.min')">
                    <template #default="{ describedBy, invalid }">
                        <TextInput id="fe_min" :model-value="optionString('min')" type="number" :described-by="describedBy" :invalid="invalid" @update:model-value="setOption('min', $event)" />
                    </template>
                </FormField>
                <FormField id="fe_max" label="Maximum" :error="err('options.max')">
                    <template #default="{ describedBy, invalid }">
                        <TextInput id="fe_max" :model-value="optionString('max')" type="number" :described-by="describedBy" :invalid="invalid" @update:model-value="setOption('max', $event)" />
                    </template>
                </FormField>
                <FormField v-if="form.type !== 'currency'" id="fe_unit" label="Unité" :error="err('options.unit')" hint="Ex. Go, ports, kVA">
                    <template #default="{ describedBy, invalid }">
                        <TextInput id="fe_unit" :model-value="optionString('unit')" :described-by="describedBy" :invalid="invalid" @update:model-value="setOption('unit', $event)" />
                    </template>
                </FormField>
                <FormField id="fe_default_number" label="Valeur par défaut" :error="err('options.default')">
                    <template #default="{ describedBy, invalid }">
                        <TextInput id="fe_default_number" :model-value="optionString('default')" type="number" :described-by="describedBy" :invalid="invalid" @update:model-value="setOption('default', $event)" />
                    </template>
                </FormField>
            </div>

            <div v-if="form.type === 'text'" class="grid grid-cols-2 gap-3">
                <FormField id="fe_max_length" label="Longueur maximale" :error="err('options.max_length')">
                    <template #default="{ describedBy, invalid }">
                        <TextInput id="fe_max_length" :model-value="optionString('max_length')" type="number" :described-by="describedBy" :invalid="invalid" @update:model-value="setOption('max_length', $event)" />
                    </template>
                </FormField>
                <FormField id="fe_default_text" label="Valeur par défaut" :error="err('options.default')">
                    <template #default="{ describedBy, invalid }">
                        <TextInput id="fe_default_text" :model-value="optionString('default')" :described-by="describedBy" :invalid="invalid" @update:model-value="setOption('default', $event)" />
                    </template>
                </FormField>
            </div>

            <FormField v-if="form.type === 'textarea'" id="fe_rows" label="Hauteur (lignes)" :error="err('options.rows')">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="fe_rows" :model-value="optionString('rows')" type="number" :described-by="describedBy" :invalid="invalid" @update:model-value="setOption('rows', $event)" />
                </template>
            </FormField>

            <CheckboxInput
                v-if="form.type === 'boolean'"
                id="fe_default_bool"
                :model-value="form.options.default === true || form.options.default === '1'"
                label="Coché par défaut"
                @update:model-value="setOption('default', $event ? true : null)"
            />
            <CheckboxInput
                v-if="form.type === 'date'"
                id="fe_default_today"
                :model-value="form.options.default === 'today'"
                label="Date du jour par défaut"
                @update:model-value="setOption('default', $event ? 'today' : null)"
            />

            <FormField
                v-if="!['boolean', 'richtext', 'multiselect', 'date', 'datetime', 'reference', 'user', 'select'].includes(form.type)"
                id="fe_placeholder"
                label="Texte d'exemple"
                :error="err('options.placeholder')"
                hint="Affiché en gris dans le champ vide."
            >
                <template #default="{ describedBy, invalid }">
                    <TextInput id="fe_placeholder" :model-value="optionString('placeholder')" :described-by="describedBy" :invalid="invalid" @update:model-value="setOption('placeholder', $event)" />
                </template>
            </FormField>

            <FormField id="fe_help" label="Aide affichée sous le champ" :error="form.errors.help_text">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="fe_help" v-model="form.help_text" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>

            <fieldset class="space-y-2">
                <legend class="mb-1 text-sm font-medium text-slate-700">Comportement</legend>
                <CheckboxInput v-if="form.type !== 'boolean'" id="fe_required" v-model="form.is_required" label="Saisie obligatoire" :disabled="field?.is_title" />
                <CheckboxInput v-if="canBeUnique" id="fe_unique" v-model="form.is_unique" label="Valeur unique parmi les fiches du client" />
                <CheckboxInput v-if="canBeListed" id="fe_list" v-model="form.in_list" label="Colonne de la liste" description="Affichée, triable et exportée dans la liste des fiches." />
                <CheckboxInput id="fe_search" v-model="form.is_searchable" label="Inclus dans la recherche globale" />
            </fieldset>

            <div class="grid grid-cols-2 gap-3">
                <FormField id="fe_section" label="Section">
                    <template #default="{ describedBy }">
                        <SelectInput id="fe_section" v-model="form.section" :options="sectionOptions" :described-by="describedBy" />
                    </template>
                </FormField>
                <FormField id="fe_width" label="Largeur">
                    <template #default="{ describedBy }">
                        <SelectInput id="fe_width" v-model="form.width" :options="widthOptions" :described-by="describedBy" />
                    </template>
                </FormField>
            </div>

            <fieldset class="rounded-md border border-slate-200 p-3">
                <legend class="px-1 text-sm font-medium text-slate-700">Condition d'affichage</legend>
                <CheckboxInput
                    id="fe_condition"
                    :model-value="form.visibility !== null"
                    label="Afficher ce champ seulement si…"
                    :disabled="!conditionSourceOptions.length || field?.is_title"
                    @update:model-value="toggleCondition($event ?? false)"
                />
                <div v-if="form.visibility" class="mt-3 space-y-3">
                    <SelectInput id="fe_condition_field" v-model="form.visibility.field" :options="conditionSourceOptions" aria-label="Champ de la condition" />
                    <SelectInput id="fe_condition_operator" v-model="form.visibility.operator" :options="operatorOptions" aria-label="Opérateur" />
                    <template v-if="operatorNeedsValue">
                        <div v-if="conditionChoices" class="max-h-40 space-y-1 overflow-y-auto">
                            <label v-for="choice in conditionChoices" :key="choice.value" class="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-slate-300"
                                    :checked="(form.visibility.value ?? []).includes(String(choice.value))"
                                    @change="toggleConditionValue(String(choice.value), ($event.target as HTMLInputElement).checked)"
                                />
                                {{ choice.label }}
                            </label>
                        </div>
                        <TextInput v-else id="fe_condition_values" v-model="conditionValueText" placeholder="Valeurs séparées par des virgules" aria-label="Valeurs de la condition" />
                    </template>
                    <p v-if="err('visibility.field')" class="text-xs font-medium text-red-700" role="alert">{{ err('visibility.field') }}</p>
                </div>
            </fieldset>

            <p v-if="form.hasErrors && !Object.keys(form.errors).some((key) => ['label', 'key', 'type'].includes(key))" class="text-xs font-medium text-red-700" role="alert">
                Vérifiez les options du champ.
            </p>

            <div class="flex items-center gap-2 border-t border-slate-200 pt-4">
                <button v-if="field && !field.is_title" type="button" class="btn-ghost btn-sm text-red-700" @click="confirmDelete = true">
                    <Trash2 class="h-4 w-4" aria-hidden="true" /> Supprimer
                </button>
                <span class="flex-1" />
                <button type="button" class="btn-secondary" @click="emit('close')">Annuler</button>
                <button type="submit" class="btn-primary" :disabled="form.processing || keyConflict">{{ field ? 'Enregistrer' : 'Ajouter le champ' }}</button>
            </div>
        </form>
    </aside>

    <ConfirmDialog
        :show="confirmDelete"
        title="Supprimer le champ"
        :message="`La colonne ${field?.column_name} de la table ${tableName} sera supprimée avec toutes les valeurs saisies dans ce champ. Cette action est définitive.`"
        confirm-label="Supprimer le champ"
        danger
        :require-text="field?.label ?? null"
        @close="confirmDelete = false"
        @confirm="destroy"
    />
</template>
