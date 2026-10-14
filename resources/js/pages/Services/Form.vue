<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import CheckboxInput from '@/components/CheckboxInput.vue';
import CheckboxList from '@/components/CheckboxList.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import FormField from '@/components/FormField.vue';
import FormSection from '@/components/FormSection.vue';
import SelectInput from '@/components/SelectInput.vue';
import TagInput from '@/components/TagInput.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, Option, ServiceCategoryDefinition, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

// Exemple affiché dans les champs d'adresse web.
const URL_PLACEHOLDER = 'https://';

interface ServiceData {
    id: number;
    site_id: number | null;
    category: string;
    name: string;
    provider: string | null;
    reference: string | null;
    url: string | null;
    status: string;
    start_date: string | null;
    end_date: string | null;
    description: string | null;
    notes: string | null;
    details: Record<string, string | number | boolean | null>;
    equipment_ids: number[];
    tags: string[];
}

const props = defineProps<{
    context: ClientContext;
    service: ServiceData | null;
    defaultCategory: string | null;
    categories: ServiceCategoryDefinition[];
    statuses: Option[];
    sites: Option<number>[];
    equipment: Option<number>[];
    tags: TagRef[];
}>();

const s = props.service;

const form = useForm({
    category: s?.category ?? props.defaultCategory ?? 'internet',
    site_id: s?.site_id ?? null,
    name: s?.name ?? '',
    provider: s?.provider ?? '',
    reference: s?.reference ?? '',
    url: s?.url ?? '',
    status: s?.status ?? 'active',
    start_date: s?.start_date ?? '',
    end_date: s?.end_date ?? '',
    description: s?.description ?? '',
    notes: s?.notes ?? '',
    details: { ...(s?.details ?? {}) } as Record<string, string | number | boolean | null>,
    equipment_ids: s?.equipment_ids ?? ([] as number[]),
    tags: s?.tags ?? ([] as string[]),
});

const categoryOptions = computed(() => props.categories.map((category) => ({ value: category.value, label: category.label })));
const definition = computed(() => props.categories.find((category) => category.value === form.category));

function submit(): void {
    if (props.service) {
        form.put(route('clients.services.update', [props.context.id, props.service.id]));
    } else {
        form.post(route('clients.services.store', props.context.id));
    }
}
</script>

<template>
    <ClientHeader
        :context="context"
        active="services"
        :title="service ? `Modifier ${service.name}` : 'Nouveau service'"
        :breadcrumbs="[{ label: 'Services', href: route('clients.services.index', context.id) }, { label: service ? service.name : 'Nouveau' }]"
    />

    <form class="space-y-5" novalidate @submit.prevent="submit">
        <FormSection title="Service">
            <FormField id="category" label="Catégorie" :error="form.errors.category" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="category" v-model="form.category" :options="categoryOptions" :described-by="describedBy" :invalid="invalid" required />
                </template>
            </FormField>
            <FormField id="name" label="Nom" :error="form.errors.name" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="name" v-model="form.name" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <FormField id="provider" :label="definition?.provider_label ?? 'Fournisseur'" :error="form.errors.provider">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="provider" v-model="form.provider" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="reference" label="Référence (contrat, compte, client)" :error="form.errors.reference">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="reference" v-model="form.reference" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="url" label="URL (console, portail)" :error="form.errors.url">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="url" v-model="form.url" type="url" :placeholder="URL_PLACEHOLDER" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="status" label="Statut" :error="form.errors.status" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="status" v-model="form.status" :options="statuses" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="site_id" label="Site" :error="form.errors.site_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="site_id" v-model="form.site_id" :options="sites" placeholder="— Aucun —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="tags" label="Étiquettes" :error="form.errors.tags">
                <template #default="{ describedBy }">
                    <TagInput id="tags" v-model="form.tags" :available="tags" :described-by="describedBy" />
                </template>
            </FormField>
            <FormField id="start_date" label="Date de début" :error="form.errors.start_date">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="start_date" v-model="form.start_date" type="date" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="end_date" label="Échéance / renouvellement" :error="form.errors.end_date" hint="Apparaît au tableau de bord à l'approche de la date.">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="end_date" v-model="form.end_date" type="date" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <FormSection v-if="definition && definition.fields.length" :title="`Informations ${definition.label.toLowerCase()}`" description="Champs structurés propres à la catégorie.">
            <template v-for="field in definition.fields" :key="field.key">
                <CheckboxInput
                    v-if="field.type === 'boolean'"
                    :id="`details-${field.key}`"
                    :model-value="Boolean(form.details[field.key])"
                    :label="field.label"
                    :description="field.help"
                    @update:model-value="form.details[field.key] = $event ?? false"
                />
                <FormField
                    v-else
                    :id="`details-${field.key}`"
                    :label="field.label"
                    :error="form.errors[`details.${field.key}` as keyof typeof form.errors]"
                    :hint="field.help"
                    :class="field.type === 'textarea' ? 'sm:col-span-2' : ''"
                >
                    <template #default="{ describedBy, invalid }">
                        <SelectInput
                            v-if="field.type === 'select'"
                            :id="`details-${field.key}`"
                            :model-value="(form.details[field.key] as string | null) ?? null"
                            :options="(field.options ?? []).map((option) => ({ value: option, label: option }))"
                            placeholder="—"
                            :described-by="describedBy"
                            :invalid="invalid"
                            @update:model-value="form.details[field.key] = $event ?? null"
                        />
                        <TextArea
                            v-else-if="field.type === 'textarea'"
                            :id="`details-${field.key}`"
                            :model-value="(form.details[field.key] as string | null) ?? ''"
                            :described-by="describedBy"
                            :invalid="invalid"
                            @update:model-value="form.details[field.key] = $event ?? null"
                        />
                        <TextInput
                            v-else
                            :id="`details-${field.key}`"
                            :model-value="(form.details[field.key] as string | number | null) ?? ''"
                            :type="field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'"
                            :described-by="describedBy"
                            :invalid="invalid"
                            @update:model-value="form.details[field.key] = $event ?? null"
                        />
                    </template>
                </FormField>
            </template>
        </FormSection>

        <FormSection title="Équipements concernés" description="Équipements qui fournissent ou utilisent ce service.">
            <div class="sm:col-span-2">
                <CheckboxList id="equipment_ids" v-model="form.equipment_ids" :options="equipment" empty-text="Aucun équipement inventorié pour ce client." />
                <p v-if="form.errors.equipment_ids" class="mt-1 text-xs text-red-700" role="alert">{{ form.errors.equipment_ids }}</p>
            </div>
        </FormSection>

        <FormSection title="Description et notes">
            <FormField id="description" label="Description" :error="form.errors.description" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="description" v-model="form.description" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="notes" label="Notes" :error="form.errors.notes" class="sm:col-span-2" hint="N'y saisissez aucun mot de passe : utilisez les accès sécurisés.">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="notes" v-model="form.notes" :rows="4" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </FormSection>

        <div class="flex justify-end gap-2">
            <Link :href="service ? route('clients.services.show', [context.id, service.id]) : route('clients.services.index', context.id)" class="btn-secondary">Annuler</Link>
            <button type="submit" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </div>
    </form>
</template>
