<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, SlidersHorizontal, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import CheckboxInput from '@/components/CheckboxInput.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormField from '@/components/FormField.vue';
import Modal from '@/components/Modal.vue';
import PageHeader from '@/components/PageHeader.vue';
import SelectInput from '@/components/SelectInput.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

defineOptions({ layout: AppLayout });

interface Field {
    id: number;
    equipment_type_id: number | null;
    key: string;
    label: string;
    type: string;
    type_label: string;
    options: string[] | null;
    is_required: boolean;
    help_text: string | null;
    sort_order: number;
    equipment_type: { id: number; name: string } | null;
    values_count: number;
}

defineProps<{ fields: Field[]; types: Option[]; equipmentTypes: Option<number>[] }>();

const editing = ref<Field | null>(null);
const showForm = ref(false);
const toDelete = ref<Field | null>(null);
const optionsText = ref('');
const form = useForm({
    equipment_type_id: null as number | null,
    label: '',
    type: 'text',
    options: [] as string[],
    is_required: false,
    help_text: '',
    sort_order: 0,
});

function open(field: Field | null): void {
    editing.value = field;
    form.clearErrors();
    form.equipment_type_id = field?.equipment_type_id ?? null;
    form.label = field?.label ?? '';
    form.type = field?.type ?? 'text';
    form.is_required = field?.is_required ?? false;
    form.help_text = field?.help_text ?? '';
    form.sort_order = field?.sort_order ?? 0;
    optionsText.value = (field?.options ?? []).join('\n');
    showForm.value = true;
}

function save(): void {
    form.options = optionsText.value.split('\n').map((line) => line.trim()).filter(Boolean);
    const options = { preserveScroll: true, onSuccess: () => (showForm.value = false) };

    if (editing.value) {
        form.put(route('admin.custom-fields.update', editing.value.id), options);
    } else {
        form.post(route('admin.custom-fields.store'), options);
    }
}

function destroy(): void {
    if (toDelete.value) {
        router.delete(route('admin.custom-fields.destroy', toDelete.value.id), { preserveScroll: true, onFinish: () => (toDelete.value = null) });
    }
}
</script>

<template>
    <PageHeader
        title="Champs personnalisés"
        description="Champs typés ajoutés aux fiches équipement, pour tous les types ou un type précis."
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Champs personnalisés' }]"
    >
        <template #actions>
            <button type="button" class="btn-primary" @click="open(null)"><Plus class="h-4 w-4" aria-hidden="true" /> Nouveau champ</button>
        </template>
    </PageHeader>

    <div class="card overflow-x-auto">
        <table v-if="fields.length" class="table-base">
            <thead>
                <tr>
                    <th scope="col">Libellé</th>
                    <th scope="col">Type de valeur</th>
                    <th scope="col">S'applique à</th>
                    <th scope="col">Valeurs saisies</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="field in fields" :key="field.id">
                    <td>
                        {{ field.label }} <Badge v-if="field.is_required" color="amber" class="ml-1">obligatoire</Badge>
                        <p v-if="field.help_text" class="text-xs text-slate-500">{{ field.help_text }}</p>
                    </td>
                    <td>
                        {{ field.type_label }}
                        <p v-if="field.options?.length" class="text-xs text-slate-500">{{ field.options.join(', ') }}</p>
                    </td>
                    <td>{{ field.equipment_type?.name ?? 'Tous les types' }}</td>
                    <td>{{ field.values_count }}</td>
                    <td class="text-right whitespace-nowrap">
                        <button type="button" class="btn-ghost btn-sm" @click="open(field)"><Pencil class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Modifier {{ field.label }}</span></button>
                        <button type="button" class="btn-ghost btn-sm text-red-700" @click="toDelete = field"><Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer {{ field.label }}</span></button>
                    </td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-else :icon="SlidersHorizontal" title="Aucun champ personnalisé" description="Ajoutez par exemple « Nombre de ports » pour les switches ou « Capacité (To) » pour le stockage." />
    </div>

    <Modal :show="showForm" :title="editing ? `Modifier ${editing.label}` : 'Nouveau champ'" @close="showForm = false">
        <form id="field-form" class="space-y-4" @submit.prevent="save">
            <FormField id="field_label" label="Libellé" :error="form.errors.label" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="field_label" v-model="form.label" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <FormField id="field_type" label="Type de valeur" :error="form.errors.type" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="field_type" v-model="form.type" :options="types" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField v-if="form.type === 'select'" id="field_options" label="Choix possibles (un par ligne)" :error="form.errors.options" required>
                <template #default="{ describedBy, invalid }">
                    <TextArea id="field_options" v-model="optionsText" :rows="4" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="field_equipment_type" label="Type d'équipement" :error="form.errors.equipment_type_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="field_equipment_type" v-model="form.equipment_type_id" :options="equipmentTypes" placeholder="Tous les types" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="field_help" label="Aide" :error="form.errors.help_text">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="field_help" v-model="form.help_text" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="field_sort" label="Ordre" :error="form.errors.sort_order">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="field_sort" v-model="form.sort_order" type="number" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <CheckboxInput id="field_required" v-model="form.is_required" label="Saisie obligatoire" />
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="showForm = false">Annuler</button>
            <button type="submit" form="field-form" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </template>
    </Modal>

    <ConfirmDialog
        :show="toDelete !== null"
        title="Supprimer le champ"
        :message="`Le champ « ${toDelete?.label} » et ses ${toDelete?.values_count ?? 0} valeur(s) saisie(s) seront supprimés définitivement.`"
        confirm-label="Supprimer"
        danger
        :require-text="toDelete && toDelete.values_count > 0 ? toDelete.label : null"
        @close="toDelete = null"
        @confirm="destroy"
    />
</template>
