<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import CheckboxInput from '@/components/CheckboxInput.vue';
import CheckboxList from '@/components/CheckboxList.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import FormField from '@/components/FormField.vue';
import FormSection from '@/components/FormSection.vue';
import RecordFieldsForm from '@/components/RecordFieldsForm.vue';
import SelectInput from '@/components/SelectInput.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, Option, RecordFormDefinition, RecordValue } from '@/types';

defineOptions({ layout: AppLayout });

interface LicenseData {
    id: number;
    kind: string;
    site_id: number | null;
    name: string;
    publisher: string | null;
    supplier: string | null;
    contract_reference: string | null;
    quantity: number | null;
    billing_period: string | null;
    cost: string | null;
    purchase_date: string | null;
    starts_at: string | null;
    expires_at: string | null;
    auto_renewal: boolean;
    renewal_notice_days: number | null;
    renewal_notes: string | null;
    status: string;
    notes: string | null;
    equipment_ids: number[];
}

const props = defineProps<{
    context: ClientContext;
    license: LicenseData | null;
    kinds: Option[];
    statuses: Option[];
    periods: Option[];
    sites: Option<number>[];
    equipment: Option<number>[];
    /** Champs ajoutés avec le constructeur de fiches (null s'il n'y en a aucun). */
    extension: RecordFormDefinition | null;
}>();

const l = props.license;

const form = useForm({
    kind: l?.kind ?? 'license',
    site_id: l?.site_id ?? null,
    name: l?.name ?? '',
    publisher: l?.publisher ?? '',
    supplier: l?.supplier ?? '',
    contract_reference: l?.contract_reference ?? '',
    quantity: l?.quantity ?? null,
    billing_period: l?.billing_period ?? null,
    cost: l?.cost ?? '',
    purchase_date: l?.purchase_date ?? '',
    starts_at: l?.starts_at ?? '',
    expires_at: l?.expires_at ?? '',
    auto_renewal: l?.auto_renewal ?? false,
    renewal_notice_days: l?.renewal_notice_days ?? null,
    renewal_notes: l?.renewal_notes ?? '',
    status: l?.status ?? 'active',
    notes: l?.notes ?? '',
    equipment_ids: l?.equipment_ids ?? ([] as number[]),
    fields: { ...(props.extension?.values ?? {}) } as Record<string, RecordValue>,
});

function submit(): void {
    if (props.license) {
        form.put(route('clients.licenses.update', [props.context.id, props.license.id]));
    } else {
        form.post(route('clients.licenses.store', props.context.id));
    }
}
</script>

<template>
    <ClientHeader
        :context="context"
        active="licenses"
        :title="license ? `Modifier ${license.name}` : 'Nouvelle licence ou abonnement'"
        :breadcrumbs="[{ label: 'Licences', href: route('clients.licenses.index', context.id) }, { label: license ? license.name : 'Nouvelle' }]"
    />

    <form class="space-y-5" novalidate @submit.prevent="submit">
        <FormSection title="Produit">
            <FormField id="name" label="Nom" :error="form.errors.name" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="name" v-model="form.name" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <FormField id="kind" label="Nature" :error="form.errors.kind" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="kind" v-model="form.kind" :options="kinds" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="publisher" label="Éditeur" :error="form.errors.publisher">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="publisher" v-model="form.publisher" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="supplier" label="Fournisseur" :error="form.errors.supplier">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="supplier" v-model="form.supplier" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="quantity" label="Quantité" :error="form.errors.quantity">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="quantity" v-model="form.quantity" type="number" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="status" label="Statut" :error="form.errors.status" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="status" v-model="form.status" :options="statuses" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="site_id" label="Site" :error="form.errors.site_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="site_id" v-model="form.site_id" :options="sites" placeholder="— Aucun —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <p class="self-end text-xs text-slate-500">Une clé de licence se conserve dans la rubrique « Accès » (chiffrée).</p>
        </FormSection>

        <FormSection title="Contrat et renouvellement" description="Les licences actives proches de l'échéance apparaissent au tableau de bord.">
            <FormField id="contract_reference" label="Référence du contrat" :error="form.errors.contract_reference">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="contract_reference" v-model="form.contract_reference" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="billing_period" label="Périodicité" :error="form.errors.billing_period">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="billing_period" v-model="form.billing_period" :options="periods" placeholder="—" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="cost" label="Coût (€ HT)" :error="form.errors.cost">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="cost" v-model="form.cost" type="number" inputmode="decimal" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="purchase_date" label="Date d'achat" :error="form.errors.purchase_date">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="purchase_date" v-model="form.purchase_date" type="date" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="starts_at" label="Début" :error="form.errors.starts_at">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="starts_at" v-model="form.starts_at" type="date" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="expires_at" label="Échéance" :error="form.errors.expires_at">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="expires_at" v-model="form.expires_at" type="date" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="renewal_notice_days" label="Préavis de résiliation (jours)" :error="form.errors.renewal_notice_days">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="renewal_notice_days" v-model="form.renewal_notice_days" type="number" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <CheckboxInput id="auto_renewal" v-model="form.auto_renewal" label="Renouvellement automatique" class="self-end" />
            <FormField id="renewal_notes" label="Informations utiles au renouvellement" :error="form.errors.renewal_notes" class="sm:col-span-2" hint="Interlocuteur, procédure, conditions tarifaires…">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="renewal_notes" v-model="form.renewal_notes" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <FormSection title="Équipements concernés">
            <div class="sm:col-span-2">
                <CheckboxList id="equipment_ids" v-model="form.equipment_ids" :options="equipment" />
            </div>
            <FormField id="notes" label="Notes" :error="form.errors.notes" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="notes" v-model="form.notes" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <RecordFieldsForm v-if="extension" :definition="extension" :values="form.fields" :errors="form.errors" :builtin="{ kind: form.kind, status: form.status }" id-prefix="extra" />

        <div class="flex justify-end gap-2">
            <Link :href="license ? route('clients.licenses.show', [context.id, license.id]) : route('clients.licenses.index', context.id)" class="btn-secondary">Annuler</Link>
            <button type="submit" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </div>
    </form>
</template>
