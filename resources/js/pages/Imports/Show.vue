<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, Download, Play } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import CheckboxInput from '@/components/CheckboxInput.vue';
import DeleteResource from '@/components/DeleteResource.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import type { ImportSummary, Option } from '@/types';

defineOptions({ layout: AppLayout });

interface AnalysisRow {
    line: number;
    label: string;
    action: 'create' | 'update' | 'skip' | 'error';
    errors: string[];
    messages: string[];
}

const props = defineProps<{
    import: ImportSummary & {
        headers: string[];
        mapping: Array<string | null>;
        skip_invalid_rows: boolean;
        failure_reason: string | null;
        started_at: string | null;
        finished_at: string | null;
    };
    columns: Array<{ key: string; label: string; required: boolean; help: string | null }>;
    analysis: {
        summary: { total: number; create: number; update: number; skip: number; error: number };
        rows: AnalysisRow[];
        missing_required: string[];
        truncated: boolean;
        duplicate_rule: string;
    } | null;
    previewLimit: number;
    error: string | null;
    modes: Option[];
    can: { execute: boolean };
}>();

const data = computed(() => props.import);
const editable = computed(() => ['uploaded', 'validated'].includes(data.value.status));
const running = computed(() => ['queued', 'processing'].includes(data.value.status));

const form = useForm({
    mapping: [...data.value.mapping],
    mode: data.value.mode,
    skip_invalid_rows: data.value.skip_invalid_rows,
});

const columnOptions = computed(() => props.columns.map((column) => ({ value: column.key, label: `${column.label}${column.required ? ' *' : ''}` })));

const actionLabels: Record<AnalysisRow['action'], { label: string; color: string }> = {
    create: { label: 'Création', color: 'green' },
    update: { label: 'Mise à jour', color: 'blue' },
    skip: { label: 'Ignorée', color: 'slate' },
    error: { label: 'Erreur', color: 'red' },
};

function saveMapping(): void {
    form.put(route('imports.mapping', data.value.id), { preserveScroll: true });
}

function execute(): void {
    router.post(route('imports.execute', data.value.id), {}, { preserveScroll: true });
}

// Actualisation automatique pendant le traitement par le worker.
let poll: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    poll = setInterval(() => {
        if (running.value) {
            router.reload({ only: ['import', 'analysis'] });
        }
    }, 2500);
});

onBeforeUnmount(() => clearInterval(poll));
</script>

