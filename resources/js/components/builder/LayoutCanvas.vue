<script setup lang="ts">
import { ArrowDown, ArrowUp, Eye, GripVertical, Lock, Plus, Search, Star, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import DynamicIcon from '@/components/DynamicIcon.vue';
import { fieldsOfSection, gridClass, spanClass } from '@/lib/records';
import type { FieldTypeDefinition, RecordFieldDefinition, RecordSection } from '@/types';

/**
 * Disposition d'une fiche : sections (titre, description, colonnes) et
 * champs, déplaçables par glisser-déposer ou avec les flèches.
 */
const props = defineProps<{
    sections: RecordSection[];
    fields: RecordFieldDefinition[];
    fieldTypes: FieldTypeDefinition[];
    selectedId: number | null;
    builtin: string[];
    systemName: string | null;
}>();

const emit = defineEmits<{
    select: [field: RecordFieldDefinition];
    add: [type: string, section: string];
    move: [id: number, section: string, beforeId: number | null];
    width: [id: number, width: string];
    'section-update': [index: number, patch: Partial<RecordSection>];
    'section-move': [index: number, delta: number];
    'section-remove': [index: number];
    'section-add': [];
}>();

const FIELD = 'text/x-techindex-field';
const TYPE = 'text/x-techindex-field-type';

const dropTarget = ref<{ section: string; beforeId: number | null } | null>(null);
const widths = [
    { value: '1', label: '1', title: 'Une colonne' },
    { value: '2', label: '2', title: 'Deux colonnes' },
    { value: 'full', label: '▭', title: 'Toute la largeur' },
];

const typeMap = computed(() => Object.fromEntries(props.fieldTypes.map((type) => [type.value, type])));
const sectionsWithFields = computed(() => props.sections.map((section) => ({ section, fields: fieldsOfSection(section, props.sections, props.fields) })));

function accepts(event: DragEvent): boolean {
    const types = event.dataTransfer?.types ?? [];

    return types.includes(FIELD) || types.includes(TYPE);
}

function onDragStart(event: DragEvent, field: RecordFieldDefinition): void {
    event.dataTransfer?.setData(FIELD, String(field.id));

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }
}

function onDragOver(event: DragEvent, section: string, beforeId: number | null): void {
    if (!accepts(event)) {
        return;
    }

    event.preventDefault();
    dropTarget.value = { section, beforeId };
}

function onDrop(event: DragEvent, section: string, beforeId: number | null): void {
    const fieldId = event.dataTransfer?.getData(FIELD);
    const type = event.dataTransfer?.getData(TYPE);
    dropTarget.value = null;

    if (fieldId) {
        event.preventDefault();
        emit('move', Number(fieldId), section, beforeId === Number(fieldId) ? null : beforeId);
    } else if (type) {
        event.preventDefault();
        emit('add', type, section);
    }
}

/** Déplacement au clavier dans l'ordre de la section (puis vers la section voisine). */
function shift(field: RecordFieldDefinition, sectionIndex: number, delta: number): void {
    const list = sectionsWithFields.value[sectionIndex]?.fields ?? [];
    const position = list.findIndex((item) => item.id === field.id);
    const target = position + delta;

    if (target >= 0 && target < list.length) {
        const before = delta < 0 ? list[target] : list[target + 1];
        emit('move', field.id, props.sections[sectionIndex].key, before?.id ?? null);

        return;
    }

    const neighbour = props.sections[sectionIndex + delta];

    if (neighbour) {
        const neighbourFields = sectionsWithFields.value[sectionIndex + delta]?.fields ?? [];
        emit('move', field.id, neighbour.key, delta > 0 ? (neighbourFields[0]?.id ?? null) : null);
    }
}
</script>

