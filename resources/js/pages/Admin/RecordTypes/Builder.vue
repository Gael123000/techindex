<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Check, Database, Eye, LayoutGrid, Loader2, Lock, Settings2, Trash2 } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import FieldEditor from '@/components/builder/FieldEditor.vue';
import LayoutCanvas from '@/components/builder/LayoutCanvas.vue';
import CheckboxInput from '@/components/CheckboxInput.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DynamicIcon from '@/components/DynamicIcon.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordFieldsForm from '@/components/RecordFieldsForm.vue';
import SelectInput from '@/components/SelectInput.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { fieldsOfSection } from '@/lib/records';
import type { ConditionSource, FieldTypeDefinition, Option, RecordFieldDefinition, RecordSection, RecordValue } from '@/types';

defineOptions({ layout: AppLayout });

interface BuilderType {
    id: number;
    key: string;
    name: string;
    plural_name: string;
    description: string | null;
    icon: string;
    is_system: boolean;
    entity: string | null;
    is_active: boolean;
    table_name: string;
    tab_after: string | null;
    sort_order: number;
    sections: RecordSection[];
    settings: { has_site?: boolean; has_tags?: boolean; has_attachments?: boolean; sort_field?: string | null; sort_direction?: string };
    records_count: number | null;
    updated_at: string;
}

const props = defineProps<{
    recordType: BuilderType;
    fields: RecordFieldDefinition[];
    builtin: string[];
    conditionSources: Record<string, ConditionSource>;
    fieldTypes: FieldTypeDefinition[];
    referenceTargets: Option[];
    reservedColumns: string[];
    database: { table: string; exists: boolean; rows: number; columns: Array<{ name: string; type: string; nullable: boolean }> };
    icons: string[];
    tabs: Option[];
}>();

const TYPE = 'text/x-techindex-field-type';
const tab = ref<'form' | 'preview' | 'settings' | 'database'>('form');

/* ---------- Disposition (état local enregistré automatiquement) ---------- */

const sections = ref<RecordSection[]>([]);
const localFields = ref<RecordFieldDefinition[]>([]);
const saveState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
let saveTimer: number | undefined;

function sync(): void {
    sections.value = props.recordType.sections.map((section) => ({ ...section }));
    localFields.value = props.fields.map((field) => ({ ...field }));
}

watch(() => [props.recordType.sections, props.fields], sync, { immediate: true, deep: true });
onBeforeUnmount(() => window.clearTimeout(saveTimer));

function ordered(): RecordFieldDefinition[] {
    return sections.value.flatMap((section) => fieldsOfSection(section, sections.value, localFields.value));
}

function renumber(list: RecordFieldDefinition[]): void {
    list.forEach((field, index) => (field.sort_order = (index + 1) * 10));
}

function persist(): void {
    window.clearTimeout(saveTimer);
    saveState.value = 'saving';
    saveTimer = window.setTimeout(() => {
        router.put(
            route('admin.record-types.layout', props.recordType.id),
            {
                sections: sections.value.map((section) => ({ ...section })),
                fields: ordered().map((field) => ({ id: field.id, section: field.section ?? sections.value[0]?.key, width: field.width })),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => (saveState.value = 'saved'),
                onError: () => (saveState.value = 'error'),
            },
        );
    }, 400);
}

function moveField(id: number, section: string, beforeId: number | null): void {
    const moved = localFields.value.find((field) => field.id === id);

    if (!moved) {
        return;
    }

    const lists = new Map(sections.value.map((item) => [item.key, fieldsOfSection(item, sections.value, localFields.value).filter((field) => field.id !== id)]));
    const target = lists.get(section) ?? [];
    const index = beforeId === null ? -1 : target.findIndex((field) => field.id === beforeId);
    target.splice(index === -1 ? target.length : index, 0, moved);
    moved.section = section;
    renumber([...lists.values()].flat());
    persist();
}

function setWidth(id: number, width: string): void {
    const field = localFields.value.find((item) => item.id === id);

    if (field) {
        field.width = width as RecordFieldDefinition['width'];
        persist();
    }
}

function updateSection(index: number, patch: Partial<RecordSection>): void {
    const section = sections.value[index];

    if (section) {
        Object.assign(section, patch);
        persist();
    }
}

