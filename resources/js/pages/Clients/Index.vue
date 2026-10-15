<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Building2, ChevronDown, ChevronsDownUp, ChevronsUpDown, CornerDownRight, Download, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
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
    group_role: string | null;
    group_role_label: string | null;
    /** Entreprise mère (liste filtrée, si elle est visible). */
    parent: { id: number; name: string } | null;
    /** Entreprises rattachées (affichage en groupe). */
    children: ClientRow[];
}

interface DisplayRow {
    client: ClientRow;
    depth: number;
    members: number;
}

const props = defineProps<{
    clients: Paginated<ClientRow>;
    /** Vrai sans recherche ni filtre : les groupes sont affichés en arbre. */
    tree: boolean;
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

/* ---------- Groupes repliés (dépliés par défaut, mémorisés dans le navigateur) ---------- */

const COLLAPSED_KEY = 'techindex.clients.collapsed';

function readCollapsed(): Set<number> {
    try {
        const value: unknown = JSON.parse(window.localStorage.getItem(COLLAPSED_KEY) ?? '[]');

        return new Set(Array.isArray(value) ? value.filter((id): id is number => typeof id === 'number') : []);
    } catch {
        return new Set();
    }
}

const collapsed = ref(readCollapsed());

function saveCollapsed(): void {
    try {
        window.localStorage.setItem(COLLAPSED_KEY, JSON.stringify([...collapsed.value].slice(-500)));
    } catch {
        // Préférence non mémorisée.
    }
}

function toggle(client: ClientRow): void {
    const next = new Set(collapsed.value);

    if (next.has(client.id)) {
        next.delete(client.id);
    } else {
        next.add(client.id);
    }

    collapsed.value = next;
    saveCollapsed();
}

function countMembers(client: ClientRow): number {
    return client.children.reduce((total, child) => total + 1 + countMembers(child), 0);
}

const groupIds = computed(() => {
    const ids: number[] = [];
    const walk = (list: ClientRow[]): void => list.forEach((client) => {
        if (client.children.length) {
            ids.push(client.id);
            walk(client.children);
        }
    });
    walk(props.clients.data);

    return ids;
});

const allCollapsed = computed(() => groupIds.value.length > 0 && groupIds.value.every((id) => collapsed.value.has(id)));

function toggleAll(): void {
    const next = new Set(collapsed.value);
    groupIds.value.forEach((id) => (allCollapsed.value ? next.delete(id) : next.add(id)));
    collapsed.value = next;
    saveCollapsed();
}

const rows = computed<DisplayRow[]>(() => {
    const result: DisplayRow[] = [];
    const walk = (list: ClientRow[], depth: number): void => list.forEach((client) => {
        result.push({ client, depth, members: countMembers(client) });

        if (client.children.length && !collapsed.value.has(client.id)) {
            walk(client.children, depth + 1);
        }
    });
    walk(props.clients.data, 0);

    return result;
});

/* Libellé du rôle : celui choisi, sinon « Tête de groupe » pour une entreprise qui en a d'autres. */
function roleLabel(row: DisplayRow): string | null {
    return row.client.group_role_label ?? (row.client.children.length ? 'Tête de groupe' : null);
}
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

        <div v-if="tree && groupIds.length" class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50/60 px-4 py-2 text-xs text-slate-500">
            <span>Les entreprises rattachées s'affichent sous leur entreprise mère.</span>
            <button type="button" class="btn-ghost btn-sm" @click="toggleAll">
                <component :is="allCollapsed ? ChevronsUpDown : ChevronsDownUp" class="h-3.5 w-3.5" aria-hidden="true" />
                {{ allCollapsed ? 'Tout déplier' : 'Tout replier' }}
            </button>
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
                    <tr v-for="row in rows" :key="row.client.id" :class="row.depth > 0 ? 'bg-slate-100/70 hover:bg-slate-100' : ''">
                        <td class="relative">
                            <!-- Entreprise rattachée : trait d'accent et décalage selon le niveau -->
                            <span v-if="row.depth > 0" class="absolute inset-y-0 left-0 w-0.5 bg-turquoise-400" aria-hidden="true" />
                            <div class="flex items-start gap-1.5" :style="row.depth > 0 ? { paddingLeft: `${(row.depth - 1) * 1.25 + 0.25}rem` } : undefined">
                                <template v-if="tree">
                                    <button
                                        v-if="row.client.children.length"
                                        type="button"
                                        class="-ml-1 flex h-5 w-5 shrink-0 items-center justify-center rounded text-slate-500 hover:bg-slate-200 hover:text-navy-900"
                                        :aria-expanded="!collapsed.has(row.client.id)"
                                        :aria-label="`${collapsed.has(row.client.id) ? 'Déplier' : 'Replier'} les entreprises rattachées à ${row.client.name}`"
                                        @click="toggle(row.client)"
                                    >
                                        <ChevronDown class="h-4 w-4 transition-transform" :class="collapsed.has(row.client.id) ? '-rotate-90' : ''" aria-hidden="true" />
                                    </button>
                                    <CornerDownRight v-else-if="row.depth > 0" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                                    <span v-else class="w-4 shrink-0" aria-hidden="true" />
                                </template>
                                <div class="min-w-0">
                                    <Link :href="route('clients.show', row.client.id)" class="link">{{ row.client.name }}</Link>
                                    <span
                                        v-if="row.members"
                                        class="ml-2 rounded-full bg-turquoise-50 px-2 py-0.5 text-[11px] font-medium text-turquoise-800 ring-1 ring-turquoise-200 ring-inset"
                                    >
                                        Groupe · {{ row.members }} entreprise{{ row.members > 1 ? 's' : '' }}
                                    </span>
                                    <p class="text-xs text-slate-500">
                                        {{ row.client.reference }}
                                        <template v-if="roleLabel(row)"> · {{ roleLabel(row) }}</template>
                                        <template v-if="!tree && row.client.parent">
                                            de <Link :href="route('clients.show', row.client.parent.id)" class="hover:text-navy-900 hover:underline">{{ row.client.parent.name }}</Link>
                                        </template>
                                        <StatusBadge v-if="row.client.status === 'archived'" status="archived" class="ml-1" />
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td>{{ row.client.city ?? '—' }}</td>
                        <td>{{ row.client.account_manager?.name ?? '—' }}</td>
                        <td class="text-xs text-slate-600">{{ row.client.sites_count }} site(s) · {{ row.client.equipment_count }} équip.</td>
                        <td><TagList :tags="row.client.tags" /></td>
                        <td class="text-xs text-slate-600">{{ formatDate(row.client.updated_at) }}</td>
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
