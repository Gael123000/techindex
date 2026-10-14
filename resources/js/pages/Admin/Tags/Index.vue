<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Tags, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormField from '@/components/FormField.vue';
import Modal from '@/components/Modal.vue';
import PageHeader from '@/components/PageHeader.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

interface TagRow {
    id: number;
    name: string;
    color: string;
    usage: number;
}

defineProps<{ tags: TagRow[]; colors: string[] }>();

const colorLabels: Record<string, string> = {
    slate: 'Gris',
    blue: 'Bleu',
    teal: 'Turquoise',
    green: 'Vert',
    amber: 'Ambre',
    orange: 'Orange',
    red: 'Rouge',
    violet: 'Violet',
};

const editing = ref<TagRow | null>(null);
const showForm = ref(false);
const toDelete = ref<TagRow | null>(null);
const form = useForm({ name: '', color: 'slate' });

function open(tag: TagRow | null): void {
    editing.value = tag;
    form.clearErrors();
    form.name = tag?.name ?? '';
    form.color = tag?.color ?? 'slate';
    showForm.value = true;
}

function save(): void {
    const options = { preserveScroll: true, onSuccess: () => (showForm.value = false) };

    if (editing.value) {
        form.put(route('admin.tags.update', editing.value.id), options);
    } else {
        form.post(route('admin.tags.store'), options);
    }
}

function destroy(): void {
    if (toDelete.value) {
        router.delete(route('admin.tags.destroy', toDelete.value.id), { preserveScroll: true, onFinish: () => (toDelete.value = null) });
    }
}
</script>

<template>
    <PageHeader title="Étiquettes" description="Étiquettes partagées entre clients, équipements, services et documents." :breadcrumbs="[{ label: 'Administration' }, { label: 'Étiquettes' }]">
        <template #actions>
            <button type="button" class="btn-primary" @click="open(null)"><Plus class="h-4 w-4" aria-hidden="true" /> Nouvelle étiquette</button>
        </template>
    </PageHeader>

    <div class="card overflow-x-auto">
        <table v-if="tags.length" class="table-base">
            <thead>
                <tr>
                    <th scope="col">Étiquette</th>
                    <th scope="col">Utilisations</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="tag in tags" :key="tag.id">
                    <td><Badge :color="tag.color">{{ tag.name }}</Badge></td>
                    <td>{{ tag.usage }}</td>
                    <td class="text-right whitespace-nowrap">
                        <button type="button" class="btn-ghost btn-sm" @click="open(tag)"><Pencil class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Modifier {{ tag.name }}</span></button>
                        <button type="button" class="btn-ghost btn-sm text-red-700" @click="toDelete = tag"><Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer {{ tag.name }}</span></button>
                    </td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-else :icon="Tags" title="Aucune étiquette" description="Les étiquettes peuvent aussi être créées directement depuis les formulaires." />
    </div>

    <Modal :show="showForm" :title="editing ? `Modifier ${editing.name}` : 'Nouvelle étiquette'" size="sm" @close="showForm = false">
        <form id="tag-form" class="space-y-4" @submit.prevent="save">
            <FormField id="tag_name" label="Nom" :error="form.errors.name" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="tag_name" v-model="form.name" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <fieldset>
                <legend class="mb-1 text-sm font-medium text-slate-700">Couleur</legend>
                <div class="flex flex-wrap gap-2">
                    <label v-for="color in colors" :key="color" class="cursor-pointer rounded-full p-0.5" :class="form.color === color ? 'ring-2 ring-turquoise-600' : ''">
                        <input v-model="form.color" type="radio" name="color" :value="color" class="sr-only" />
                        <Badge :color="color">{{ colorLabels[color] ?? color }}</Badge>
                    </label>
                </div>
            </fieldset>
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="showForm = false">Annuler</button>
            <button type="submit" form="tag-form" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </template>
    </Modal>

    <ConfirmDialog
        :show="toDelete !== null"
        title="Supprimer l'étiquette"
        :message="`L'étiquette « ${toDelete?.name} » sera retirée de ${toDelete?.usage ?? 0} élément(s).`"
        confirm-label="Supprimer"
        danger
        @close="toDelete = null"
        @confirm="destroy"
    />
</template>
