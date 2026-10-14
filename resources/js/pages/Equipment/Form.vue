<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import CheckboxInput from '@/components/CheckboxInput.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import FormField from '@/components/FormField.vue';
import FormSection from '@/components/FormSection.vue';
import SelectInput from '@/components/SelectInput.vue';
import TagInput from '@/components/TagInput.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, CustomFieldDefinition, Option, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

// Exemple affiché dans les champs d'adresse web.
const URL_PLACEHOLDER = 'https://';

interface InterfaceRow {
    label: string | null;
    ip_address: string | null;
    mac_address: string | null;
    subnet_id: number | null;
    is_primary: boolean;
    notes: string | null;
}

interface EquipmentData {
    id: number;
    equipment_type_id: number;
    site_id: number | null;
    name: string;
    hostname: string | null;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    inventory_number: string | null;
    location: string | null;
    os_name: string | null;
    os_version: string | null;
    firmware_version: string | null;
    role: string | null;
    description: string | null;
    supplier: string | null;
    admin_url: string | null;
    notes: string | null;
    status: string;
    purchase_date: string | null;
    commissioning_date: string | null;
    warranty_expires_at: string | null;
    end_of_life_at: string | null;
    tags: string[];
    interfaces: InterfaceRow[];
    custom_fields: Record<string, string | number | boolean | null>;
}

const props = defineProps<{
    context: ClientContext;
    equipment: EquipmentData | null;
    types: Array<{ id: number; name: string; icon: string }>;
    sites: Option<number>[];
    subnets: Option<number>[];
    statuses: Option[];
    customFields: CustomFieldDefinition[];
    tags: TagRef[];
    suggestions: { manufacturers: string[]; suppliers: string[]; os: string[] };
}>();

const e = props.equipment;

const form = useForm({
    equipment_type_id: e?.equipment_type_id ?? null,
    site_id: e?.site_id ?? null,
    name: e?.name ?? '',
    hostname: e?.hostname ?? '',
    manufacturer: e?.manufacturer ?? '',
    model: e?.model ?? '',
    serial_number: e?.serial_number ?? '',
    inventory_number: e?.inventory_number ?? '',
    location: e?.location ?? '',
    os_name: e?.os_name ?? '',
    os_version: e?.os_version ?? '',
    firmware_version: e?.firmware_version ?? '',
    role: e?.role ?? '',
    description: e?.description ?? '',
    supplier: e?.supplier ?? '',
    admin_url: e?.admin_url ?? '',
    notes: e?.notes ?? '',
    status: e?.status ?? 'in_service',
    purchase_date: e?.purchase_date ?? '',
    commissioning_date: e?.commissioning_date ?? '',
    warranty_expires_at: e?.warranty_expires_at ?? '',
    end_of_life_at: e?.end_of_life_at ?? '',
    tags: e?.tags ?? ([] as string[]),
    interfaces: (e?.interfaces.length ? e.interfaces : [{ label: '', ip_address: '', mac_address: '', subnet_id: null, is_primary: true, notes: '' }]) as InterfaceRow[],
    custom_fields: { ...(e?.custom_fields ?? {}) } as Record<string, string | number | boolean | null>,
});

const typeOptions = computed(() => props.types.map((type) => ({ value: type.id, label: type.name })));
const applicableFields = computed(() =>
    props.customFields.filter((field) => field.equipment_type_id === null || field.equipment_type_id === form.equipment_type_id),
);

const textInputs = {
    hardware: [
        { key: 'model', label: 'Modèle' },
        { key: 'serial_number', label: 'Numéro de série' },
        { key: 'inventory_number', label: 'Référence d\'inventaire' },
    ],
    system: [
        { key: 'os_version', label: 'Version du système' },
        { key: 'firmware_version', label: 'Firmware' },
    ],
} as const;

const dateInputs = [
    { key: 'purchase_date', label: 'Date d\'achat' },
    { key: 'commissioning_date', label: 'Mise en service' },
    { key: 'warranty_expires_at', label: 'Fin de garantie' },
    { key: 'end_of_life_at', label: 'Fin de vie (support constructeur)' },
] as const;

function addInterface(): void {
    form.interfaces.push({ label: '', ip_address: '', mac_address: '', subnet_id: null, is_primary: form.interfaces.length === 0, notes: '' });
}

function removeInterface(index: number): void {
    form.interfaces.splice(index, 1);
}

function setPrimary(index: number): void {
    form.interfaces.forEach((row, i) => (row.is_primary = i === index));
}

function submit(): void {
    if (props.equipment) {
        form.put(route('clients.equipment.update', [props.context.id, props.equipment.id]));
    } else {
        form.post(route('clients.equipment.store', props.context.id));
    }
}
</script>

