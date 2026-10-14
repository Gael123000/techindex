<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Building2, Download, Plus } from '@lucide/vue';
import { route } from 'ziggy-js';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import SelectInput from '@/components/SelectInput.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TagList from '@/components/TagList.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import type { Option, Paginated, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

interface ClientRow {
    id: number;
    name: string;
    reference: string;
    city: string | null;
    status: 'active' | 'archived';
    updated_at: string;
    sites_count: number;
    equipment_count: number;
    account_manager: { id: number; name: string } | null;
    tags: TagRef[];
}

const props = defineProps<{
    clients: Paginated<ClientRow>;
    filters: { q: string; status: string; manager: number | null; tag: number | null; sort: string; direction: string };
    managers: Option<number>[];
    tags: TagRef[];
    can: { create: boolean; export: boolean };
}>();

const { filters, sortBy } = useFilters(() => route('clients.index'), { ...props.filters });

const statusOptions: Option[] = [
    { value: 'active', label: 'Actifs' },
    { value: 'archived', label: 'Archivés' },
    { value: 'all', label: 'Tous' },
];
</script>

<template>
    <PageHeader title="Clients" description="Entreprises clientes documentées dans TechIndex." :breadcrumbs="[{ label: 'Clients' }]">
        <template #actions>
            <template v-if="can.export">
                <a :href="route('exports.download', ['clients', 'xlsx'])" class="btn-secondary"><Download class="h-4 w-4" aria-hidden="true" /> Excel</a>
                <a :href="route('exports.download', ['clients', 'csv'])" class="btn-secondary"><Download class="h-4 w-4" aria-hidden="true" /> CSV</a>
            </template>
            <Link v-if="can.create" :href="route('clients.create')" class="btn-primary"><Plus class="h-4 w-4" aria-hidden="true" /> Nouveau client</Link>
        </template>
    </PageHeader>

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="filter-q" class="sr-only">Rechercher</label>
                <input id="filter-q" v-model="filters.q" type="search" class="form-input" placeholder="Nom, référence, ville…" />
            </div>
            <div>
                <label for="filter-status" class="sr-only">Statut</label>
                <SelectInput id="filter-status" v-model="filters.status" :options="statusOptions" />
            </div>
            <div>
                <label for="filter-manager" class="sr-only">Responsable</label>
                <SelectInput id="filter-manager" v-model="filters.manager" :options="managers" placeholder="Tous les responsables" />
            </div>
        </div>

        <div v-if="clients.data.length" class="overflow-x-auto">
            <table class="table-base">
                <caption class="sr-only">Liste des clients</caption>
                <thead>
                    <tr>
                        <SortableHeader column="name" label="Client" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortableHeader column="city" label="Ville" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th scope="col">Responsable</th>
                        <th scope="col">Inventaire</th>
                        <th scope="col">Étiquettes</th>
                        <SortableHeader column="updated_at" label="Mis à jour" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="client in clients.data" :key="client.id">
                        <td>
                            <Link :href="route('clients.show', client.id)" class="link">{{ client.name }}</Link>
                            <p class="text-xs text-slate-500">
                                {{ client.reference }}
                                <StatusBadge v-if="client.status === 'archived'" status="archived" class="ml-1" />
                            </p>
                        </td>
                        <td>{{ client.city ?? '—' }}</td>
                        <td>{{ client.account_manager?.name ?? '—' }}</td>
                        <td class="text-xs text-slate-600">{{ client.sites_count }} site(s) · {{ client.equipment_count }} équip.</td>
                        <td><TagList :tags="client.tags" /></td>
                        <td class="text-xs text-slate-600">{{ formatDate(client.updated_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState
            v-else
            :icon="Building2"
            title="Aucun client trouvé"
            :description="filters.q ? 'Aucun client ne correspond à votre recherche.' : 'Les clients qui vous sont attribués apparaîtront ici.'"
        >
            <Link v-if="can.create && !filters.q" :href="route('clients.create')" class="btn-primary"><Plus class="h-4 w-4" aria-hidden="true" /> Créer un client</Link>
        </EmptyState>

        <Pagination :paginator="clients" />
    </div>
</template>
