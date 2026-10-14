<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import SelectInput from '@/components/SelectInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    types: Array<{ value: string; label: string; duplicate_rule: string; templates: { csv: string; xlsx: string } }>;
    modes: Option[];
    clients: Option<number>[];
    defaultClient: number | null;
    maxSizeKb: number;
    maxRows: number;
}>();

const form = useForm({
    type: props.defaultClient ? 'equipment' : (props.types[0]?.value ?? 'equipment'),
    mode: 'create',
    client_id: props.defaultClient,
    file: null as File | null,
});

const selected = computed(() => props.types.find((type) => type.value === form.type));
const typeOptions = computed(() => props.types.map((type) => ({ value: type.value, label: type.label })));

function onFile(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submit(): void {
    form.post(route('imports.store'), { forceFormData: true });
}
</script>

<template>
    <PageHeader title="Nouvel import" :breadcrumbs="[{ label: 'Imports', href: route('imports.index') }, { label: 'Nouveau' }]" />

    <ol class="mb-5 grid grid-cols-1 gap-2 text-xs sm:grid-cols-4" aria-label="Étapes de l'import">
        <li class="rounded-md bg-navy-900 px-3 py-2 font-medium text-white">1. Fichier</li>
        <li class="rounded-md bg-slate-100 px-3 py-2 text-slate-600">2. Correspondance des colonnes</li>
        <li class="rounded-md bg-slate-100 px-3 py-2 text-slate-600">3. Aperçu et validation</li>
        <li class="rounded-md bg-slate-100 px-3 py-2 text-slate-600">4. Rapport</li>
    </ol>

    <form class="card space-y-5 p-5" novalidate @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField id="type" label="Données à importer" :error="form.errors.type" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="type" v-model="form.type" :options="typeOptions" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField v-if="form.type === 'equipment'" id="client_id" label="Client destinataire" :error="form.errors.client_id" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="client_id" v-model="form.client_id" :options="clients" placeholder="— Choisir —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="mode" label="Si un enregistrement existe déjà" :error="form.errors.mode" required class="sm:col-span-2">
                <template #default="{ describedBy }">
                    <fieldset :aria-describedby="describedBy" class="space-y-1.5">
                        <legend class="sr-only">Comportement face aux doublons</legend>
                        <label v-for="mode in modes" :key="mode.value" class="flex items-center gap-2 text-sm">
                            <input v-model="form.mode" type="radio" name="mode" :value="mode.value" class="h-4 w-4 border-slate-300" />
                            {{ mode.label }}
                        </label>
                    </fieldset>
                </template>
            </FormField>
        </div>

        <div v-if="selected" class="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm">
            <p class="font-medium text-navy-900">Règle de détection des doublons</p>
            <p class="mt-1 text-slate-600">{{ selected.duplicate_rule }}</p>
            <p class="mt-3 font-medium text-navy-900">Fichier modèle</p>
            <p class="mt-1 flex flex-wrap gap-2">
                <a :href="selected.templates.xlsx" class="btn-secondary btn-sm"><Download class="h-3.5 w-3.5" aria-hidden="true" /> Modèle Excel</a>
                <a :href="selected.templates.csv" class="btn-secondary btn-sm"><Download class="h-3.5 w-3.5" aria-hidden="true" /> Modèle CSV</a>
            </p>
        </div>

        <FormField id="file" label="Fichier CSV ou Excel (.xlsx)" :error="form.errors.file" :hint="`${Math.round(maxSizeKb / 1024)} Mo et ${maxRows.toLocaleString('fr-FR')} lignes maximum. La première ligne doit contenir les en-têtes.`" required>
            <template #default="{ describedBy }">
                <input
                    id="file"
                    type="file"
                    accept=".csv,.xlsx"
                    class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-navy-900 file:px-3 file:py-1.5 file:text-sm file:text-white"
                    :aria-describedby="describedBy"
                    @change="onFile"
                />
            </template>
        </FormField>

        <div class="flex justify-end gap-2">
            <Link :href="route('imports.index')" class="btn-secondary">Annuler</Link>
            <button type="submit" class="btn-primary" :disabled="form.processing || !form.file">Charger et analyser</button>
        </div>
    </form>
</template>
