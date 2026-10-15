<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { EyeOff, Globe, Pencil, Plus, Search, Trash2, Wifi } from '@lucide/vue';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import CheckboxInput from '@/components/CheckboxInput.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormField from '@/components/FormField.vue';
import IspLogo from '@/components/IspLogo.vue';
import Modal from '@/components/Modal.vue';
import PageHeader from '@/components/PageHeader.vue';
import SelectInput from '@/components/SelectInput.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

defineOptions({ layout: AppLayout });

interface Isp {
    id: number;
    name: string;
    country: string | null;
    website: string | null;
    customer_area_url: string | null;
    status_url: string | null;
    support_phone: string | null;
    business_support_phone: string | null;
    support_email: string | null;
    notes: string | null;
    is_active: boolean;
    logo_url: string | null;
    has_custom_logo: boolean;
    has_bundled_logo: boolean;
    links_count: number;
}

const props = defineProps<{ isps: Isp[]; countries: Option[]; maxLogoKb: number }>();

// Exemple affiché dans les champs d'adresse web.
const URL_PLACEHOLDER = 'https://';

const search = ref('');
const country = ref<string | null>(null);
const showHidden = ref(false);

const countryLabels = computed(() => Object.fromEntries(props.countries.map((option) => [option.value, option.label])));
const usedCountries = computed(() => props.countries.filter((option) => props.isps.some((isp) => isp.country === option.value)));

