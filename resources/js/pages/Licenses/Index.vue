<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Download, KeyRound, Plug, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import CheckboxInput from '@/components/CheckboxInput.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExpiryBadge from '@/components/ExpiryBadge.vue';
import Pagination from '@/components/Pagination.vue';
import SelectInput from '@/components/SelectInput.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, Option, Paginated } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    context: ClientContext;
    licenses: Paginated<{
        id: number;
        name: string;
        kind: string;
        publisher: string | null;
        supplier: string | null;
        quantity: number | null;
        assigned_quantity: number | null;
        integration_account_id: number | null;
        expires_at: string | null;
        auto_renewal: boolean;
        status: string;
        equipment_count: number;
    }>;
    filters: { q: string; kind: string | null; status: string; expiring: boolean; sort: string; direction: string };
    kinds: Option[];
    statuses: Option[];
    warnDays: number;
}>();

const { filters, sortBy } = useFilters(() => route('clients.licenses.index', props.context.id), { ...props.filters });
const kindLabels = computed(() => Object.fromEntries(props.kinds.map((kind) => [kind.value, kind.label])));
const statusLabels = computed(() => Object.fromEntries(props.statuses.map((status) => [status.value, status.label])));
const statusOptions = computed<Option[]>(() => [{ value: 'current', label: 'Hors archivées' }, ...props.statuses, { value: 'all', label: 'Toutes' }]);
const canCreate = props.context.permissions.edit && props.context.status === 'active';
</script>

<template>
    <ClientHeader :context="context" active="licenses" title="Licences et abonnements" :breadcrumbs="[{ label: 'Licences' }]">
        <template #actions>
            <a v-if="context.permissions.export" :href="route('clients.export', [context.id, 'licenses', 'xlsx'])" class="btn-secondary"><Download class="h-4 w-4" aria-hidden="true" /> Exporter</a>
            <Link v-if="canCreate" :href="route('clients.licenses.create', context.id)" class="btn-primary"><Plus class="h-4 w-4" aria-hidden="true" /> Nouvelle licence</Link>
        </template>
    </ClientHeader>

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-4">
            <div>
                <label for="lic-q" class="sr-only">Rechercher</label>
                <input id="lic-q" v-model="filters.q" type="search" class="form-input" placeholder="Nom, éditeur, contrat…" />
            </div>
            <div>
                <label for="lic-kind" class="sr-only">Nature</label>
                <SelectInput id="lic-kind" v-model="filters.kind" :options="kinds" placeholder="Toutes natures" />
            </div>
            <div>
                <label for="lic-status" class="sr-only">Statut</label>
                <SelectInput id="lic-status" v-model="filters.status" :options="statusOptions" />
            </div>
            <CheckboxInput id="lic-expiring" v-model="filters.expiring" :label="`Échéance sous ${warnDays} jours`" class="self-center" />
        </div>

        <div v-if="licenses.data.length" class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <SortableHeader column="name" label="Licence" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th scope="col">Nature</th>
                        <SortableHeader column="publisher" label="Éditeur" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortableHeader column="quantity" label="Quantité" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th scope="col">Statut</th>
                        <SortableHeader column="expires_at" label="Échéance" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="license in licenses.data" :key="license.id">
                        <td>
                            <Link :href="route('clients.licenses.show', [context.id, license.id])" class="link">{{ license.name }}</Link>
                            <Plug v-if="license.integration_account_id" class="ml-1.5 inline h-3.5 w-3.5 text-turquoise-600" aria-label="Synchronisée par une intégration" />
                            <p class="text-xs text-slate-500">{{ license.supplier }}<template v-if="license.equipment_count"> · {{ license.equipment_count }} équip.</template></p>
                        </td>
                        <td>{{ kindLabels[license.kind] }}</td>
                        <td>{{ license.publisher ?? '—' }}</td>
                        <td>
                            {{ license.quantity ?? '—' }}
                            <span v-if="license.assigned_quantity !== null" class="block text-xs text-slate-500">{{ license.assigned_quantity }} attribuée(s)</span>
                        </td>
                        <td><StatusBadge :status="license.status" :label="statusLabels[license.status]" /></td>
                        <td class="text-xs">
                            <ExpiryBadge :date="license.expires_at" :warn-days="warnDays" />
                            <span v-if="license.auto_renewal" class="block text-slate-500">renouvellement auto.</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else :icon="KeyRound" title="Aucune licence" description="Suivez les licences, abonnements et contrats de support avec leurs échéances." />
        <Pagination :paginator="licenses" />
    </div>
</template>