<template>
    <div class="space-y-4">
        <section v-if="systemName" class="card border-dashed bg-slate-50/60 p-4" aria-label="Champs d'origine">
            <p class="flex items-center gap-2 text-sm font-semibold text-slate-700"><Lock class="h-4 w-4" aria-hidden="true" /> Champs d'origine de la fiche « {{ systemName }} »</p>
            <p class="mt-1 text-xs text-slate-500">Ils ne peuvent être ni modifiés ni supprimés. Les sections ci-dessous s'ajoutent en bas du formulaire et de la fiche.</p>
            <ul class="mt-3 flex flex-wrap gap-1.5">
                <li v-for="label in builtin" :key="label" class="rounded-full border border-slate-200 bg-white px-2.5 py-0.5 text-xs text-slate-600">{{ label }}</li>
            </ul>
        </section>

        <section
            v-for="({ section, fields: sectionFields }, index) in sectionsWithFields"
            :key="section.key"
            class="card"
            :aria-label="`Section ${section.title}`"
        >
            <header class="flex flex-wrap items-start gap-2 border-b border-slate-200 px-3 py-2.5">
                <div class="min-w-48 flex-1 space-y-1">
                    <input
                        :value="section.title"
                        class="w-full rounded border-transparent bg-transparent px-1 py-0.5 text-sm font-semibold text-navy-900 hover:border-slate-200 focus:border-turquoise-500 focus:ring-0"
                        :aria-label="`Titre de la section ${index + 1}`"
                        maxlength="120"
                        placeholder="Titre de la section"
                        @change="emit('section-update', index, { title: ($event.target as HTMLInputElement).value })"
                    />
                    <input
                        :value="section.description ?? ''"
                        class="w-full rounded border-transparent bg-transparent px-1 py-0.5 text-xs text-slate-500 hover:border-slate-200 focus:border-turquoise-500 focus:ring-0"
                        :aria-label="`Description de la section ${index + 1}`"
                        maxlength="500"
                        placeholder="Description (facultative)"
                        @change="emit('section-update', index, { description: ($event.target as HTMLInputElement).value || null })"
                    />
                </div>
                <div class="flex items-center gap-1">
                    <div class="flex overflow-hidden rounded-md border border-slate-200 text-xs" role="group" :aria-label="`Colonnes de la section ${section.title}`">
                        <button
                            v-for="columns in [1, 2, 3]"
                            :key="columns"
                            type="button"
                            class="px-2 py-1"
                            :class="section.columns === columns ? 'bg-navy-900 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'"
                            :aria-pressed="section.columns === columns"
                            :title="`${columns} colonne(s)`"
                            @click="emit('section-update', index, { columns })"
                        >
                            {{ columns }} col.
                        </button>
                    </div>
                    <button type="button" class="btn-ghost btn-sm" :disabled="index === 0" title="Monter la section" @click="emit('section-move', index, -1)">
                        <ArrowUp class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Monter la section</span>
                    </button>
                    <button type="button" class="btn-ghost btn-sm" :disabled="index === sections.length - 1" title="Descendre la section" @click="emit('section-move', index, 1)">
                        <ArrowDown class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Descendre la section</span>
                    </button>
                    <button
                        type="button"
                        class="btn-ghost btn-sm text-red-700"
                        :disabled="sections.length === 1"
                        :title="sectionFields.length ? 'Supprimer la section (ses champs vont dans la première section)' : 'Supprimer la section'"
                        @click="emit('section-remove', index)"
                    >
                        <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer la section</span>
                    </button>
                </div>
            </header>

            <div
                class="grid min-h-20 grid-cols-1 gap-2 p-3"
                :class="[gridClass(section), dropTarget?.section === section.key && dropTarget.beforeId === null ? 'bg-turquoise-50/70' : '']"
                @dragover="onDragOver($event, section.key, null)"
                @dragleave="dropTarget = null"
                @drop="onDrop($event, section.key, null)"
            >
                <div
                    v-for="field in sectionFields"
                    :key="field.id"
                    :class="spanClass(field.width, section.columns)"
                    draggable="true"
                    @dragstart="onDragStart($event, field)"
                    @dragover.stop="onDragOver($event, section.key, field.id)"
                    @drop.stop="onDrop($event, section.key, field.id)"
                >
                    <div
                        class="group flex h-full flex-col gap-1 rounded-md border bg-white px-2.5 py-2 text-sm"
                        :class="[
                            selectedId === field.id ? 'border-turquoise-500 ring-2 ring-turquoise-200' : 'border-slate-200 hover:border-slate-300',
                            dropTarget?.beforeId === field.id ? 'border-t-4 border-t-turquoise-500' : '',
                        ]"
                    >
                        <div class="flex items-center gap-1.5">
                            <GripVertical class="h-4 w-4 shrink-0 cursor-grab text-slate-300 group-hover:text-slate-500" aria-hidden="true" />
                            <button type="button" class="flex min-w-0 flex-1 items-center gap-1.5 text-left" :title="`Modifier ${field.label}`" @click="emit('select', field)">
                                <DynamicIcon :name="typeMap[field.type]?.icon" class="h-3.5 w-3.5 shrink-0 text-slate-400" />
                                <span class="truncate font-medium text-navy-900">{{ field.label }}</span>
                                <span v-if="field.is_required" class="text-red-700" title="Obligatoire" aria-label="obligatoire">*</span>
                            </button>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1 pl-5 text-[11px] text-slate-500">
                            <span>{{ typeMap[field.type]?.label }}</span>
                            <span class="truncate font-mono text-slate-400">· {{ field.column_name }}</span>
                            <Star v-if="field.is_title" class="h-3 w-3 text-amber-500" aria-label="Titre de la fiche" />
                            <span v-if="field.in_list" class="rounded bg-slate-100 px-1" title="Colonne de la liste">liste</span>
                            <Search v-if="field.is_searchable" class="h-3 w-3" aria-label="Inclus dans la recherche" />
                            <span v-if="field.is_unique" class="rounded bg-slate-100 px-1" title="Valeur unique">unique</span>
                            <Eye v-if="field.visibility" class="h-3 w-3 text-violet-600" aria-label="Affichage conditionnel" />
                            <span class="ml-auto flex items-center gap-1">
                                <span v-if="section.columns > 1" class="flex overflow-hidden rounded border border-slate-200 text-[10px]" role="group" :aria-label="`Largeur de ${field.label}`">
                                    <button
                                        v-for="width in widths"
                                        :key="width.value"
                                        type="button"
                                        class="px-1.5 py-0.5"
                                        :class="field.width === width.value ? 'bg-slate-700 text-white' : 'bg-white text-slate-500 hover:bg-slate-50'"
                                        :title="width.title"
                                        :aria-pressed="field.width === width.value"
                                        @click="emit('width', field.id, width.value)"
                                    >
                                        {{ width.label }}
                                    </button>
                                </span>
                                <button type="button" class="rounded p-0.5 text-slate-400 hover:text-navy-900" :title="`Monter ${field.label}`" @click="shift(field, index, -1)">
                                    <ArrowUp class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Monter {{ field.label }}</span>
                                </button>
                                <button type="button" class="rounded p-0.5 text-slate-400 hover:text-navy-900" :title="`Descendre ${field.label}`" @click="shift(field, index, 1)">
                                    <ArrowDown class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Descendre {{ field.label }}</span>
                                </button>
                            </span>
                        </div>
                    </div>
                </div>

                <p v-if="!sectionFields.length" class="col-span-full flex items-center justify-center rounded-md border border-dashed border-slate-300 py-6 text-sm text-slate-500">
                    Glissez un type de champ ici, ou utilisez la palette.
                </p>
            </div>
        </section>

        <button type="button" class="btn-secondary w-full justify-center border-dashed" @click="emit('section-add')">
            <Plus class="h-4 w-4" aria-hidden="true" /> Ajouter une section
        </button>
    </div>
</template>
