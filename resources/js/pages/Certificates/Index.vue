<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BadgeCheck, Download, Plus } from '@lucide/vue';
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
    certificates: Paginated<{
        id: number;
        name: string;
        common_name: string | null;
        issuer: string | null;
        type: string;
        expires_at: string | null;
        auto_renewal: boolean;
        status: string;
        equipment_count: number;
        service: { id: number; name: string } | null;
    }>;
    filters: { q: string; status: string; expiring: boolean; sort: string; direction: string };
    statuses: Option[];
    types: Option[];
    warnDays: number;
}>();

const { filters, sortBy } = useFilters(() => route('clients.certificates.index', props.context.id), { ...props.filters });
const typeLabels = computed(() => Object.fromEntries(props.types.map((type) => [type.value, type.label])));
const statusOptions = computed<Option[]>(() => [{ value: 'current', label: 'Hors archivés' }, ...props.statuses, { value: 'all', label: 'Tous' }]);
const canCreate = props.context.permissions.edit && props.context.status === 'active';
</script>

<template>
    <ClientHeader :context="context" active="certificates" title="Certificats" :breadcrumbs="[{ label: 'Certificats' }]">
        <template #actions>
            <a v-if="context.permissions.export" :href="route('clients.export', [context.id, 'certificates', 'xlsx'])" class="btn-secondary"><Download class="h-4 w-4" aria-hidden="true" /> Exporter</a>
            <Link v-if="canCreate" :href="route('clients.certificates.create', context.id)" class="btn-primary"><Plus class="h-4 w-4" aria-hidden="true" /> Nouveau certificat</Link>
        </template>
    </ClientHeader>

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-3">
            <div>
                <label for="cert-q" class="sr-only">Rechercher</label>
                <input id="cert-q" v-model="filters.q" type="search" class="form-input" placeholder="Nom, CN, SAN, émetteur…" />
            </div>
            <div>
                <label for="cert-status" class="sr-only">Statut</label>
                <SelectInput id="cert-status" v-model="filters.status" :options="statusOptions" />
            </div>
            <CheckboxInput id="cert-expiring" v-model="filters.expiring" :label="`Expiration sous ${warnDays} jours`" class="self-center" />
        </div>

        <div v-if="certificates.data.length" class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <SortableHeader column="name" label="Certificat" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortableHeader column="common_name" label="Nom commun" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortableHeader column="issuer" label="Émetteur" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th scope="col">Statut</th>
                        <SortableHeader column="expires_at" label="Expiration" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="certificate in certificates.data" :key="certificate.id">
                        <td>
                            <Link :href="route('clients.certificates.show', [context.id, certificate.id])" class="link">{{ certificate.name }}</Link>
                            <p class="text-xs text-slate-500">{{ typeLabels[certificate.type] }}<template v-if="certificate.service"> · {{ certificate.service.name }}</template></p>
                        </td>
                        <td class="font-mono text-xs">{{ certificate.common_name ?? '—' }}</td>
                        <td>{{ certificate.issuer ?? '—' }}</td>
                        <td><StatusBadge :status="certificate.status" /></td>
                        <td class="text-xs">
                            <ExpiryBadge :date="certificate.expires_at" :warn-days="warnDays" />
                            <span v-if="certificate.auto_renewal" class="block text-slate-500">renouvellement auto.</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else :icon="BadgeCheck" title="Aucun certificat" description="Suivez les certificats TLS, VPN ou internes et leur date d'expiration." />
        <Pagination :paginator="certificates" />
    </div>
</template>
