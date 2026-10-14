<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Download, Layers, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import ClientHeader from '@/components/ClientHeader.vue';
import DynamicIcon from '@/components/DynamicIcon.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExpiryBadge from '@/components/ExpiryBadge.vue';
import Pagination from '@/components/Pagination.vue';
import SelectInput from '@/components/SelectInput.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TagList from '@/components/TagList.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, Option, Paginated, ServiceCategoryDefinition, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    context: ClientContext;
    services: Paginated<{
        id: number;
        name: string;
        category: string;
        provider: string | null;
        reference: string | null;
        status: string;
        end_date: string | null;
        equipment_count: number;
        site: { id: number; name: string } | null;
        tags: TagRef[];
    }>;
    filters: { q: string; category: string | null; status: string; site: number | null; sort: string; direction: string };
    categories: ServiceCategoryDefinition[];
    statuses: Option[];
    sites: Option<number>[];
}>();

const { filters, sortBy } = useFilters(() => route('clients.services.index', props.context.id), { ...props.filters });
const categoryOptions = computed(() => props.categories.map((category) => ({ value: category.value, label: category.label })));
const categoryMap = computed(() => Object.fromEntries(props.categories.map((category) => [category.value, category])));
const statusOptions = computed<Option[]>(() => [{ value: 'current', label: 'Hors archivés' }, ...props.statuses, { value: 'all', label: 'Tous' }]);
const canCreate = props.context.permissions.edit && props.context.status === 'active';
</script>

<template>
    <ClientHeader
        :context="context"
        active="services"
        title="Réseaux et services"
        description="Internet, VPN, domaines, hébergement, cloud, messagerie, applications métiers, sauvegardes…"
        :breadcrumbs="[{ label: 'Services' }]"
    >
        <template #actions>
            <a v-if="context.permissions.export" :href="route('clients.export', [context.id, 'services', 'xlsx'])" class="btn-secondary"><Download class="h-4 w-4" aria-hidden="true" /> Exporter</a>
            <Link v-if="canCreate" :href="route('clients.services.create', context.id)" class="btn-primary"><Plus class="h-4 w-4" aria-hidden="true" /> Nouveau service</Link>
        </template>
    </ClientHeader>

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-4">
            <div>
                <label for="srv-q" class="sr-only">Rechercher</label>
                <input id="srv-q" v-model="filters.q" type="search" class="form-input" placeholder="Nom, fournisseur, référence…" />
            </div>
            <div>
                <label for="srv-category" class="sr-only">Catégorie</label>
                <SelectInput id="srv-category" v-model="filters.category" :options="categoryOptions" placeholder="Toutes les catégories" />
            </div>
            <div>
                <label for="srv-site" class="sr-only">Site</label>
                <SelectInput id="srv-site" v-model="filters.site" :options="sites" placeholder="Tous les sites" />
            </div>
            <div>
                <label for="srv-status" class="sr-only">Statut</label>
                <SelectInput id="srv-status" v-model="filters.status" :options="statusOptions" />
            </div>
        </div>

        <div v-if="services.data.length" class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <SortableHeader column="name" label="Service" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th scope="col">Catégorie</th>
                        <SortableHeader column="provider" label="Fournisseur" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th scope="col">Site</th>
                        <th scope="col">Statut</th>
                        <SortableHeader column="end_date" label="Échéance" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="service in services.data" :key="service.id">
                        <td>
                            <Link :href="route('clients.services.show', [context.id, service.id])" class="link">{{ service.name }}</Link>
                            <p class="text-xs text-slate-500">{{ service.reference }}<template v-if="service.equipment_count"> · {{ service.equipment_count }} équip.</template></p>
                            <TagList :tags="service.tags" class="mt-1" />
                        </td>
                        <td>
                            <span class="inline-flex items-center gap-1.5">
                                <DynamicIcon :name="categoryMap[service.category]?.icon" class="h-3.5 w-3.5 text-slate-400" />
                                {{ categoryMap[service.category]?.label }}
                            </span>
                        </td>
                        <td>{{ service.provider ?? '—' }}</td>
                        <td>{{ service.site?.name ?? '—' }}</td>
                        <td><StatusBadge :status="service.status" /></td>
                        <td class="text-xs"><ExpiryBadge :date="service.end_date" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else :icon="Layers" title="Aucun service" description="Documentez les connexions, domaines, services cloud et sauvegardes du client.">
            <Link v-if="canCreate" :href="route('clients.services.create', context.id)" class="btn-primary"><Plus class="h-4 w-4" aria-hidden="true" /> Ajouter un service</Link>
        </EmptyState>
        <Pagination :paginator="services" />
    </div>
</template>
