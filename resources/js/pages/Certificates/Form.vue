<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import CheckboxInput from '@/components/CheckboxInput.vue';
import CheckboxList from '@/components/CheckboxList.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import FormField from '@/components/FormField.vue';
import FormSection from '@/components/FormSection.vue';
import SelectInput from '@/components/SelectInput.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, Option } from '@/types';

defineOptions({ layout: AppLayout });

interface CertificateData {
    id: number;
    site_id: number | null;
    service_id: number | null;
    name: string;
    common_name: string | null;
    subject_alt_names: string | null;
    issuer: string | null;
    type: string;
    serial_number: string | null;
    fingerprint_sha256: string | null;
    issued_at: string | null;
    expires_at: string | null;
    auto_renewal: boolean;
    renewal_method: string | null;
    status: string;
    notes: string | null;
    equipment_ids: number[];
}

const props = defineProps<{
    context: ClientContext;
    certificate: CertificateData | null;
    types: Option[];
    statuses: Option[];
    sites: Option<number>[];
    services: Option<number>[];
    equipment: Option<number>[];
}>();

const c = props.certificate;

const form = useForm({
    site_id: c?.site_id ?? null,
    service_id: c?.service_id ?? null,
    name: c?.name ?? '',
    common_name: c?.common_name ?? '',
    subject_alt_names: c?.subject_alt_names ?? '',
    issuer: c?.issuer ?? '',
    type: c?.type ?? 'public',
    serial_number: c?.serial_number ?? '',
    fingerprint_sha256: c?.fingerprint_sha256 ?? '',
    issued_at: c?.issued_at ?? '',
    expires_at: c?.expires_at ?? '',
    auto_renewal: c?.auto_renewal ?? false,
    renewal_method: c?.renewal_method ?? '',
    status: c?.status ?? 'active',
    notes: c?.notes ?? '',
    equipment_ids: c?.equipment_ids ?? ([] as number[]),
});

function submit(): void {
    if (props.certificate) {
        form.put(route('clients.certificates.update', [props.context.id, props.certificate.id]));
    } else {
        form.post(route('clients.certificates.store', props.context.id));
    }
}
</script>

<template>
    <ClientHeader
        :context="context"
        active="certificates"
        :title="certificate ? `Modifier ${certificate.name}` : 'Nouveau certificat'"
        :breadcrumbs="[{ label: 'Certificats', href: route('clients.certificates.index', context.id) }, { label: certificate ? certificate.name : 'Nouveau' }]"
    />

    <form class="space-y-5" novalidate @submit.prevent="submit">
        <FormSection title="Certificat" description="La clé privée n'est jamais saisie ici ; si elle doit être conservée, utilisez un accès sécurisé.">
            <FormField id="name" label="Libellé" :error="form.errors.name" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="name" v-model="form.name" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <FormField id="type" label="Type" :error="form.errors.type" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="type" v-model="form.type" :options="types" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="common_name" label="Nom commun (CN)" :error="form.errors.common_name">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="common_name" v-model="form.common_name" placeholder="*.exemple.fr" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="issuer" label="Émetteur (autorité)" :error="form.errors.issuer">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="issuer" v-model="form.issuer" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="subject_alt_names" label="Noms alternatifs (SAN)" :error="form.errors.subject_alt_names" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="subject_alt_names" v-model="form.subject_alt_names" :rows="2" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="serial_number" label="Numéro de série" :error="form.errors.serial_number">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="serial_number" v-model="form.serial_number" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="fingerprint_sha256" label="Empreinte SHA-256" :error="form.errors.fingerprint_sha256">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="fingerprint_sha256" v-model="form.fingerprint_sha256" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="issued_at" label="Émis le" :error="form.errors.issued_at">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="issued_at" v-model="form.issued_at" type="date" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="expires_at" label="Expire le" :error="form.errors.expires_at">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="expires_at" v-model="form.expires_at" type="date" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="status" label="Statut" :error="form.errors.status" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="status" v-model="form.status" :options="statuses" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <CheckboxInput id="auto_renewal" v-model="form.auto_renewal" label="Renouvellement automatique (ACME…)" class="self-end" />
            <FormField id="renewal_method" label="Méthode de renouvellement" :error="form.errors.renewal_method" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="renewal_method" v-model="form.renewal_method" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <FormSection title="Rattachement">
            <FormField id="service_id" label="Service" :error="form.errors.service_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="service_id" v-model="form.service_id" :options="services" placeholder="— Aucun —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="site_id" label="Site" :error="form.errors.site_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="site_id" v-model="form.site_id" :options="sites" placeholder="— Aucun —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <div class="sm:col-span-2">
                <p class="mb-1 text-sm font-medium text-slate-700">Équipements utilisant ce certificat</p>
                <CheckboxList id="equipment_ids" v-model="form.equipment_ids" :options="equipment" />
            </div>
            <FormField id="notes" label="Notes" :error="form.errors.notes" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="notes" v-model="form.notes" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <div class="flex justify-end gap-2">
            <Link :href="certificate ? route('clients.certificates.show', [context.id, certificate.id]) : route('clients.certificates.index', context.id)" class="btn-secondary">Annuler</Link>
            <button type="submit" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </div>
    </form>
</template>
