<script setup lang="ts">
import { route } from 'ziggy-js';
import ClientHeader from '@/components/ClientHeader.vue';
import HistoryList from '@/components/HistoryList.vue';
import Pagination from '@/components/Pagination.vue';
import SelectInput from '@/components/SelectInput.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AuditEntry, ClientContext, Option, Paginated } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    context: ClientContext;
    entries: Paginated<AuditEntry>;
    filters: { type: string | null; user: number | null; from: string | null; to: string | null };
    types: Option[];
}>();

const { filters } = useFilters(() => route('clients.history', props.context.id), { ...props.filters });
</script>

<template>
    <ClientHeader
        :context="context"
        active="history"
        title="Historique"
        description="Créations, modifications, archivages, révélations de secrets, imports et exports. Les valeurs des secrets ne sont jamais enregistrées."
        :breadcrumbs="[{ label: 'Historique' }]"
    />

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-3">
            <div>
                <label for="hist-type" class="mb-1 block text-xs font-medium text-slate-600">Type d'élément</label>
                <SelectInput id="hist-type" v-model="filters.type" :options="types" placeholder="Tous" />
            </div>
            <div>
                <label for="hist-from" class="mb-1 block text-xs font-medium text-slate-600">Du</label>
                <input id="hist-from" v-model="filters.from" type="date" class="form-input" />
            </div>
            <div>
                <label for="hist-to" class="mb-1 block text-xs font-medium text-slate-600">Au</label>
                <input id="hist-to" v-model="filters.to" type="date" class="form-input" />
            </div>
        </div>
        <HistoryList :entries="entries.data" show-subject />
        <Pagination :paginator="entries" />
    </div>
</template>
