<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';
import CheckboxInput from '@/components/CheckboxInput.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import FormField from '@/components/FormField.vue';
import FormSection from '@/components/FormSection.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordFieldsForm from '@/components/RecordFieldsForm.vue';
import SelectInput from '@/components/SelectInput.vue';
import TagInput from '@/components/TagInput.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, Option, RecordFormDefinition, RecordValue, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

// Exemple affiché dans les champs d'adresse web.
const URL_PLACEHOLDER = 'https://';

interface ClientData {
    id: number;
    name: string;
    reference: string;
    description: string | null;
    account_manager_id: number | null;
    address_line1: string | null;
    address_line2: string | null;
    postal_code: string | null;
    city: string | null;
    country: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
    notes: string | null;
    tags: string[];
    logo_url: string | null;
}

const props = defineProps<{
    client: ClientData | null;
    context?: ClientContext;
    defaults: { reference: string; account_manager_id: number; country: string } | null;
    managers: Option<number>[];
    tags: TagRef[];
    /** Champs ajoutés avec le constructeur de fiches (null s'il n'y en a aucun). */
    extension: RecordFormDefinition | null;
}>();

const form = useForm({
    name: props.client?.name ?? '',
    reference: props.client?.reference ?? props.defaults?.reference ?? '',
    description: props.client?.description ?? '',
    account_manager_id: props.client?.account_manager_id ?? props.defaults?.account_manager_id ?? null,
    address_line1: props.client?.address_line1 ?? '',
    address_line2: props.client?.address_line2 ?? '',
    postal_code: props.client?.postal_code ?? '',
    city: props.client?.city ?? '',
    country: props.client?.country ?? props.defaults?.country ?? '',
    phone: props.client?.phone ?? '',
    email: props.client?.email ?? '',
    website: props.client?.website ?? '',
    notes: props.client?.notes ?? '',
    tags: props.client?.tags ?? ([] as string[]),
    logo: null as File | null,
    remove_logo: false,
    fields: { ...(props.extension?.values ?? {}) } as Record<string, RecordValue>,
});

const logoPreview = ref<string | null>(props.client?.logo_url ?? null);
const title = computed(() => (props.client ? 'Modifier le client' : 'Nouveau client'));

function onLogo(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.logo = file;
    form.remove_logo = false;
    logoPreview.value = file ? URL.createObjectURL(file) : (props.client?.logo_url ?? null);
}

// En multipart, une liste vide n'est pas transmise : elle est envoyée vide pour être effacée.
function multipartFields(fields: Record<string, RecordValue>): Record<string, RecordValue> {
    return Object.fromEntries(Object.entries(fields).map(([key, value]) => [key, Array.isArray(value) && value.length === 0 ? '' : value]));
}

function submit(): void {
    if (props.client) {
        // Envoi multipart : la méthode PUT est simulée (_method).
        form.transform((data) => ({ ...data, fields: multipartFields(data.fields), _method: 'put' })).post(route('clients.update', props.client.id), { forceFormData: true });
    } else {
        form.transform((data) => ({ ...data, fields: multipartFields(data.fields) })).post(route('clients.store'), { forceFormData: true });
    }
}
</script>

<template>
    <ClientHeader v-if="context" :context="context" active="overview" :title="title" :breadcrumbs="[{ label: 'Modifier' }]" />
    <PageHeader v-else :title="title" :breadcrumbs="[{ label: 'Clients', href: route('clients.index') }, { label: 'Nouveau' }]" />

    <form class="space-y-5" novalidate @submit.prevent="submit">
        <FormSection title="Identité" description="Nom, référence interne et responsable chez le prestataire.">
            <FormField id="name" label="Nom" :error="form.errors.name" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="name" v-model="form.name" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <FormField id="reference" label="Référence interne" :error="form.errors.reference" hint="Unique ; lettres, chiffres, points et tirets." required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="reference" v-model="form.reference" :described-by="describedBy" :invalid="invalid" required />
                </template>
            </FormField>
            <FormField id="account_manager_id" label="Technicien ou responsable interne" :error="form.errors.account_manager_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="account_manager_id" v-model="form.account_manager_id" :options="managers" placeholder="— Aucun —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="tags" label="Étiquettes" :error="form.errors.tags">
                <template #default="{ describedBy }">
                    <TagInput id="tags" v-model="form.tags" :available="tags" :described-by="describedBy" />
                </template>
            </FormField>
            <FormField id="description" label="Description" :error="form.errors.description" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="description" v-model="form.description" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <div class="sm:col-span-2">
                <FormField id="logo" label="Logo (facultatif)" :error="form.errors.logo" hint="PNG, JPEG ou WebP, 2 Mo maximum.">
                    <template #default="{ describedBy }">
                        <div class="flex items-center gap-4">
                            <img v-if="logoPreview && !form.remove_logo" :src="logoPreview" alt="Aperçu du logo" class="h-14 w-14 rounded-md border border-slate-200 object-contain" />
                            <input
                                id="logo"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                class="block text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-navy-900 file:px-3 file:py-1.5 file:text-sm file:text-white"
                                :aria-describedby="describedBy"
                                @change="onLogo"
                            />
                        </div>
                    </template>
                </FormField>
                <CheckboxInput v-if="client?.logo_url" id="remove_logo" v-model="form.remove_logo" label="Supprimer le logo actuel" class="mt-2" />
            </div>
        </FormSection>

        <FormSection title="Coordonnées" description="Adresse du siège ; les autres établissements se gèrent dans « Sites ».">
            <FormField id="address_line1" label="Adresse" :error="form.errors.address_line1">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="address_line1" v-model="form.address_line1" autocomplete="address-line1" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="address_line2" label="Complément d'adresse" :error="form.errors.address_line2">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="address_line2" v-model="form.address_line2" autocomplete="address-line2" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="postal_code" label="Code postal" :error="form.errors.postal_code">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="postal_code" v-model="form.postal_code" autocomplete="postal-code" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="city" label="Ville" :error="form.errors.city">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="city" v-model="form.city" autocomplete="address-level2" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="country" label="Pays" :error="form.errors.country">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="country" v-model="form.country" autocomplete="country-name" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="phone" label="Téléphone" :error="form.errors.phone">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="phone" v-model="form.phone" type="tel" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="email" label="E-mail" :error="form.errors.email">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="email" v-model="form.email" type="email" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="website" label="Site web" :error="form.errors.website">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="website" v-model="form.website" type="url" :placeholder="URL_PLACEHOLDER" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <FormSection title="Notes">
            <FormField id="notes" label="Notes internes" :error="form.errors.notes" class="sm:col-span-2" hint="N'y saisissez aucun mot de passe : utilisez la rubrique « Accès ».">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="notes" v-model="form.notes" :rows="5" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <RecordFieldsForm v-if="extension" :definition="extension" :values="form.fields" :errors="form.errors" id-prefix="extra" />

        <div class="flex flex-wrap justify-end gap-2">
            <Link :href="client ? route('clients.show', client.id) : route('clients.index')" class="btn-secondary">Annuler</Link>
            <button type="submit" class="btn-primary" :disabled="form.processing">{{ client ? 'Enregistrer' : 'Créer le client' }}</button>
        </div>
    </form>
</template>
