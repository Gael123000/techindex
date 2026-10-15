<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import ClientHeader from '@/components/ClientHeader.vue';
import FormField from '@/components/FormField.vue';
import FormSection from '@/components/FormSection.vue';
import RecordFieldsForm from '@/components/RecordFieldsForm.vue';
import SelectInput from '@/components/SelectInput.vue';
import TagInput from '@/components/TagInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, Option, RecordFormDefinition, RecordValue, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    context: ClientContext;
    recordType: { id: number; key: string; name: string; plural_name: string; description: string | null; icon: string; has_site: boolean; has_tags: boolean };
    record: { id: number; title: string; site_id: number | null; tags: string[] } | null;
    definition: RecordFormDefinition;
    sites: Option<number>[];
    tags: TagRef[];
}>();

const form = useForm({
    site_id: props.record?.site_id ?? null,
    tags: props.record?.tags ?? ([] as string[]),
    fields: { ...(props.definition.values ?? {}) } as Record<string, RecordValue>,
});

function submit(): void {
    if (props.record) {
        form.put(route('clients.records.update', [props.context.id, props.record.id]));
    } else {
        form.post(route('clients.records.store', [props.context.id, props.recordType.key]));
    }
}
</script>

<template>
    <ClientHeader
        :context="context"
        :active="`record:${recordType.key}`"
        :title="record ? `Modifier ${record.title}` : `Nouveau : ${recordType.name.toLowerCase()}`"
        :breadcrumbs="[{ label: recordType.plural_name, href: route('clients.records.index', [context.id, recordType.key]) }, { label: record ? record.title : 'Nouveau' }]"
    />

    <form class="space-y-5" novalidate @submit.prevent="submit">
        <RecordFieldsForm :definition="definition" :values="form.fields" :errors="form.errors" id-prefix="rec" />

        <FormSection v-if="recordType.has_site || recordType.has_tags" title="Rattachement">
            <FormField v-if="recordType.has_site" id="site_id" label="Site" :error="form.errors.site_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="site_id" v-model="form.site_id" :options="sites" placeholder="— Aucun —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField v-if="recordType.has_tags" id="tags" label="Étiquettes" :error="form.errors.tags">
                <template #default="{ describedBy }">
                    <TagInput id="tags" v-model="form.tags" :available="tags" :described-by="describedBy" />
                </template>
            </FormField>
        </FormSection>

        <div class="flex justify-end gap-2">
            <Link
                :href="record ? route('clients.records.show', [context.id, record.id]) : route('clients.records.index', [context.id, recordType.key])"
                class="btn-secondary"
            >
                Annuler
            </Link>
            <button type="submit" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </div>
    </form>
</template>