function moveSection(index: number, delta: number): void {
    const target = index + delta;

    if (target < 0 || target >= sections.value.length) {
        return;
    }

    const list = [...sections.value];
    [list[index], list[target]] = [list[target], list[index]];
    sections.value = list;
    renumber(ordered());
    persist();
}

function removeSection(index: number): void {
    const removed = sections.value[index];
    const fallback = sections.value.find((_, i) => i !== index);

    if (!removed || !fallback) {
        return;
    }

    localFields.value.filter((field) => field.section === removed.key).forEach((field) => (field.section = fallback.key));
    sections.value = sections.value.filter((_, i) => i !== index);
    renumber(ordered());
    persist();
}

function addSection(): void {
    sections.value = [...sections.value, { key: `s${Date.now().toString(36)}`, title: 'Nouvelle section', description: null, columns: 2 }];
    persist();
}

/* ---------- Panneau de propriétés ---------- */

const editor = ref<{ field: RecordFieldDefinition | null; type: string; section: string } | null>(null);

function editField(field: RecordFieldDefinition): void {
    editor.value = { field, type: field.type, section: field.section ?? sections.value[0]?.key ?? '' };
}

function newField(type: string, section?: string): void {
    window.clearTimeout(saveTimer);
    editor.value = { field: null, type, section: section ?? sections.value[0]?.key ?? '' };
}

function onPaletteDragStart(event: DragEvent, type: string): void {
    event.dataTransfer?.setData(TYPE, type);

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'copy';
    }
}

// Après un enregistrement, le champ affiché reprend les valeurs du serveur.
watch(
    () => props.fields,
    (fields) => {
        if (editor.value?.field) {
            const fresh = fields.find((field) => field.id === editor.value?.field?.id);
            editor.value = fresh ? { ...editor.value, field: fresh } : null;
        }
    },
);

/* ---------- Aperçu ---------- */

const previewValues = reactive<Record<string, RecordValue>>({});

watch(
    () => props.fields,
    (fields) => {
        for (const field of fields) {
            if (!(field.key in previewValues)) {
                previewValues[field.key] = field.type === 'boolean' ? Boolean(field.options.default) : field.type === 'multiselect' ? [] : ((field.options.default as RecordValue) ?? null);
            }
        }
    },
    { immediate: true },
);

const previewDefinition = computed(() => ({
    sections: sections.value,
    fields: localFields.value,
    references: Object.fromEntries(props.referenceTargets.map((target) => [target.value, [{ value: 1, label: `${target.label} (exemple)` }]])),
    users: [{ value: 1, label: 'Utilisateur (exemple)' }],
}));
const listedFields = computed(() => ordered().filter((field) => field.in_list && !field.is_title));
const titleField = computed(() => localFields.value.find((field) => field.is_title));

/* ---------- Réglages (fiches créées) ---------- */

const settings = useForm({
    name: props.recordType.name,
    plural_name: props.recordType.plural_name,
    description: props.recordType.description ?? '',
    icon: props.recordType.icon,
    tab_after: props.recordType.tab_after ?? 'documents',
    is_active: props.recordType.is_active,
    settings: {
        has_site: props.recordType.settings.has_site ?? true,
        has_tags: props.recordType.settings.has_tags ?? true,
        has_attachments: props.recordType.settings.has_attachments ?? true,
        sort_field: props.recordType.settings.sort_field ?? 'title',
        sort_direction: props.recordType.settings.sort_direction ?? 'asc',
    },
});

const sortOptions = computed<Option[]>(() => [
    { value: 'title', label: titleField.value?.label ?? 'Titre' },
    ...listedFields.value.map((field) => ({ value: field.key, label: field.label })),
    { value: 'updated_at', label: 'Date de modification' },
    { value: 'created_at', label: 'Date de création' },
]);

function saveSettings(): void {
    settings.put(route('admin.record-types.update', props.recordType.id), { preserveScroll: true });
}

const confirmDelete = ref(false);

function destroyType(): void {
    router.delete(route('admin.record-types.destroy', props.recordType.id), {
        data: { confirmation: props.recordType.name },
        onFinish: () => (confirmDelete.value = false),
    });
}

const columnLabels = computed(() => Object.fromEntries(props.fields.map((field) => [field.column_name, field.label])));
</script>