<template>
    <ClientHeader
        :context="context"
        active="equipment"
        :title="equipment ? `Modifier ${equipment.name}` : 'Nouvel équipement'"
        :breadcrumbs="[{ label: 'Équipements', href: route('clients.equipment.index', context.id) }, { label: equipment ? equipment.name : 'Nouveau' }]"
    />

    <form class="space-y-5" novalidate @submit.prevent="submit">
        <FormSection title="Identification">
            <FormField id="name" label="Nom de l'équipement" :error="form.errors.name" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="name" v-model="form.name" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <FormField id="equipment_type_id" label="Type" :error="form.errors.equipment_type_id" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="equipment_type_id" v-model="form.equipment_type_id" :options="typeOptions" placeholder="— Choisir —" :described-by="describedBy" :invalid="invalid" required />
                </template>
            </FormField>
            <FormField id="hostname" label="Nom d'hôte" :error="form.errors.hostname">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="hostname" v-model="form.hostname" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="status" label="Statut" :error="form.errors.status" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="status" v-model="form.status" :options="statuses" :described-by="describedBy" :invalid="invalid" required />
                </template>
            </FormField>
            <FormField id="site_id" label="Site de rattachement" :error="form.errors.site_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="site_id" v-model="form.site_id" :options="sites" placeholder="— Aucun —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="location" label="Emplacement physique" :error="form.errors.location" hint="Salle, baie, unité…">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="location" v-model="form.location" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="role" label="Rôle" :error="form.errors.role" hint="Ex. contrôleur de domaine, serveur de fichiers…">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="role" v-model="form.role" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="tags" label="Étiquettes" :error="form.errors.tags">
                <template #default="{ describedBy }">
                    <TagInput id="tags" v-model="form.tags" :available="tags" :described-by="describedBy" />
                </template>
            </FormField>
        </FormSection>

        <FormSection title="Matériel et cycle de vie">
            <FormField id="manufacturer" label="Fabricant" :error="form.errors.manufacturer">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="manufacturer" v-model="form.manufacturer" list="manufacturers" :described-by="describedBy" :invalid="invalid" />
                    <datalist id="manufacturers"><option v-for="item in suggestions.manufacturers" :key="item" :value="item" /></datalist>
                </template>
            </FormField>
            <FormField v-for="field in textInputs.hardware" :id="field.key" :key="field.key" :label="field.label" :error="form.errors[field.key]">
                <template #default="{ describedBy, invalid }">
                    <TextInput :id="field.key" v-model="form[field.key]" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="supplier" label="Fournisseur" :error="form.errors.supplier">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="supplier" v-model="form.supplier" list="suppliers" :described-by="describedBy" :invalid="invalid" />
                    <datalist id="suppliers"><option v-for="item in suggestions.suppliers" :key="item" :value="item" /></datalist>
                </template>
            </FormField>
            <FormField v-for="field in dateInputs" :id="field.key" :key="field.key" :label="field.label" :error="form.errors[field.key]">
                <template #default="{ describedBy, invalid }">
                    <TextInput :id="field.key" v-model="form[field.key]" type="date" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <FormSection title="Système et administration">
            <FormField id="os_name" label="Système d'exploitation" :error="form.errors.os_name">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="os_name" v-model="form.os_name" list="os-list" :described-by="describedBy" :invalid="invalid" />
                    <datalist id="os-list"><option v-for="item in suggestions.os" :key="item" :value="item" /></datalist>
                </template>
            </FormField>
            <FormField v-for="field in textInputs.system" :id="field.key" :key="field.key" :label="field.label" :error="form.errors[field.key]">
                <template #default="{ describedBy, invalid }">
                    <TextInput :id="field.key" v-model="form[field.key]" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="admin_url" label="URL d'administration" :error="form.errors.admin_url">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="admin_url" v-model="form.admin_url" type="url" :placeholder="URL_PLACEHOLDER" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <p class="text-xs text-slate-500 sm:col-span-2">Les identifiants d'administration se saisissent dans la rubrique « Accès » de l'équipement, où ils sont chiffrés.</p>
        </FormSection>

        <section class="card" aria-labelledby="interfaces-title">
            <header class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
                <div>
                    <h2 id="interfaces-title" class="text-sm font-semibold text-navy-900">Adresses réseau</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Une ligne par interface : adresse IP et/ou adresse MAC.</p>
                </div>
                <button type="button" class="btn-secondary btn-sm" @click="addInterface"><Plus class="h-4 w-4" aria-hidden="true" /> Ajouter une adresse</button>
            </header>
            <p v-if="form.errors.interfaces" class="px-5 pt-3 text-xs font-medium text-red-700" role="alert">{{ form.errors.interfaces }}</p>
            <div class="space-y-3 p-5">
                <fieldset v-for="(row, index) in form.interfaces" :key="index" class="grid grid-cols-1 gap-3 rounded-md border border-slate-200 p-3 sm:grid-cols-12">
                    <legend class="sr-only">Interface {{ index + 1 }}</legend>
                    <div class="sm:col-span-2">
                        <label :for="`if-label-${index}`" class="mb-1 block text-xs font-medium text-slate-600">Libellé</label>
                        <input :id="`if-label-${index}`" v-model="row.label" type="text" class="form-input" placeholder="LAN, iDRAC…" />
                    </div>
                    <div class="sm:col-span-3">
                        <label :for="`if-ip-${index}`" class="mb-1 block text-xs font-medium text-slate-600">Adresse IP</label>
                        <input :id="`if-ip-${index}`" v-model="row.ip_address" type="text" class="form-input font-mono" inputmode="decimal" placeholder="192.168.1.10" />
                        <p v-if="form.errors[`interfaces.${index}.ip_address`]" class="mt-1 text-xs text-red-700" role="alert">{{ form.errors[`interfaces.${index}.ip_address`] }}</p>
                    </div>
                    <div class="sm:col-span-3">
                        <label :for="`if-mac-${index}`" class="mb-1 block text-xs font-medium text-slate-600">Adresse MAC</label>
                        <input :id="`if-mac-${index}`" v-model="row.mac_address" type="text" class="form-input font-mono" placeholder="00:11:22:33:44:55" />
                        <p v-if="form.errors[`interfaces.${index}.mac_address`]" class="mt-1 text-xs text-red-700" role="alert">{{ form.errors[`interfaces.${index}.mac_address`] }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label :for="`if-subnet-${index}`" class="mb-1 block text-xs font-medium text-slate-600">Sous-réseau</label>
                        <SelectInput :id="`if-subnet-${index}`" v-model="row.subnet_id" :options="subnets" placeholder="—" />
                    </div>
                    <div class="flex items-end justify-between gap-2 sm:col-span-2">
                        <label class="flex items-center gap-1.5 pb-2 text-xs text-slate-700">
                            <input type="radio" name="primary-interface" :checked="row.is_primary" class="h-4 w-4 border-slate-300" @change="setPrimary(index)" />
                            Principale
                        </label>
                        <button type="button" class="btn-ghost btn-sm text-red-700" @click="removeInterface(index)">
                            <Trash2 class="h-4 w-4" aria-hidden="true" /><span class="sr-only">Retirer l'interface {{ index + 1 }}</span>
                        </button>
                    </div>
                </fieldset>
                <p v-if="form.interfaces.length === 0" class="text-sm text-slate-500">Aucune adresse.</p>
            </div>
        </section>

        <FormSection v-if="applicableFields.length" title="Champs personnalisés" description="Champs définis par l'administrateur pour ce type d'équipement.">
            <template v-for="field in applicableFields" :key="field.id">
                <CheckboxInput
                    v-if="field.type === 'boolean'"
                    :id="`cf-${field.id}`"
                    :model-value="Boolean(form.custom_fields[field.id])"
                    :label="field.label"
                    :description="field.help_text ?? undefined"
                    @update:model-value="form.custom_fields[field.id] = $event ?? false"
                />
                <FormField v-else :id="`cf-${field.id}`" :label="field.label" :error="form.errors[`custom_fields.${field.id}`]" :hint="field.help_text ?? undefined" :required="field.is_required">
                    <template #default="{ describedBy, invalid }">
                        <SelectInput
                            v-if="field.type === 'select'"
                            :id="`cf-${field.id}`"
                            :model-value="(form.custom_fields[field.id] as string | null) ?? null"
                            :options="(field.options ?? []).map((option) => ({ value: option, label: option }))"
                            placeholder="—"
                            :described-by="describedBy"
                            :invalid="invalid"
                            @update:model-value="form.custom_fields[field.id] = $event ?? null"
                        />
                        <TextInput
                            v-else
                            :id="`cf-${field.id}`"
                            :model-value="(form.custom_fields[field.id] as string | number | null) ?? null"
                            :type="field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : field.type === 'url' ? 'url' : 'text'"
                            :described-by="describedBy"
                            :invalid="invalid"
                            @update:model-value="form.custom_fields[field.id] = $event ?? null"
                        />
                    </template>
                </FormField>
            </template>
        </FormSection>

        <FormSection title="Description et notes">
            <FormField id="description" label="Description" :error="form.errors.description" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="description" v-model="form.description" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="notes" label="Notes" :error="form.errors.notes" class="sm:col-span-2" hint="N'y saisissez aucun mot de passe.">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="notes" v-model="form.notes" :rows="4" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <div class="flex justify-end gap-2">
            <Link :href="equipment ? route('clients.equipment.show', [context.id, equipment.id]) : route('clients.equipment.index', context.id)" class="btn-secondary">Annuler</Link>
            <button type="submit" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </div>
    </form>
</template>
