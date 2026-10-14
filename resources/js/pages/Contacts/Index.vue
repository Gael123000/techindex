<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Users } from '@lucide/vue';
import { ref } from 'vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import CheckboxInput from '@/components/CheckboxInput.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormField from '@/components/FormField.vue';
import Modal from '@/components/Modal.vue';
import Pagination from '@/components/Pagination.vue';
import SelectInput from '@/components/SelectInput.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, Option, Paginated } from '@/types';

defineOptions({ layout: AppLayout });

interface ContactRow {
    id: number;
    site_id: number | null;
    first_name: string | null;
    last_name: string;
    full_name: string;
    job_title: string | null;
    email: string | null;
    phone: string | null;
    mobile: string | null;
    is_technical: boolean;
    is_primary: boolean;
    notes: string | null;
    site: { id: number; name: string } | null;
}

const props = defineProps<{
    context: ClientContext;
    contacts: Paginated<ContactRow>;
    filters: { q: string; site: number | null; technical: boolean };
    sites: Option<number>[];
}>();

const { filters } = useFilters(() => route('clients.contacts.index', props.context.id), { ...props.filters });
const canEdit = props.context.permissions.edit && props.context.status === 'active';

const showForm = ref(false);
const editing = ref<ContactRow | null>(null);
const toDelete = ref<ContactRow | null>(null);

const form = useForm({
    site_id: null as number | null,
    first_name: '',
    last_name: '',
    job_title: '',
    email: '',
    phone: '',
    mobile: '',
    is_technical: false,
    is_primary: false,
    notes: '',
});

function open(contact: ContactRow | null): void {
    editing.value = contact;
    form.clearErrors();
    form.site_id = contact?.site_id ?? null;
    form.first_name = contact?.first_name ?? '';
    form.last_name = contact?.last_name ?? '';
    form.job_title = contact?.job_title ?? '';
    form.email = contact?.email ?? '';
    form.phone = contact?.phone ?? '';
    form.mobile = contact?.mobile ?? '';
    form.is_technical = contact?.is_technical ?? false;
    form.is_primary = contact?.is_primary ?? false;
    form.notes = contact?.notes ?? '';
    showForm.value = true;
}

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => (showForm.value = false) };

    if (editing.value) {
        form.put(route('clients.contacts.update', [props.context.id, editing.value.id]), options);
    } else {
        form.post(route('clients.contacts.store', props.context.id), options);
    }
}

function destroy(): void {
    if (toDelete.value) {
        router.delete(route('clients.contacts.destroy', [props.context.id, toDelete.value.id]), {
            preserveScroll: true,
            onFinish: () => (toDelete.value = null),
        });
    }
}

const textFields: Array<{ key: 'first_name' | 'last_name' | 'job_title' | 'email' | 'phone' | 'mobile'; label: string; type?: string; required?: boolean }> = [
    { key: 'first_name', label: 'Prénom' },
    { key: 'last_name', label: 'Nom', required: true },
    { key: 'job_title', label: 'Fonction' },
    { key: 'email', label: 'E-mail', type: 'email' },
    { key: 'phone', label: 'Téléphone', type: 'tel' },
    { key: 'mobile', label: 'Mobile', type: 'tel' },
];
</script>

<template>
    <ClientHeader :context="context" active="contacts" title="Contacts et interlocuteurs" :breadcrumbs="[{ label: 'Contacts' }]">
        <template #actions>
            <button v-if="canEdit" type="button" class="btn-primary" @click="open(null)"><Plus class="h-4 w-4" aria-hidden="true" /> Nouveau contact</button>
        </template>
    </ClientHeader>

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="contacts-q" class="sr-only">Rechercher un contact</label>
                <input id="contacts-q" v-model="filters.q" type="search" class="form-input" placeholder="Nom, e-mail, fonction…" />
            </div>
            <div>
                <label for="contacts-site" class="sr-only">Site</label>
                <SelectInput id="contacts-site" v-model="filters.site" :options="sites" placeholder="Tous les sites" />
            </div>
            <CheckboxInput id="contacts-technical" v-model="filters.technical" label="Contacts techniques uniquement" class="self-center" />
        </div>

        <div v-if="contacts.data.length" class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th scope="col">Contact</th>
                        <th scope="col">Site</th>
                        <th scope="col">Coordonnées</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="contact in contacts.data" :key="contact.id">
                        <td>
                            <p class="font-medium text-slate-900">
                                {{ contact.full_name }}
                                <Badge v-if="contact.is_primary" color="teal" class="ml-1">Principal</Badge>
                                <Badge v-if="contact.is_technical" color="blue" class="ml-1">Technique</Badge>
                            </p>
                            <p class="text-xs text-slate-500">{{ contact.job_title }}</p>
                            <p v-if="contact.notes" class="mt-1 text-xs whitespace-pre-line text-slate-500">{{ contact.notes }}</p>
                        </td>
                        <td>{{ contact.site?.name ?? '—' }}</td>
                        <td class="text-xs">
                            <a v-if="contact.email" :href="`mailto:${contact.email}`" class="link block">{{ contact.email }}</a>
                            <a v-if="contact.phone" :href="`tel:${contact.phone}`" class="link block">{{ contact.phone }}</a>
                            <a v-if="contact.mobile" :href="`tel:${contact.mobile}`" class="link block">{{ contact.mobile }} (mobile)</a>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <template v-if="canEdit">
                                <button type="button" class="btn-ghost btn-sm" @click="open(contact)">
                                    <Pencil class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Modifier {{ contact.full_name }}</span>
                                </button>
                                <button type="button" class="btn-ghost btn-sm text-red-700" @click="toDelete = contact">
                                    <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer {{ contact.full_name }}</span>
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else :icon="Users" title="Aucun contact" description="Enregistrez les interlocuteurs du client et leurs coordonnées." />
        <Pagination :paginator="contacts" />
    </div>

    <Modal :show="showForm" :title="editing ? `Modifier ${editing.full_name}` : 'Nouveau contact'" size="lg" @close="showForm = false">
        <form id="contact-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <FormField v-for="field in textFields" :id="`contact_${field.key}`" :key="field.key" :label="field.label" :error="form.errors[field.key]" :required="field.required">
                <template #default="{ describedBy, invalid }">
                    <TextInput :id="`contact_${field.key}`" v-model="form[field.key]" :type="field.type" :described-by="describedBy" :invalid="invalid" :required="field.required" />
                </template>
            </FormField>
            <FormField id="contact_site" label="Site" :error="form.errors.site_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="contact_site" v-model="form.site_id" :options="sites" placeholder="— Aucun —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <div class="flex flex-col justify-end gap-2">
                <CheckboxInput id="contact_primary" v-model="form.is_primary" label="Contact principal" />
                <CheckboxInput id="contact_technical" v-model="form.is_technical" label="Interlocuteur technique" />
            </div>
            <FormField id="contact_notes" label="Notes" :error="form.errors.notes" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="contact_notes" v-model="form.notes" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="showForm = false">Annuler</button>
            <button type="submit" form="contact-form" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </template>
    </Modal>

    <ConfirmDialog
        :show="toDelete !== null"
        title="Supprimer le contact"
        :message="`Supprimer ${toDelete?.full_name} ?`"
        confirm-label="Supprimer"
        danger
        @close="toDelete = null"
        @confirm="destroy"
    />
</template>
