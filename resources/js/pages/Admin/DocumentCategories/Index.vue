<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { route } from 'ziggy-js';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import Modal from '@/components/Modal.vue';
import PageHeader from '@/components/PageHeader.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

interface Category {
    id: number;
    name: string;
    sort_order: number;
    documents_count: number;
}

defineProps<{ categories: Category[] }>();

const editing = ref<Category | null>(null);
const showForm = ref(false);
const toDelete = ref<Category | null>(null);
const form = useForm({ name: '', sort_order: 0 });

function open(category: Category | null): void {
    editing.value = category;
    form.clearErrors();
    form.name = category?.name ?? '';
    form.sort_order = category?.sort_order ?? 0;
    showForm.value = true;
}

function save(): void {
    const options = { preserveScroll: true, onSuccess: () => (showForm.value = false) };

    if (editing.value) {
        form.put(route('admin.document-categories.update', editing.value.id), options);
    } else {
        form.post(route('admin.document-categories.store'), options);
    }
}

function destroy(): void {
    if (toDelete.value) {
        router.delete(route('admin.document-categories.destroy', toDelete.value.id), { preserveScroll: true, onFinish: () => (toDelete.value = null) });
    }
}
</script>

<template>
    <PageHeader title="Catégories de documents" :breadcrumbs="[{ label: 'Administration' }, { label: 'Catégories de documents' }]">
        <template #actions>
            <button type="button" class="btn-primary" @click="open(null)"><Plus class="h-4 w-4" aria-hidden="true" /> Nouvelle catégorie</button>
        </template>
    </PageHeader>

    <div class="card overflow-x-auto">
        <table class="table-base">
            <thead>
                <tr>
                    <th scope="col">Catégorie</th>
                    <th scope="col">Ordre</th>
                    <th scope="col">Fiches</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="category in categories" :key="category.id">
                    <td>{{ category.name }}</td>
                    <td>{{ category.sort_order }}</td>
                    <td>{{ category.documents_count }}</td>
                    <td class="text-right whitespace-nowrap">
                        <button type="button" class="btn-ghost btn-sm" @click="open(category)"><Pencil class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Modifier {{ category.name }}</span></button>
                        <button type="button" class="btn-ghost btn-sm text-red-700" @click="toDelete = category"><Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer {{ category.name }}</span></button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <Modal :show="showForm" :title="editing ? `Modifier ${editing.name}` : 'Nouvelle catégorie'" size="sm" @close="showForm = false">
        <form id="category-form" class="space-y-4" @submit.prevent="save">
            <FormField id="category_name" label="Nom" :error="form.errors.name" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="category_name" v-model="form.name" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <FormField id="category_sort" label="Ordre" :error="form.errors.sort_order">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="category_sort" v-model="form.sort_order" type="number" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="showForm = false">Annuler</button>
            <button type="submit" form="category-form" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </template>
    </Modal>

    <ConfirmDialog
        :show="toDelete !== null"
        title="Supprimer la catégorie"
        :message="`Supprimer « ${toDelete?.name} » ? Les ${toDelete?.documents_count ?? 0} fiche(s) concernée(s) seront conservées sans catégorie.`"
        confirm-label="Supprimer"
        danger
        @close="toDelete = null"
        @confirm="destroy"
    />
</template>
