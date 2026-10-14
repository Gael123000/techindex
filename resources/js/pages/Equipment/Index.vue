<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Download, FileUp, Plus, Server } from '@lucide/vue';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import ClientHeader from '@/components/ClientHeader.vue';
import DynamicIcon from '@/components/DynamicIcon.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExpiryBadge from '@/components/ExpiryBadge.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import SelectInput from '@/components/SelectInput.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TagList from '@/components/TagList.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, Option, Paginated, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

interface EquipmentRow {
    id: number;
    client: { id: number; name: string };
    name: string;
    hostname: string | null;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    status: string;
    status_label: string;
    warranty_expires_at: string | null;
    type: { id: number; name: string; icon: string } | null;
    site: { id: number; name: string } | null;
    primary_ip: string | null;
    ip_count: number;
    tags: TagRef[];
}

const props = defineProps<{
    context: ClientContext | null;
    equipment: Paginated<EquipmentRow>;
    filters: { q: string; type: number | null; site: number | null; client: number | null; status: string; tag: number | null; alert: string | null; sort: string; direction: string };
    options: { types: Option<number>[]; sites: Option<number>[]; clients?: Option<number>[]; statuses: Option[]; tags: TagRef[] };
}>();

const isGlobal = computed(() => props.context === null);
const url = () => (props.context ? route('clients.equipment.index', props.context.id) : route('equipment.index'));
const { filters, sortBy } = useFilters(url, { ...props.filters });

const statusOptions = computed<Option[]>(() => [{ value: 'current', label: 'En cours (hors archivés)' }, ...props.options.statuses, { value: 'all', label: 'Tous les statuts' }]);
const alertOptions: Option[] = [
    { value: 'warranty', label: 'Garantie proche ou expirée' },
    { value: 'end_of_life', label: 'Fin de vie proche ou atteinte' },
];
const tagOptions = computed(() => props.options.tags.map((tag) => ({ value: tag.id, label: tag.name })));
const canCreate = computed(() => props.context?.permissions.edit && props.context.status === 'active');
</script>

<template>
    <ClientHeader v-if="context" :context="context" active="equipment" title="Inventaire technique" :breadcrumbs="[{ label: 'Équipements' }]">
        <template #actions>
            <template v-if="context.permissions.export">
                <a :href="route('clients.export', [context.id, 'equipment', 'xlsx'])" class="btn-secondary"><Download class="h-4 w-4" aria-hidden="true" /> Excel</a>
                <a :href="route('clients.export', [context.id, 'equipment', 'csv'])" class="btn-secondary">CSV</a>
            </template>
            <Link v-if="canCreate" :href="route('imports.create', { client: context.id })" class="btn-secondary"><FileUp class="h-4 w-4" aria-hidden="true" /> Importer</Link>
            <Link v-if="canCreate" :href="route('clients.equipment.create', context.id)" class="btn-primary"><Plus class="h-4 w-4" aria-hidden="true" /> Nouvel équipement</Link>
        </template>
    </ClientHeader>
    <PageHeader v-else title="Équipements" description="Inventaire de tous les clients qui vous sont accessibles." :breadcrumbs="[{ label: 'Équipements' }]">
        <template #actions>
            <a :href="route('exports.download', ['equipment', 'xlsx'])" class="btn-secondary"><Download class="h-4 w-4" aria-hidden="true" /> Excel</a>
            <a :href="route('exports.download', ['equipment', 'csv'])" class="btn-secondary">CSV</a>
        </template>
    </PageHeader>

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-6">
            <div class="lg:col-span-2">
                <label for="eq-q" class="sr-only">Rechercher</label>
                <input id="eq-q" v-model="filters.q" type="search" class="form-input" placeholder="Nom, hôte, IP, MAC, n° de série, modèle…" />
            </div>
            <div v-if="isGlobal">
                <label for="eq-client" class="sr-only">Client</label>
                <SelectInput id="eq-client" v-model="filters.client" :options="options.clients ?? []" placeholder="Tous les clients" />
            </div>
            <div v-else>
                <label for="eq-site" class="sr-only">Site</label>
                <SelectInput id="eq-site" v-model="filters.site" :options="options.sites" placeholder="Tous les sites" />
            </div>
            <div>
                <label for="eq-type" class="sr-only">Type</label>
                <SelectInput id="eq-type" v-model="filters.type" :options="options.types" placeholder="Tous les types" />
            </div>
            <div>
                <label for="eq-status" class="sr-only">Statut</label>
                <SelectInput id="eq-status" v-model="filters.status" :options="statusOptions" />
            </div>
            <div>
                <label for="eq-alert" class="sr-only">Échéance</label>
                <SelectInput id="eq-alert" v-model="filters.alert" :options="alertOptions" placeholder="Toutes les échéances" />
            </div>
            <div v-if="tagOptions.length" class="lg:col-span-2">
                <label for="eq-tag" class="sr-only">Étiquette</label>
                <SelectInput id="eq-tag" v-model="filters.tag" :options="tagOptions" placeholder="Toutes les étiquettes" />
            </div>
        </div>

        <div v-if="equipment.data.length" class="overflow-x-auto">
            <table class="table-base">
                <caption class="sr-only">Équipements</caption>
                <thead>
                    <tr>
                        <SortableHeader column="name" label="Équipement" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th v-if="isGlobal" scope="col">Client</th>
                        <th scope="col">Site</th>
                        <th scope="col">Adresse IP</th>
                        <SortableHeader column="model" label="Modèle / série" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th scope="col">Statut</th>
                        <SortableHeader column="warranty_expires_at" label="Garantie" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="item in equipment.data" :key="item.id">
                        <td>
                            <div class="flex items-start gap-2">
                                <DynamicIcon :name="item.type?.icon" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                                <div>
                                    <Link :href="route('clients.equipment.show', [item.client.id, item.id])" class="link">{{ item.name }}</Link>
                                    <p class="text-xs text-slate-500">{{ [item.type?.name, item.hostname].filter(Boolean).join(' · ') }}</p>
                                    <TagList :tags="item.tags" class="mt-1" />
                                </div>
                            </div>
                        </td>
                        <td v-if="isGlobal"><Link :href="route('clients.show', item.client.id)" class="link">{{ item.client.name }}</Link></td>
                        <td>{{ item.site?.name ?? '—' }}</td>
                        <td class="font-mono text-xs">
                            {{ item.primary_ip ?? '—' }}<span v-if="item.ip_count > 1" class="ml-1 text-slate-400">+{{ item.ip_count - 1 }}</span>
                        </td>
                        <td class="text-xs">
                            <p>{{ [item.manufacturer, item.model].filter(Boolean).join(' ') || '—' }}</p>
                            <p v-if="item.serial_number" class="font-mono text-slate-500">{{ item.serial_number }}</p>
                        </td>
                        <td><StatusBadge :status="item.status" :label="item.status_label" /></td>
                        <td class="text-xs"><ExpiryBadge :date="item.warranty_expires_at" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState
            v-else
            :icon="Server"
            title="Aucun équipement"
            :description="filters.q ? 'Aucun équipement ne correspond aux filtres.' : 'Ajoutez les équipements un par un ou importez un fichier CSV / Excel.'"
        >
            <Link v-if="canCreate && context" :href="route('clients.equipment.create', context.id)" class="btn-primary"><Plus class="h-4 w-4" aria-hidden="true" /> Ajouter</Link>
        </EmptyState>
        <Pagination :paginator="equipment" />
    </div>
</template>