<template>
    <PageHeader :title="`Import : ${data.original_filename}`" :breadcrumbs="[{ label: 'Imports', href: route('imports.index') }, { label: data.original_filename }]">
        <template #actions>
            <StatusBadge :status="data.status" :label="data.status_label" />
            <DeleteResource v-if="!running" :url="route('imports.destroy', data.id)" :name="data.original_filename" label="Supprimer" message="L'import et son fichier seront supprimés. Les données déjà importées sont conservées." />
        </template>
    </PageHeader>

    <dl class="card mb-5 grid grid-cols-2 gap-4 p-4 text-sm sm:grid-cols-4">
        <div><dt class="text-xs text-slate-500">Données</dt><dd>{{ data.type_label }}</dd></div>
        <div><dt class="text-xs text-slate-500">Client</dt><dd>{{ data.client?.name ?? '—' }}</dd></div>
        <div><dt class="text-xs text-slate-500">Lignes lues</dt><dd>{{ data.total_rows }}</dd></div>
        <div><dt class="text-xs text-slate-500">Chargé le</dt><dd>{{ formatDateTime(data.created_at) }}</dd></div>
    </dl>

    <p v-if="error" class="mb-5 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ error }}</p>

    <div v-if="running" class="mb-5 rounded-md border border-turquoise-200 bg-turquoise-50 px-4 py-3 text-sm text-turquoise-900" role="status">
        Import en cours de traitement par le worker… Cette page s'actualise automatiquement.
    </div>

    <div v-if="data.status === 'completed'" class="mb-5 rounded-md border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-900" role="status">
        <CircleCheck class="mr-1 inline h-4 w-4" aria-hidden="true" />
        Import terminé le {{ formatDateTime(data.finished_at) }} : {{ data.created_count }} création(s), {{ data.updated_count }} mise(s) à jour,
        {{ data.skipped_count }} ligne(s) ignorée(s)<template v-if="data.error_count">, {{ data.error_count }} ligne(s) en erreur non importée(s)</template>.
    </div>

    <div v-if="data.status === 'failed'" class="mb-5 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
        <CircleAlert class="mr-1 inline h-4 w-4" aria-hidden="true" /> {{ data.failure_reason }}
    </div>

    <form v-if="editable && can.execute" class="card mb-5" @submit.prevent="saveMapping">
        <header class="border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-navy-900">Correspondance des colonnes</h2>
            <p class="text-xs text-slate-500">Associez chaque colonne du fichier à un champ TechIndex (* obligatoire). Les colonnes non associées sont ignorées.</p>
        </header>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th scope="col">Colonne du fichier</th>
                        <th scope="col">Champ TechIndex</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="(header, index) in data.headers" :key="index">
                        <td><label :for="`map-${index}`" class="font-medium">{{ header || `(colonne ${index + 1} sans titre)` }}</label></td>
                        <td>
                            <select :id="`map-${index}`" v-model="form.mapping[index]" class="form-input">
                                <option :value="null">— Ignorer —</option>
                                <option v-for="option in columnOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="form.errors.mapping" class="px-4 pt-2 text-xs font-medium text-red-700" role="alert">{{ form.errors.mapping }}</p>
        <div class="grid grid-cols-1 gap-4 border-t border-slate-200 p-4 sm:grid-cols-2">
            <fieldset class="space-y-1.5">
                <legend class="mb-1 text-sm font-medium text-slate-700">Si un enregistrement existe déjà</legend>
                <label v-for="mode in modes" :key="mode.value" class="flex items-center gap-2 text-sm">
                    <input v-model="form.mode" type="radio" name="mode" :value="mode.value" class="h-4 w-4 border-slate-300" />
                    {{ mode.label }}
                </label>
            </fieldset>
            <div class="space-y-3">
                <CheckboxInput
                    id="skip_invalid_rows"
                    v-model="form.skip_invalid_rows"
                    label="Ignorer explicitement les lignes en erreur"
                    description="Sinon, l'import est refusé tant qu'une ligne est en erreur."
                />
                <button type="submit" class="btn-secondary" :disabled="form.processing">Enregistrer et recalculer l'aperçu</button>
            </div>
        </div>
    </form>

    <section v-if="analysis" class="card" aria-labelledby="preview-title">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
            <div>
                <h2 id="preview-title" class="text-sm font-semibold text-navy-900">{{ editable ? 'Aperçu avant validation' : 'Rapport ligne par ligne' }}</h2>
                <p class="text-xs text-slate-500">{{ analysis.duplicate_rule }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <ul class="flex flex-wrap gap-1.5 text-xs">
                    <li><Badge color="green">{{ analysis.summary.create }} création(s)</Badge></li>
                    <li><Badge color="blue">{{ analysis.summary.update }} mise(s) à jour</Badge></li>
                    <li><Badge color="slate">{{ analysis.summary.skip }} ignorée(s)</Badge></li>
                    <li><Badge :color="analysis.summary.error ? 'red' : 'slate'">{{ analysis.summary.error }} erreur(s)</Badge></li>
                </ul>
                <span class="flex items-center gap-1 text-xs">
                    <Download class="h-3.5 w-3.5 text-slate-500" aria-hidden="true" />
                    Rapport complet :
                    <a :href="route('imports.report', [data.id, 'xlsx'])" class="link">Excel</a>
                    <a :href="route('imports.report', [data.id, 'csv'])" class="link">CSV</a>
                </span>
            </div>
        </header>

        <p v-if="analysis.missing_required.length" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800" role="alert">
            Colonnes obligatoires non associées : {{ analysis.missing_required.join(', ') }}.
        </p>
        <p v-if="analysis.truncated" class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900" role="alert">
            Le fichier dépasse le nombre maximal de lignes : seules les premières lignes sont prises en compte.
        </p>

        <div class="max-h-[32rem] overflow-auto">
            <table class="table-base">
                <thead class="sticky top-0">
                    <tr>
                        <th scope="col">Ligne</th>
                        <th scope="col">Élément</th>
                        <th scope="col">Action</th>
                        <th scope="col">Détail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="row in analysis.rows" :key="row.line" :class="row.action === 'error' ? 'bg-red-50/50' : ''">
                        <td class="text-xs">{{ row.line }}</td>
                        <td>{{ row.label }}</td>
                        <td><Badge :color="actionLabels[row.action].color">{{ actionLabels[row.action].label }}</Badge></td>
                        <td class="text-xs">
                            <ul v-if="row.errors.length" class="list-disc pl-4 text-red-800">
                                <li v-for="message in row.errors" :key="message">{{ message }}</li>
                            </ul>
                            <span v-for="message in row.messages" :key="message" class="text-slate-600">{{ message }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="analysis.summary.total > analysis.rows.length" class="border-t border-slate-200 px-4 py-2 text-xs text-slate-500">
            Seules les {{ analysis.rows.length }} premières lignes sont affichées ; toutes les lignes sont analysées et figurent dans le rapport complet.
        </p>

        <div v-if="editable && can.execute" class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3">
            <p class="text-xs text-slate-600">
                L'import est exécuté en une seule transaction : si une erreur survient, aucune donnée n'est enregistrée.
            </p>
            <button
                type="button"
                class="btn-primary"
                :disabled="form.isDirty || analysis.missing_required.length > 0 || (analysis.summary.error > 0 && !data.skip_invalid_rows) || analysis.summary.create + analysis.summary.update === 0"
                @click="execute"
            >
                <Play class="h-4 w-4" aria-hidden="true" /> Lancer l'import ({{ analysis.summary.create + analysis.summary.update }} ligne(s))
            </button>
        </div>
    </section>
</template>
