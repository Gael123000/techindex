<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import CheckboxList from '@/components/CheckboxList.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import FormField from '@/components/FormField.vue';
import FormSection from '@/components/FormSection.vue';
import IspPicker from '@/components/IspPicker.vue';
import RecordFieldsForm from '@/components/RecordFieldsForm.vue';
import SelectInput from '@/components/SelectInput.vue';
import TagInput from '@/components/TagInput.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, IspOption, Option, RecordFormDefinition, RecordValue, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

interface LinkData {
    id: number;
    site_id: number | null;
    isp_id: number | null;
    operator_name: string | null;
    name: string;
    role: string;
    status: string;
    technology: string | null;
    download_mbps: number | null;
    upload_mbps: number | null;
    guaranteed_mbps: number | null;
    customer_number: string | null;
    contract_reference: string | null;
    line_reference: string | null;
    connection_mode: string | null;
    connection_username: string | null;
    wan_vlan: number | null;
    public_ips: string | null;
    wan_gateway: string | null;
    dns_servers: string | null;
    gtr: string | null;
    support_phone: string | null;
    commissioned_at: string | null;
    commitment_ends_at: string | null;
    monthly_cost: string | null;
    description: string | null;
    notes: string | null;
    equipment_ids: number[];
    document_ids: number[];
    tags: string[];
}

const props = defineProps<{
    context: ClientContext;
    link: LinkData | null;
    isps: IspOption[];
    roles: Option[];
    statuses: Option[];
    technologies: Option[];
    connectionModes: Option[];
    sites: Option<number>[];
    equipment: Option<number>[];
    documents: Option<number>[];
    tags: TagRef[];
    canManageCatalog: boolean;
    /** Champs ajoutés avec le constructeur de fiches (null s'il n'y en a aucun). */
    extension: RecordFormDefinition | null;
}>();

const l = props.link;

const form = useForm({
    isp_id: l?.isp_id ?? null,
    operator_name: l?.operator_name ?? '',
    site_id: l?.site_id ?? null,
    name: l?.name ?? '',
    role: l?.role ?? 'primary',
    status: l?.status ?? 'active',
    technology: l?.technology ?? null,
    download_mbps: l?.download_mbps ?? null,
    upload_mbps: l?.upload_mbps ?? null,
    guaranteed_mbps: l?.guaranteed_mbps ?? null,
    customer_number: l?.customer_number ?? '',
    contract_reference: l?.contract_reference ?? '',
    line_reference: l?.line_reference ?? '',
    connection_mode: l?.connection_mode ?? null,
    connection_username: l?.connection_username ?? '',
    wan_vlan: l?.wan_vlan ?? null,
    public_ips: l?.public_ips ?? '',
    wan_gateway: l?.wan_gateway ?? '',
    dns_servers: l?.dns_servers ?? '',
    gtr: l?.gtr ?? '',
    support_phone: l?.support_phone ?? '',
    commissioned_at: l?.commissioned_at ?? '',
    commitment_ends_at: l?.commitment_ends_at ?? '',
    monthly_cost: l?.monthly_cost ?? '',
    description: l?.description ?? '',
    notes: l?.notes ?? '',
    equipment_ids: l?.equipment_ids ?? ([] as number[]),
    document_ids: l?.document_ids ?? ([] as number[]),
    tags: l?.tags ?? ([] as string[]),
    fields: { ...(props.extension?.values ?? {}) } as Record<string, RecordValue>,
});

const operatorError = computed(() => form.errors.isp_id ?? form.errors.operator_name);
const presets = [
    { label: '100 Mbit/s', value: 100 },
    { label: '500 Mbit/s', value: 500 },
    { label: '1 Gbit/s', value: 1000 },
    { label: '2 Gbit/s', value: 2000 },
    { label: '8 Gbit/s', value: 8000 },
];

// Nom proposé à partir de l'opérateur et de la technologie, tant que l'utilisateur n'en a pas saisi.
const suggestedName = computed(() => {
    const operator = props.isps.find((isp) => isp.value === form.isp_id)?.label ?? form.operator_name.trim();
    const technology = props.technologies.find((option) => option.value === form.technology)?.label;

    return [technology, operator].filter(Boolean).join(' – ');
});

function useSuggestedName(): void {
    if (form.name.trim() === '' && suggestedName.value !== '') {
        form.name = suggestedName.value;
    }
}

