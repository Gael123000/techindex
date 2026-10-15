<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import ClientHeader from '@/components/ClientHeader.vue';
import FormField from '@/components/FormField.vue';
import FormSection from '@/components/FormSection.vue';
import RecordFieldsForm from '@/components/RecordFieldsForm.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, RecordFormDefinition, RecordValue } from '@/types';

defineOptions({ layout: AppLayout });

interface SiteData {
    id: number;
    name: string;
    code: string | null;
    address_line1: string | null;
    address_line2: string | null;
    postal_code: string | null;
    city: string | null;
    country: string | null;
    phone: string | null;
    access_instructions: string | null;
    notes: string | null;
    status?: string;
}

const props = defineProps<{ context: ClientContext; site: SiteData | null; /** Champs ajoutés avec le constructeur de fiches (null s'il n'y en a aucun). */
    extension: RecordFormDefinition | null }>();

const form = useForm({
    name: props.site?.name ?? '',
    code: props.site?.code ?? '',
    address_line1: props.site?.address_line1 ?? '',
    address_line2: props.site?.address_line2 ?? '',
    postal_code: props.site?.postal_code ?? '',
    city: props.site?.city ?? '',
    country: props.site?.country ?? 'France',
    phone: props.site?.phone ?? '',
    access_instructions: props.site?.access_instructions ?? '',
    notes: props.site?.notes ?? '',
    fields: { ...(props.extension?.values ?? {}) } as Record<string, RecordValue>,
});

const textFields: Array<{ key: 'address_line1' | 'address_line2' | 'postal_code' | 'city' | 'country' | 'phone'; label: string; autocomplete?: string }> = [
    { key: 'address_line1', label: 'Adresse', autocomplete: 'address-line1' },
    { key: 'address_line2', label: 'Complément d\'adresse', autocomplete: 'address-line2' },
    { key: 'postal_code', label: 'Code postal', autocomplete: 'postal-code' },
    { key: 'city', label: 'Ville', autocomplete: 'address-level2' },
    { key: 'country', label: 'Pays', autocomplete: 'country-name' },
    { key: 'phone', label: 'Téléphone' },
];

function submit(): void {
    if (props.site) {
        form.put(route('clients.sites.update', [props.context.id, props.site.id]));
    } else {
        form.post(route('clients.sites.store', props.context.id));
    }
}
</script>

<template>
    <ClientHeader
        :context="context"
        active="sites"
        :title="site ? `Modifier le site ${site.name}` : 'Nouveau site'"
        :breadcrumbs="[{ label: 'Sites', href: route('clients.sites.index', context.id) }, { label: site ? site.name : 'Nouveau' }]"
    />

    <form class="space-y-5" novalidate @submit.prevent="submit">
        <FormSection title="Identification">
            <FormField id="name" label="Nom du site" :error="form.errors.name" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="name" v-model="form.name" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <FormField id="code" label="Code" :error="form.errors.code" hint="Code interne facultatif (ex. LYO-01).">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="code" v-model="form.code" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <FormSection title="Adresse et accès">
            <FormField v-for="field in textFields" :id="field.key" :key="field.key" :label="field.label" :error="form.errors[field.key]">
                <template #default="{ describedBy, invalid }">
                    <TextInput :id="field.key" v-model="form[field.key]" :autocomplete="field.autocomplete" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField
                id="access_instructions"
                label="Consignes d'accès"
                :error="form.errors.access_instructions"
                hint="Horaires, badge, interlocuteur à l'accueil, local technique…"
                class="sm:col-span-2"
            >
                <template #default="{ describedBy, invalid }">
                    <TextArea id="access_instructions" v-model="form.access_instructions" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="notes" label="Notes" :error="form.errors.notes" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="notes" v-model="form.notes" :rows="4" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <RecordFieldsForm v-if="extension" :definition="extension" :values="form.fields" :errors="form.errors" :builtin="{ status: site?.status ?? 'active' }" id-prefix="extra" />

        <div class="flex justify-end gap-2">
            <Link :href="site ? route('clients.sites.show', [context.id, site.id]) : route('clients.sites.index', context.id)" class="btn-secondary">Annuler</Link>
            <button type="submit" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </div>
    </form>
</template>