function normalize(value: string): string {
    return value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const visible = computed(() => {
    const term = normalize(search.value.trim());

    return props.isps.filter(
        (isp) =>
            (showHidden.value || isp.is_active) &&
            (country.value === null || (country.value === 'INT' ? isp.country === null : isp.country === country.value)) &&
            (term === '' || normalize(isp.name).includes(term)),
    );
});

const countryOptions = computed<Option[]>(() => [{ value: 'INT', label: 'International' }, ...usedCountries.value]);
const hiddenCount = computed(() => props.isps.filter((isp) => !isp.is_active).length);

const editing = ref<Isp | null>(null);
const showForm = ref(false);
const toDelete = ref<Isp | null>(null);
const logoPreview = ref<string | null>(null);

const form = useForm({
    name: '',
    country: null as string | null,
    website: '',
    customer_area_url: '',
    status_url: '',
    support_phone: '',
    business_support_phone: '',
    support_email: '',
    notes: '',
    is_active: true,
    logo: null as File | null,
    remove_logo: false,
});

function open(isp: Isp | null): void {
    editing.value = isp;
    form.clearErrors();
    form.name = isp?.name ?? '';
    form.country = isp?.country ?? null;
    form.website = isp?.website ?? '';
    form.customer_area_url = isp?.customer_area_url ?? '';
    form.status_url = isp?.status_url ?? '';
    form.support_phone = isp?.support_phone ?? '';
    form.business_support_phone = isp?.business_support_phone ?? '';
    form.support_email = isp?.support_email ?? '';
    form.notes = isp?.notes ?? '';
    form.is_active = isp?.is_active ?? true;
    form.logo = null;
    form.remove_logo = false;
    logoPreview.value = isp?.logo_url ?? null;
    showForm.value = true;
}

function onLogo(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.logo = file;
    form.remove_logo = false;
    logoPreview.value = file ? URL.createObjectURL(file) : (editing.value?.logo_url ?? null);
}

function save(): void {
    const options = { preserveScroll: true, forceFormData: true, onSuccess: () => (showForm.value = false) };

    if (editing.value) {
        // Envoi multipart : la méthode PUT est simulée (_method).
        form.transform((data) => ({ ...data, _method: 'put' })).post(route('admin.isps.update', editing.value.id), options);
    } else {
        form.transform((data) => data).post(route('admin.isps.store'), options);
    }
}

function destroy(): void {
    if (toDelete.value) {
        router.delete(route('admin.isps.destroy', toDelete.value.id), { preserveScroll: true, onFinish: () => (toDelete.value = null) });
    }
}
</script>

<template>
    <PageHeader
        title="Fournisseurs d'accès à Internet"
        description="Catalogue commun à tous les clients : logo, assistance, espace client et page d'état du réseau de chaque opérateur."
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Fournisseurs d\'accès' }]"
    >
        <template #actions>
            <button type="button" class="btn-primary" @click="open(null)"><Plus class="h-4 w-4" aria-hidden="true" /> Nouvel opérateur</button>
        </template>
    </PageHeader>

    <div class="card mb-4 grid grid-cols-1 gap-3 p-4 sm:grid-cols-3">
        <div class="relative">
            <label for="isp-search" class="sr-only">Rechercher un opérateur</label>
            <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
            <input id="isp-search" v-model="search" type="search" class="form-input pl-9" placeholder="Rechercher un opérateur…" />
        </div>
        <div>
            <label for="isp-country" class="sr-only">Pays</label>
            <SelectInput id="isp-country" v-model="country" :options="countryOptions" placeholder="Tous les pays" />
        </div>
        <CheckboxInput v-if="hiddenCount" id="isp-hidden" v-model="showHidden" :label="`Afficher les opérateurs masqués (${hiddenCount})`" class="self-center" />
    </div>

    <p class="mb-3 text-sm text-slate-600" role="status">{{ visible.length }} opérateur(s)</p>

    <ul v-if="visible.length" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <li v-for="isp in visible" :key="isp.id" class="card flex items-center gap-3 p-3" :class="isp.is_active ? '' : 'opacity-60'">
            <IspLogo :url="isp.logo_url" :name="isp.name" size="lg" />
            <div class="min-w-0 flex-1">
                <p class="truncate font-medium text-navy-900" :title="isp.name">{{ isp.name }}</p>
                <p class="truncate text-xs text-slate-500">
                    {{ isp.country ? (countryLabels[isp.country] ?? isp.country) : 'International' }}
                    <template v-if="isp.support_phone"> · {{ isp.support_phone }}</template>
                </p>
                <div class="mt-1 flex flex-wrap items-center gap-1">
                    <Badge v-if="isp.links_count" color="teal">{{ isp.links_count }} lien(s)</Badge>
                    <Badge v-if="!isp.is_active" color="slate"><EyeOff class="h-3 w-3" aria-hidden="true" /> Masqué</Badge>
                    <Badge v-if="isp.has_custom_logo" color="navy">Logo déposé</Badge>
                    <a v-if="isp.website" :href="isp.website" target="_blank" rel="noopener noreferrer" class="text-slate-400 hover:text-navy-700" :title="isp.website">
                        <Globe class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Site de {{ isp.name }}</span>
                    </a>
                </div>
            </div>
            <div class="flex flex-col">
                <button type="button" class="btn-ghost btn-sm" @click="open(isp)"><Pencil class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Modifier {{ isp.name }}</span></button>
                <button v-if="isp.links_count === 0" type="button" class="btn-ghost btn-sm text-red-700" @click="toDelete = isp">
                    <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer {{ isp.name }}</span>
                </button>
            </div>
        </li>
    </ul>
    <EmptyState v-else :icon="Wifi" title="Aucun opérateur" description="Aucun opérateur ne correspond à la recherche." />

    <Modal :show="showForm" :title="editing ? `Modifier ${editing.name}` : 'Nouvel opérateur'" size="lg" @close="showForm = false">
        <form id="isp-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <FormField id="isp_name" label="Nom" :error="form.errors.name" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="isp_name" v-model="form.name" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <FormField id="isp_country" label="Pays" :error="form.errors.country">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="isp_country" v-model="form.country" :options="countries" placeholder="International" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>

            <FormField id="isp_logo" label="Logo" :error="form.errors.logo" :hint="`PNG, JPEG ou WebP, ${Math.round(maxLogoKb / 1024)} Mo maximum. Remplace le logo fourni.`" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <div class="flex items-center gap-3">
                        <IspLogo :url="form.remove_logo ? null : logoPreview" :name="form.name || '?'" size="lg" />
                        <input
                            id="isp_logo"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium hover:file:bg-slate-200"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid ? 'true' : undefined"
                            @change="onLogo"
                        />
                    </div>
                    <CheckboxInput
                        v-if="editing?.has_custom_logo"
                        id="isp_remove_logo"
                        v-model="form.remove_logo"
                        :label="editing.has_bundled_logo ? 'Revenir au logo fourni avec TechIndex' : 'Supprimer le logo déposé'"
                        class="mt-2"
                    />
                </template>
            </FormField>

            <FormField id="isp_support" label="Assistance" :error="form.errors.support_phone">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="isp_support" v-model="form.support_phone" type="tel" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="isp_business_support" label="Assistance professionnels" :error="form.errors.business_support_phone">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="isp_business_support" v-model="form.business_support_phone" type="tel" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="isp_email" label="E-mail du support" :error="form.errors.support_email">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="isp_email" v-model="form.support_email" type="email" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="isp_website" label="Site web" :error="form.errors.website">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="isp_website" v-model="form.website" type="url" :placeholder="URL_PLACEHOLDER" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="isp_customer_area" label="Espace client" :error="form.errors.customer_area_url">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="isp_customer_area" v-model="form.customer_area_url" type="url" :placeholder="URL_PLACEHOLDER" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="isp_status" label="État du réseau / incidents" :error="form.errors.status_url">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="isp_status" v-model="form.status_url" type="url" :placeholder="URL_PLACEHOLDER" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="isp_notes" label="Notes" :error="form.errors.notes" hint="Procédure d'ouverture d'incident, interlocuteurs, horaires…" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="isp_notes" v-model="form.notes" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <CheckboxInput id="isp_active" v-model="form.is_active" label="Proposé dans les formulaires (décocher pour masquer l'opérateur)" class="sm:col-span-2" />
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="showForm = false">Annuler</button>
            <button type="submit" form="isp-form" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </template>
    </Modal>

    <ConfirmDialog
        :show="toDelete !== null"
        title="Supprimer l'opérateur"
        :message="`« ${toDelete?.name} » sera retiré du catalogue.`"
        confirm-label="Supprimer"
        danger
        @close="toDelete = null"
        @confirm="destroy"
    />
</template>
