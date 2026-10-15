<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Download, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import ClientHeader from '@/components/ClientHeader.vue';
import DynamicIcon from '@/components/DynamicIcon.vue';
import EmptyState from '@/components/EmptyState.vue';
import Pagination from '@/components/Pagination.vue';
import SelectInput from '@/components/SelectInput.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TagList from '@/components/TagList.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import type { ClientContext, Option, Paginated, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

interface RecordRow {
    id: number;
    title: string;
    status: string;
    site: { id: number; name: string } | null;
    tags: TagRef[];
    updated_at: string;
    cells: Record<string, string | null>;
}

const props = defineProps<{
    context: ClientContext;
    recordType: { id: number; key: string; name: string; plural_name: string; description: string | null; icon: string; has_site: boolean; has_tags: boolean };
    columns: Array<{ key: string; label: string; type: string }>;
    records: Paginated<RecordRow>;
    filters: { q: string; status: string; site: number | null; sort: string; direction: string };
    sites: Option<number>[];
}>();

const { filters, sortBy } = useFilters(() => route('clients.records.index', [props.context.id, props.recordType.key]), { ...props.filters });
const statusOptions: Option[] = [
    { value: 'active', label: 'Actives' },
    { value: 'archived', label: 'Archivées' },
    { value: 'all', label: 'Toutes' },
];
const canCreate = props.context.permissions.edit && props.context.status === 'active';
const numericTypes = ['integer', 'decimal', 'currency'];
const hasFilters = computed(() => props.filters.q !== '' || props.filters.site !== null || props.filters.status !== 'active');
</script>

<template>
    <ClientHeader
        :context="context"
        :active="`record:${recordType.key}`"
        :title="recordType.plural_name"
        :description="recordType.description ?? undefined"
        :breadcrumbs="[{ label: recordType.plural_name }]"
    >
        <template #actions>
            <a v-if="context.permissions.export && records.total" :href="route('clients.records.export', [context.id, recordType.key, 'xlsx'])" class="btn-secondary">
                <Download class="h-4 w-4" aria-hidden="true" /> Exporter
            </a>
            <Link v-if="canCreate" :href="route('clients.records.create', [context.id, recordType.key])" class="btn-primary">
                <Plus class="h-4 w-4" aria-hidden="true" /> Nouveau : {{ recordType.name.toLowerCase() }}
            </Link>
        </template>
    </ClientHeader>

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-3">
            <div>
                <label for="rec-q" class="sr-only">Rechercher</label>
                <input id="rec-q" v-model="filters.q" type="search" class="form-input" placeholder="Rechercher…" />
            </div>
            <div>
                <label for="rec-status" class="sr-only">Statut</label>
                <SelectInput id="rec-status" v-model="filters.status" :options="statusOptions" />
            </div>
            <div v-if="recordType.has_site && sites.length">
                <label for="rec-site" class="sr-only">Site</label>
                <SelectInput id="rec-site" v-model="filters.site" :options="sites" placeholder="Tous les sites" />
            </div>
        </div>

        <div v-if="records.data.length" class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <SortableHeader column="title" :label="recordType.name" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortableHeader v-for="column in columns" :key="column.key" :column="column.key" :label="column.label" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th v-if="recordType.has_site" scope="col">Site</th>
                        <SortableHeader column="updated_at" label="Modifié" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="record in records.data" :key="record.id">
                        <td>
                            <span class="flex items-center gap-2">
                                <DynamicIcon :name="recordType.icon" class="h-4 w-4 shrink-0 text-slate-400" />
                                <Link :href="route('clients.records.show', [context.id, record.id])" class="link">{{ record.title }}</Link>
                                <StatusBadge v-if="record.status === 'archived'" status="archived" />
                            </span>
                            <TagList v-if="record.tags.length" :tags="record.tags" class="mt-1" />
                        </td>
                        <td v-for="column in columns" :key="column.key" :class="numericTypes.includes(column.type) ? 'text-right tabular-nums' : ''">
                            <span :class="['ip', 'cidr'].includes(column.type) ? 'font-mono text-xs' : ''">{{ record.cells[column.key] ?? '—' }}</span>
                        </td>
                        <td v-if="recordType.has_site">{{ record.site?.name ?? '—' }}</td>
                        <td class="text-xs whitespace-nowrap text-slate-500">{{ formatDateTime(record.updated_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState
            v-else
            :icon="undefined"
            :title="hasFilters ? 'Aucun résultat' : `Aucun élément : ${recordType.plural_name.toLowerCase()}`"
            :description="hasFilters ? 'Modifiez la recherche ou les filtres.' : (recordType.description ?? undefined)"
        >
            <Link v-if="canCreate && !hasFilters" :href="route('clients.records.create', [context.id, recordType.key])" class="btn-primary">
                <Plus class="h-4 w-4" aria-hidden="true" /> Ajouter
            </Link>
        </EmptyState>
        <Pagination :paginator="records" />
    </div>
</template>
