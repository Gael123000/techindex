<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import CheckboxList from '@/components/CheckboxList.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import FormField from '@/components/FormField.vue';
import FormSection from '@/components/FormSection.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import SelectInput from '@/components/SelectInput.vue';
import TagInput from '@/components/TagInput.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, Option, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

interface DocumentData {
    id: number;
    title: string;
    content: string;
    category_id: number | null;
    status: string;
    owner_id: number | null;
    review_interval_days: number | null;
    equipment_ids: number[];
    service_ids: number[];
    tags: string[];
    current_version: number;
}

const props = defineProps<{
    context: ClientContext;
    document: DocumentData | null;
    defaults: { owner_id: number; review_interval_days: number; equipment_ids: number[]; service_ids: number[] } | null;
    categories: Option<number>[];
    statuses: Option[];
    owners: Option<number>[];
    equipment: Option<number>[];
    services: Option<number>[];
    tags: TagRef[];
}>();

const d = props.document;

const form = useForm({
    title: d?.title ?? '',
    content: d?.content ?? '',
    category_id: d?.category_id ?? null,
    status: d?.status ?? 'published',
    owner_id: d?.owner_id ?? props.defaults?.owner_id ?? null,
    review_interval_days: d?.review_interval_days ?? props.defaults?.review_interval_days ?? null,
    change_summary: '',
    equipment_ids: d?.equipment_ids ?? props.defaults?.equipment_ids ?? ([] as number[]),
    service_ids: d?.service_ids ?? props.defaults?.service_ids ?? ([] as number[]),
    tags: d?.tags ?? ([] as string[]),
});

function submit(): void {
    if (props.document) {
        form.put(route('clients.documents.update', [props.context.id, props.document.id]));
    } else {
        form.post(route('clients.documents.store', props.context.id));
    }
}
</script>

<template>
    <ClientHeader
        :context="context"
        active="documents"
        :title="document ? `Modifier « ${document.title} »` : 'Nouvelle fiche'"
        :breadcrumbs="[{ label: 'Documentation', href: route('clients.documents.index', context.id) }, { label: document ? document.title : 'Nouvelle' }]"
    />

    <form class="space-y-5" novalidate @submit.prevent="submit">
        <section class="card space-y-4 p-5">
            <FormField id="title" label="Titre" :error="form.errors.title" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="title" v-model="form.title" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <FormField id="content" label="Contenu" :error="form.errors.content" hint="Le contenu est nettoyé à l'enregistrement (scripts et styles supprimés). Ne saisissez aucun mot de passe : utilisez la rubrique « Accès ».">
                <template #default="{ describedBy, invalid }">
                    <RichTextEditor id="content" v-model="form.content" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField v-if="document" id="change_summary" label="Résumé de la modification" :error="form.errors.change_summary" hint="Facultatif ; affiché dans l'historique des versions.">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="change_summary" v-model="form.change_summary" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </section>

        <FormSection title="Classement et suivi" description="Le responsable vérifie régulièrement que la fiche reste exacte.">
            <FormField id="category_id" label="Catégorie" :error="form.errors.category_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="category_id" v-model="form.category_id" :options="categories" placeholder="— Aucune —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="status" label="Statut" :error="form.errors.status" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="status" v-model="form.status" :options="statuses" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="owner_id" label="Responsable" :error="form.errors.owner_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="owner_id" v-model="form.owner_id" :options="owners" placeholder="— Aucun —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="review_interval_days" label="Vérification tous les … jours" :error="form.errors.review_interval_days">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="review_interval_days" v-model="form.review_interval_days" type="number" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="tags" label="Étiquettes" :error="form.errors.tags" class="sm:col-span-2">
                <template #default="{ describedBy }">
                    <TagInput id="tags" v-model="form.tags" :available="tags" :described-by="describedBy" />
                </template>
            </FormField>
        </FormSection>

        <FormSection title="Éléments concernés" description="Équipements et services auxquels la fiche s'applique.">
            <div>
                <p class="mb-1 text-sm font-medium text-slate-700">Équipements</p>
                <CheckboxList id="equipment_ids" v-model="form.equipment_ids" :options="equipment" />
            </div>
            <div>
                <p class="mb-1 text-sm font-medium text-slate-700">Services</p>
                <CheckboxList id="service_ids" v-model="form.service_ids" :options="services" />
            </div>
        </FormSection>

        <div class="flex justify-end gap-2">
            <Link :href="document ? route('clients.documents.show', [context.id, document.id]) : route('clients.documents.index', context.id)" class="btn-secondary">Annuler</Link>
            <button type="submit" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </div>
    </form>
</template>