<template>
    <PageHeader
        :title="recordType.is_system ? `Fiche par défaut : ${recordType.plural_name}` : recordType.plural_name"
        :description="recordType.is_system ? 'Ajoutez des champs et des sections à cette fiche ; ses champs d\'origine restent inchangés.' : (recordType.description ?? 'Composez la fiche : champs, sections, colonnes et conditions d\'affichage.')"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Fiches et champs', href: route('admin.record-types.index') }, { label: recordType.plural_name }]"
    >
        <template #actions>
            <span class="inline-flex items-center gap-1.5 text-xs text-slate-500" role="status" aria-live="polite">
                <template v-if="saveState === 'saving'"><Loader2 class="h-3.5 w-3.5 animate-spin" aria-hidden="true" /> Enregistrement de la disposition…</template>
                <template v-else-if="saveState === 'saved'"><Check class="h-3.5 w-3.5 text-green-700" aria-hidden="true" /> Disposition enregistrée</template>
                <template v-else-if="saveState === 'error'"><span class="text-red-700">Disposition non enregistrée</span></template>
            </span>
            <button v-if="!recordType.is_system" type="button" class="btn-ghost text-red-700 hover:bg-red-50" @click="confirmDelete = true">
                <Trash2 class="h-4 w-4" aria-hidden="true" /> Supprimer la fiche
            </button>
        </template>
    </PageHeader>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-xs">
        <Badge :color="recordType.is_system ? 'slate' : 'teal'"><DynamicIcon :name="recordType.icon" class="h-3 w-3" /> {{ recordType.is_system ? 'Fiche par défaut' : 'Fiche créée' }}</Badge>
        <Badge color="navy"><Database class="h-3 w-3" aria-hidden="true" /> {{ recordType.table_name }}</Badge>
        <Badge v-if="!recordType.is_active" color="amber">Désactivée : onglet masqué</Badge>
        <span v-if="recordType.records_count !== null" class="text-slate-500">{{ recordType.records_count }} enregistrement(s)</span>
    </div>

    <nav class="mb-4 flex gap-1 border-b border-slate-200" aria-label="Sections du constructeur">
        <button
            v-for="item in [
                { key: 'form', label: 'Formulaire', icon: LayoutGrid },
                { key: 'preview', label: 'Aperçu', icon: Eye },
                { key: 'settings', label: 'Réglages', icon: Settings2 },
                { key: 'database', label: 'Base de données', icon: Database },
            ] as const"
            :key="item.key"
            type="button"
            class="inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm"
            :class="tab === item.key ? 'border-turquoise-600 font-semibold text-navy-900' : 'border-transparent text-slate-600 hover:text-navy-900'"
            :aria-current="tab === item.key ? 'page' : undefined"
            @click="tab = item.key"
        >
            <component :is="item.icon" class="h-4 w-4" aria-hidden="true" /> {{ item.label }}
        </button>
    </nav>

    <!-- Formulaire : palette, disposition et propriétés -->
    <div
        v-show="tab === 'form'"
        class="grid grid-cols-1 gap-4 lg:grid-cols-[13rem_minmax(0,1fr)]"
        :class="editor ? 'xl:grid-cols-[13rem_minmax(0,1fr)_22rem]' : ''"
    >
        <aside class="lg:sticky lg:top-20 lg:self-start" aria-labelledby="palette-title">
            <h2 id="palette-title" class="mb-2 text-xs font-semibold tracking-wider text-slate-500 uppercase">Ajouter un champ</h2>
            <ul class="grid grid-cols-2 gap-1.5 lg:grid-cols-1">
                <li v-for="type in fieldTypes" :key="type.value">
                    <button
                        type="button"
                        draggable="true"
                        class="flex w-full items-center gap-2 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-left text-sm text-slate-700 hover:border-turquoise-400 hover:bg-turquoise-50"
                        :title="type.description"
                        @click="newField(type.value)"
                        @dragstart="onPaletteDragStart($event, type.value)"
                    >
                        <DynamicIcon :name="type.icon" class="h-4 w-4 shrink-0 text-slate-400" />
                        <span class="truncate">{{ type.label }}</span>
                    </button>
                </li>
            </ul>
            <div v-if="!editor" class="mt-4 rounded-md border border-slate-200 bg-white p-3 text-xs text-slate-600">
                <p class="font-medium text-navy-900">Comment ça marche</p>
                <ul class="mt-1.5 list-disc space-y-1 pl-4">
                    <li>Cliquez sur un type de champ, ou glissez-le dans une section.</li>
                    <li>Chaque champ crée une colonne dans <span class="font-mono break-all">{{ recordType.table_name }}</span>.</li>
                    <li>Glissez les champs pour les réorganiser ; réglez les colonnes de chaque section et la largeur des champs.</li>
                    <li>Un champ peut n'apparaître que selon la valeur d'un autre.</li>
                    <li>Cliquez sur un champ pour modifier ses propriétés.</li>
                </ul>
            </div>
        </aside>

        <LayoutCanvas
            :sections="sections"
            :fields="localFields"
            :field-types="fieldTypes"
            :selected-id="editor?.field?.id ?? null"
            :builtin="builtin"
            :system-name="recordType.is_system ? recordType.plural_name : null"
            @select="editField"
            @add="newField"
            @move="moveField"
            @width="setWidth"
            @section-update="updateSection"
            @section-move="moveSection"
            @section-remove="removeSection"
            @section-add="addSection"
        />

        <div v-if="editor" class="lg:col-span-2 xl:col-span-1">
            <div class="xl:sticky xl:top-20">
                <FieldEditor
                    :record-type-id="recordType.id"
                    :table-name="recordType.table_name"
                    :field="editor.field"
                    :new-type="editor.type"
                    :new-section="editor.section"
                    :sections="sections"
                    :fields="localFields"
                    :field-types="fieldTypes"
                    :reference-targets="referenceTargets"
                    :condition-sources="conditionSources"
                    :reserved-columns="reservedColumns"
                    class="max-h-[calc(100vh-6rem)]"
                    @close="editor = null"
                    @saved="editor = editor?.field ? editor : null"
                />
            </div>
        </div>
    </div>

    <!-- Aperçu en direct -->
    <div v-if="tab === 'preview'" class="space-y-5">
        <p class="text-sm text-slate-600">Formulaire tel que le verront les techniciens. Les listes de liens contiennent ici des exemples.</p>
        <section v-if="recordType.is_system" class="card border-dashed p-4 text-sm text-slate-500">
            <Lock class="mr-1 inline h-4 w-4" aria-hidden="true" /> Champs d'origine de la fiche « {{ recordType.plural_name }} » ({{ builtin.length }})
        </section>
        <RecordFieldsForm v-if="localFields.length" :definition="previewDefinition" :values="previewValues" id-prefix="preview" />
        <p v-else class="card p-5 text-sm text-slate-500">Aucun champ ajouté pour le moment.</p>

        <section v-if="!recordType.is_system" class="card" aria-label="Aperçu de la liste">
            <header class="border-b border-slate-200 px-4 py-3"><h2 class="text-sm font-semibold text-navy-900">Colonnes de la liste</h2></header>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th scope="col">{{ titleField?.label ?? recordType.name }}</th>
                            <th v-for="field in listedFields" :key="field.key" scope="col">{{ field.label }}</th>
                            <th scope="col">Modifié</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </section>
    </div>

    <!-- Réglages -->
    <div v-if="tab === 'settings'">
        <div v-if="recordType.is_system" class="card p-5 text-sm text-slate-600">
            Fiche par défaut de TechIndex : son nom, son onglet et ses champs d'origine ne sont pas modifiables. Vous pouvez lui ajouter des champs et des sections dans l'onglet « Formulaire ».
        </div>
        <form v-else class="card grid grid-cols-1 gap-4 p-5 sm:grid-cols-2" novalidate @submit.prevent="saveSettings">
            <FormField id="st_name" label="Nom d'un élément" :error="settings.errors.name" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="st_name" v-model="settings.name" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="st_plural" label="Nom de l'onglet" :error="settings.errors.plural_name" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="st_plural" v-model="settings.plural_name" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="st_description" label="Description" :error="settings.errors.description" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="st_description" v-model="settings.description" :rows="2" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="st_tab" label="Onglet placé après" :error="settings.errors.tab_after">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="st_tab" v-model="settings.tab_after" :options="tabs" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <div class="grid grid-cols-2 gap-3">
                <FormField id="st_sort" label="Tri de la liste" :error="settings.errors['settings.sort_field']">
                    <template #default="{ describedBy, invalid }">
                        <SelectInput id="st_sort" v-model="settings.settings.sort_field" :options="sortOptions" :described-by="describedBy" :invalid="invalid" />
                    </template>
                </FormField>
                <FormField id="st_direction" label="Ordre">
                    <template #default="{ describedBy }">
                        <SelectInput
                            id="st_direction"
                            v-model="settings.settings.sort_direction"
                            :options="[{ value: 'asc', label: 'Croissant' }, { value: 'desc', label: 'Décroissant' }]"
                            :described-by="describedBy"
                        />
                    </template>
                </FormField>
            </div>
            <fieldset class="sm:col-span-2">
                <legend class="mb-1 text-sm font-medium text-slate-700">Icône</legend>
                <div class="flex flex-wrap gap-1.5">
                    <label
                        v-for="icon in icons"
                        :key="icon"
                        class="flex h-9 w-9 cursor-pointer items-center justify-center rounded-md border"
                        :class="settings.icon === icon ? 'border-turquoise-600 bg-turquoise-50 text-turquoise-800' : 'border-slate-200 text-slate-600 hover:bg-slate-50'"
                        :title="icon"
                    >
                        <input v-model="settings.icon" type="radio" name="st_icon" :value="icon" class="sr-only" />
                        <DynamicIcon :name="icon" class="h-4 w-4" />
                        <span class="sr-only">{{ icon }}</span>
                    </label>
                </div>
            </fieldset>
            <div class="space-y-2 sm:col-span-2">
                <CheckboxInput id="st_active" v-model="settings.is_active" label="Fiche active (onglet affiché dans chaque client)" />
                <CheckboxInput id="st_site" v-model="settings.settings.has_site" label="Rattachement à un site du client" />
                <CheckboxInput id="st_tags" v-model="settings.settings.has_tags" label="Étiquettes" />
                <CheckboxInput id="st_files" v-model="settings.settings.has_attachments" label="Pièces jointes" />
            </div>
            <div class="flex justify-end sm:col-span-2">
                <button type="submit" class="btn-primary" :disabled="settings.processing">Enregistrer les réglages</button>
            </div>
        </form>
    </div>

    <!-- Base de données -->
    <div v-if="tab === 'database'" class="space-y-4">
        <div class="card p-5 text-sm text-slate-700">
            <p>
                Les données de cette fiche sont stockées dans la table <span class="font-mono font-semibold">{{ database.table }}</span>
                <template v-if="database.exists"> ({{ database.rows }} ligne(s)).</template>
                <template v-else> : elle sera créée à l'ajout du premier champ.</template>
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Chaque champ correspond à une colonne typée. La colonne <span class="font-mono">record_id</span> relie chaque ligne à
                {{ recordType.is_system ? `l'élément de la fiche « ${recordType.plural_name} »` : 'son enregistrement (identifiant unique, client, site, historique, étiquettes et pièces jointes)' }}
                et la supprime avec lui. Les secrets ne sont jamais stockés ici : ils restent chiffrés dans les accès.
            </p>
        </div>
        <div v-if="database.exists" class="card overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th scope="col">Colonne</th>
                        <th scope="col">Type SQL</th>
                        <th scope="col">Champ</th>
                        <th scope="col">Valeur nulle</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="column in database.columns" :key="column.name">
                        <td class="font-mono text-xs">{{ column.name }}</td>
                        <td class="font-mono text-xs">{{ column.type }}</td>
                        <td>{{ column.name === 'record_id' ? 'Identifiant de l\'enregistrement' : (columnLabels[column.name] ?? '—') }}</td>
                        <td>{{ column.nullable ? 'Oui' : 'Non' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <ConfirmDialog
        :show="confirmDelete"
        title="Supprimer la fiche"
        :message="`La fiche « ${recordType.name} », ses ${recordType.records_count ?? 0} enregistrement(s), leurs pièces jointes et la table ${recordType.table_name} seront supprimés définitivement.`"
        confirm-label="Supprimer définitivement"
        danger
        :require-text="recordType.name"
        @close="confirmDelete = false"
        @confirm="destroyType"
    />
</template>