function submit(): void {
    if (props.link) {
        form.put(route('clients.internet-links.update', [props.context.id, props.link.id]));
    } else {
        form.post(route('clients.internet-links.store', props.context.id));
    }
}
</script>

<template>
    <ClientHeader
        :context="context"
        active="internet"
        :title="link ? `Modifier ${link.name}` : 'Nouveau lien Internet'"
        :breadcrumbs="[{ label: 'FAI', href: route('clients.internet-links.index', context.id) }, { label: link ? link.name : 'Nouveau' }]"
    />

    <form class="space-y-5" novalidate @submit.prevent="submit">
        <FormSection title="Opérateur et lien" description="Choisissez l'opérateur dans le catalogue : son logo, son support et ses liens utiles s'affichent sur la fiche.">
            <FormField id="isp_id" label="Opérateur (FAI)" :error="operatorError" required>
                <template #default="{ describedBy, invalid }">
                    <IspPicker id="isp_id" v-model="form.isp_id" :options="isps" :described-by="describedBy" :invalid="invalid" @update:model-value="useSuggestedName" />
                </template>
            </FormField>
            <FormField v-if="form.isp_id === null" id="operator_name" label="Nom de l'opérateur (hors catalogue)" :error="form.errors.operator_name" :hint="canManageCatalog ? 'Vous pouvez aussi l\'ajouter au catalogue (Administration › Fournisseurs d\'accès).' : undefined">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="operator_name" v-model="form.operator_name" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <div v-else class="hidden sm:block" />
            <FormField id="name" label="Nom du lien" :error="form.errors.name" required :hint="suggestedName && !form.name ? `Suggestion : ${suggestedName}` : 'Ex. Fibre principale du siège'">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="name" v-model="form.name" :described-by="describedBy" :invalid="invalid" required @focus="useSuggestedName" />
                </template>
            </FormField>
            <FormField id="technology" label="Technologie" :error="form.errors.technology">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="technology" v-model="form.technology" :options="technologies" placeholder="— Non précisée —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="role" label="Rôle" :error="form.errors.role" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="role" v-model="form.role" :options="roles" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="status" label="Statut" :error="form.errors.status" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="status" v-model="form.status" :options="statuses" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="site_id" label="Site desservi" :error="form.errors.site_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="site_id" v-model="form.site_id" :options="sites" placeholder="— Aucun —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="tags" label="Étiquettes" :error="form.errors.tags">
                <template #default="{ describedBy }">
                    <TagInput id="tags" v-model="form.tags" :available="tags" :described-by="describedBy" />
                </template>
            </FormField>
        </FormSection>

        <FormSection title="Débits" description="En Mbit/s : 1 Gbit/s = 1000 Mbit/s.">
            <FormField id="download_mbps" label="Débit descendant (Mbit/s)" :error="form.errors.download_mbps">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="download_mbps" v-model="form.download_mbps" type="number" inputmode="numeric" :described-by="describedBy" :invalid="invalid" />
                    <div class="mt-1.5 flex flex-wrap gap-1">
                        <button v-for="preset in presets" :key="preset.value" type="button" class="rounded border border-slate-200 px-1.5 py-0.5 text-xs text-slate-600 hover:bg-slate-50" @click="form.download_mbps = preset.value">
                            {{ preset.label }}
                        </button>
                    </div>
                </template>
            </FormField>
            <FormField id="upload_mbps" label="Débit montant (Mbit/s)" :error="form.errors.upload_mbps">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="upload_mbps" v-model="form.upload_mbps" type="number" inputmode="numeric" :described-by="describedBy" :invalid="invalid" />
                    <div class="mt-1.5 flex flex-wrap gap-1">
                        <button v-for="preset in presets" :key="preset.value" type="button" class="rounded border border-slate-200 px-1.5 py-0.5 text-xs text-slate-600 hover:bg-slate-50" @click="form.upload_mbps = preset.value">
                            {{ preset.label }}
                        </button>
                    </div>
                </template>
            </FormField>
            <FormField id="guaranteed_mbps" label="Débit garanti (Mbit/s)" :error="form.errors.guaranteed_mbps" hint="Pour une fibre dédiée ou un SDSL.">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="guaranteed_mbps" v-model="form.guaranteed_mbps" type="number" inputmode="numeric" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <FormSection title="Raccordement WAN" description="Paramètres côté opérateur. Le mot de passe de connexion se conserve dans un accès chiffré, depuis la fiche du lien.">
            <FormField id="connection_mode" label="Mode de connexion" :error="form.errors.connection_mode">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="connection_mode" v-model="form.connection_mode" :options="connectionModes" placeholder="— Non précisé —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="connection_username" label="Identifiant de connexion" :error="form.errors.connection_username" hint="Identifiant PPPoE par exemple, jamais le mot de passe.">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="connection_username" v-model="form.connection_username" autocomplete="off" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="wan_vlan" label="VLAN opérateur" :error="form.errors.wan_vlan" hint="VLAN imposé par l'opérateur sur le lien WAN, s'il y en a un.">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="wan_vlan" v-model="form.wan_vlan" type="number" inputmode="numeric" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="wan_gateway" label="Passerelle opérateur" :error="form.errors.wan_gateway">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="wan_gateway" v-model="form.wan_gateway" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="public_ips" label="Adresses IP publiques" :error="form.errors.public_ips" hint="Adresse fixe, plusieurs adresses séparées par des virgules ou un bloc (ex. 203.0.113.8/29).">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="public_ips" v-model="form.public_ips" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="dns_servers" label="Serveurs DNS de l'opérateur" :error="form.errors.dns_servers">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="dns_servers" v-model="form.dns_servers" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <FormSection title="Contrat et support">
            <FormField id="customer_number" label="Numéro de client" :error="form.errors.customer_number">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="customer_number" v-model="form.customer_number" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="contract_reference" label="Référence du contrat" :error="form.errors.contract_reference">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="contract_reference" v-model="form.contract_reference" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="line_reference" label="Référence de ligne / d'accès" :error="form.errors.line_reference" hint="NDI, référence de prestation, identifiant de ligne…">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="line_reference" v-model="form.line_reference" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="gtr" label="Garantie de temps de rétablissement (GTR)" :error="form.errors.gtr" hint="Ex. 4 h ouvrées, 4 h 24/7.">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="gtr" v-model="form.gtr" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="support_phone" label="Support dédié au contrat" :error="form.errors.support_phone" hint="S'ajoute aux numéros de l'opérateur indiqués dans le catalogue.">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="support_phone" v-model="form.support_phone" type="tel" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="monthly_cost" label="Coût mensuel (€ HT)" :error="form.errors.monthly_cost">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="monthly_cost" v-model="form.monthly_cost" type="number" inputmode="decimal" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="commissioned_at" label="Mise en service" :error="form.errors.commissioned_at">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="commissioned_at" v-model="form.commissioned_at" type="date" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="commitment_ends_at" label="Fin d'engagement" :error="form.errors.commitment_ends_at">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="commitment_ends_at" v-model="form.commitment_ends_at" type="date" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <FormSection title="Équipements et documentation" description="Box, routeur ou pare-feu raccordés à ce lien ; procédures associées.">
            <div>
                <p class="mb-1 text-sm font-medium text-slate-700">Équipements</p>
                <CheckboxList id="equipment_ids" v-model="form.equipment_ids" :options="equipment" empty-text="Aucun équipement inventorié pour ce client." />
                <p v-if="form.errors.equipment_ids" class="mt-1 text-xs text-red-700" role="alert">{{ form.errors.equipment_ids }}</p>
            </div>
            <div>
                <p class="mb-1 text-sm font-medium text-slate-700">Documents</p>
                <CheckboxList id="document_ids" v-model="form.document_ids" :options="documents" empty-text="Aucun document pour ce client." />
                <p v-if="form.errors.document_ids" class="mt-1 text-xs text-red-700" role="alert">{{ form.errors.document_ids }}</p>
            </div>
            <FormField id="description" label="Description" :error="form.errors.description" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="description" v-model="form.description" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="notes" label="Notes" :error="form.errors.notes" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="notes" v-model="form.notes" :rows="5" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <RecordFieldsForm v-if="extension" :definition="extension" :values="form.fields" :errors="form.errors" :builtin="{ technology: form.technology, role: form.role, status: form.status }" id-prefix="extra" />

        <div class="flex justify-end gap-2">
            <Link :href="link ? route('clients.internet-links.show', [context.id, link.id]) : route('clients.internet-links.index', context.id)" class="btn-secondary">Annuler</Link>
            <button type="submit" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </div>
    </form>
</template>
