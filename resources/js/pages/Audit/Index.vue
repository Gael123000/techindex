<script setup lang="ts">
import { route } from 'ziggy-js';
import HistoryList from '@/components/HistoryList.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import SelectInput from '@/components/SelectInput.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AuditEntry, Option, Paginated } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    entries: Paginated<AuditEntry>;
    filters: { client: number | null; user: number | null; category: string | null; type: string | null; q: string; from: string | null; to: string | null };
    options: { clients: Option<number>[]; users: Option<number>[]; categories: Option[]; types: Option[] };
}>();

const { filters } = useFilters(() => route('audit.index'), { ...props.filters });
</script>

<template>
    <PageHeader
        title="Journal d'audit"
        description="Connexions, modifications, changements de droits, révélations de secrets, imports et exports."
        :breadcrumbs="[{ label: 'Journal d\'audit' }]"
    />

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="audit-category" class="mb-1 block text-xs font-medium text-slate-600">Catégorie</label>
                <SelectInput id="audit-category" v-model="filters.category" :options="options.categories" placeholder="Toutes" />
            </div>
            <div>
                <label for="audit-client" class="mb-1 block text-xs font-medium text-slate-600">Client</label>
                <SelectInput id="audit-client" v-model="filters.client" :options="options.clients" placeholder="Tous" />
            </div>
            <div v-if="options.users.length">
                <label for="audit-user" class="mb-1 block text-xs font-medium text-slate-600">Utilisateur</label>
                <SelectInput id="audit-user" v-model="filters.user" :options="options.users" placeholder="Tous" />
            </div>
            <div>
                <label for="audit-type" class="mb-1 block text-xs font-medium text-slate-600">Type d'élément</label>
                <SelectInput id="audit-type" v-model="filters.type" :options="options.types" placeholder="Tous" />
            </div>
            <div>
                <label for="audit-q" class="mb-1 block text-xs font-medium text-slate-600">Élément</label>
                <input id="audit-q" v-model="filters.q" type="search" class="form-input" placeholder="Nom de l'élément…" />
            </div>
            <div>
                <label for="audit-from" class="mb-1 block text-xs font-medium text-slate-600">Du</label>
                <input id="audit-from" v-model="filters.from" type="date" class="form-input" />
            </div>
            <div>
                <label for="audit-to" class="mb-1 block text-xs font-medium text-slate-600">Au</label>
                <input id="audit-to" v-model="filters.to" type="date" class="form-input" />
            </div>
        </div>
        <HistoryList :entries="entries.data" show-subject show-client />
        <Pagination :paginator="entries" />
    </div>
</template>
