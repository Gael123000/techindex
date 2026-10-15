<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { route } from 'ziggy-js';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DynamicIcon from '@/components/DynamicIcon.vue';
import FormField from '@/components/FormField.vue';
import Modal from '@/components/Modal.vue';
import PageHeader from '@/components/PageHeader.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

interface EquipmentType {
    id: number;
    name: string;
    slug: string;
    icon: string;
    sort_order: number;
    equipment_count: number;
    fields_count: number;
}

defineProps<{ types: EquipmentType[]; icons: string[] }>();

const editing = ref<EquipmentType | null>(null);
const showForm = ref(false);
const toDelete = ref<EquipmentType | null>(null);
const form = useForm({ name: '', icon: 'box', sort_order: 0 });

function open(type: EquipmentType | null): void {
    editing.value = type;
    form.clearErrors();
    form.name = type?.name ?? '';
    form.icon = type?.icon ?? 'box';
    form.sort_order = type?.sort_order ?? 0;
    showForm.value = true;
}

function save(): void {
    const options = { preserveScroll: true, onSuccess: () => (showForm.value = false) };

    if (editing.value) {
        form.put(route('admin.equipment-types.update', editing.value.id), options);
    } else {
        form.post(route('admin.equipment-types.store'), options);
    }
}

function destroy(): void {
    if (toDelete.value) {
        router.delete(route('admin.equipment-types.destroy', toDelete.value.id), { preserveScroll: true, onFinish: () => (toDelete.value = null) });
    }
}
</script>

<template>
    <PageHeader title="Types d'équipements" description="Types proposés dans l'inventaire. Un type utilisé ne peut pas être supprimé." :breadcrumbs="[{ label: 'Administration' }, { label: 'Types d\'équipements' }]">
        <template #actions>
            <button type="button" class="btn-primary" @click="open(null)"><Plus class="h-4 w-4" aria-hidden="true" /> Nouveau type</button>
        </template>
    </PageHeader>

    <div class="card overflow-x-auto">
        <table class="table-base">
            <thead>
                <tr>
                    <th scope="col">Type</th>
                    <th scope="col">Ordre</th>
                    <th scope="col">Équipements</th>
                    <th scope="col">Champs spécifiques</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="type in types" :key="type.id">
                    <td><span class="inline-flex items-center gap-2"><DynamicIcon :name="type.icon" class="h-4 w-4 text-slate-500" /> {{ type.name }}</span></td>
                    <td>{{ type.sort_order }}</td>
                    <td>{{ type.equipment_count }}</td>
                    <td>{{ type.fields_count }}</td>
                    <td class="text-right whitespace-nowrap">
                        <button type="button" class="btn-ghost btn-sm" @click="open(type)"><Pencil class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Modifier {{ type.name }}</span></button>
                        <button v-if="type.equipment_count === 0" type="button" class="btn-ghost btn-sm text-red-700" @click="toDelete = type">
                            <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer {{ type.name }}</span>
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <Modal :show="showForm" :title="editing ? `Modifier ${editing.name}` : 'Nouveau type'" @close="showForm = false">
        <form id="type-form" class="space-y-4" @submit.prevent="save">
            <FormField id="type_name" label="Nom" :error="form.errors.name" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="type_name" v-model="form.name" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <fieldset>
                <legend class="mb-1 text-sm font-medium text-slate-700">Icône</legend>
                <div class="flex flex-wrap gap-2">
                    <label
                        v-for="icon in icons"
                        :key="icon"
                        class="flex cursor-pointer items-center justify-center rounded-md border p-2"
                        :class="form.icon === icon ? 'border-turquoise-600 bg-turquoise-50' : 'border-slate-200'"
                    >
                        <input v-model="form.icon" type="radio" name="icon" :value="icon" class="sr-only" />
                        <DynamicIcon :name="icon" class="h-5 w-5 text-slate-700" />
                        <span class="sr-only">{{ icon }}</span>
                    </label>
                </div>
            </fieldset>
            <FormField id="type_sort" label="Ordre d'affichage" :error="form.errors.sort_order">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="type_sort" v-model="form.sort_order" type="number" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="showForm = false">Annuler</button>
            <button type="submit" form="type-form" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </template>
    </Modal>

    <ConfirmDialog :show="toDelete !== null" title="Supprimer le type" :message="`Supprimer le type « ${toDelete?.name} » ?`" confirm-label="Supprimer" danger @close="toDelete = null" @confirm="destroy" />
</template>
