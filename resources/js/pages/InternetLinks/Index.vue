<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Download, Globe, MapPin, Plus, Router, Wifi } from '@lucide/vue';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExpiryBadge from '@/components/ExpiryBadge.vue';
import IspLogo from '@/components/IspLogo.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TagList from '@/components/TagList.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, InternetLinkSummary, Option } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    context: ClientContext;
    links: InternetLinkSummary[];
    filters: { q: string; status: string; site: number | null; isp: number | null };
    statuses: Option[];
    sites: Option<number>[];
    isps: Option<number>[];
}>();

const { filters } = useFilters(() => route('clients.internet-links.index', props.context.id), { ...props.filters });
const statusOptions = computed<Option[]>(() => [{ value: 'current', label: 'En service ou à venir' }, ...props.statuses, { value: 'all', label: 'Tous' }]);
const canCreate = props.context.permissions.edit && props.context.status === 'active';

const roleColors: Record<string, string> = { primary: 'teal', backup: 'amber', aggregated: 'violet', other: 'slate' };

function speed(mbps: number | null): string | null {
    if (mbps === null) {
        return null;
    }

    return mbps >= 1000 && mbps % 100 === 0 ? `${(mbps / 1000).toLocaleString('fr-FR')} Gbit/s` : `${mbps} Mbit/s`;
}
</script>

<template>
    <ClientHeader
        :context="context"
        active="internet"
        title="Fournisseurs d'accès à Internet"
        description="Liens Internet du client : opérateur, technologie, débits, adresses publiques, contrat et support."
        :breadcrumbs="[{ label: 'FAI' }]"
    >
        <template #actions>
            <a v-if="context.permissions.export && links.length" :href="route('clients.export', [context.id, 'internet_links', 'xlsx'])" class="btn-secondary">
                <Download class="h-4 w-4" aria-hidden="true" /> Exporter
            </a>
            <Link v-if="canCreate" :href="route('clients.internet-links.create', context.id)" class="btn-primary"><Plus class="h-4 w-4" aria-hidden="true" /> Nouveau lien</Link>
        </template>
    </ClientHeader>

    <div class="card mb-4 grid grid-cols-1 gap-3 p-4 sm:grid-cols-4">
        <div>
            <label for="isp-q" class="sr-only">Rechercher</label>
            <input id="isp-q" v-model="filters.q" type="search" class="form-input" placeholder="Nom, opérateur, réf. de ligne, IP…" />
        </div>
        <div>
            <label for="isp-status" class="sr-only">Statut</label>
            <SelectInput id="isp-status" v-model="filters.status" :options="statusOptions" />
        </div>
        <div>
            <label for="isp-site" class="sr-only">Site</label>
            <SelectInput id="isp-site" v-model="filters.site" :options="sites" placeholder="Tous les sites" />
        </div>
        <div>
            <label for="isp-operator" class="sr-only">Opérateur</label>
            <SelectInput id="isp-operator" v-model="filters.isp" :options="isps" placeholder="Tous les opérateurs" />
        </div>
    </div>

    <div v-if="links.length" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        <Link
            v-for="link in links"
            :key="link.id"
            :href="route('clients.internet-links.show', [context.id, link.id])"
            class="card group flex flex-col p-4 transition hover:border-turquoise-300 hover:shadow-md focus-visible:outline-turquoise-600"
        >
            <div class="flex items-start gap-3">
                <IspLogo :url="link.logo_url" :name="link.operator" size="lg" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-xs font-medium tracking-wide text-slate-500 uppercase">{{ link.operator ?? 'Opérateur non précisé' }}</p>
                    <h2 class="truncate font-semibold text-navy-900 group-hover:underline">{{ link.name }}</h2>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                        <Badge :color="roleColors[link.role] ?? 'slate'">{{ link.role_label }}</Badge>
                        <StatusBadge :status="link.status" :label="link.status_label" />
                    </div>
                </div>
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                <div class="col-span-2 flex items-center gap-3 rounded-md bg-slate-50 px-3 py-2">
                    <Wifi class="h-4 w-4 shrink-0 text-turquoise-700" aria-hidden="true" />
                    <dt class="sr-only">Technologie</dt>
                    <dd class="min-w-0 flex-1 truncate">{{ link.technology_label ?? 'Technologie non précisée' }}</dd>
                    <template v-if="link.download_mbps !== null || link.upload_mbps !== null">
                        <dt class="sr-only">Débits</dt>
                        <dd class="flex items-center gap-2 text-xs font-medium whitespace-nowrap text-navy-900">
                            <span v-if="link.download_mbps !== null" class="inline-flex items-center"><ArrowDown class="h-3.5 w-3.5" aria-label="descendant" />{{ speed(link.download_mbps) }}</span>
                            <span v-if="link.upload_mbps !== null" class="inline-flex items-center"><ArrowUp class="h-3.5 w-3.5" aria-label="montant" />{{ speed(link.upload_mbps) }}</span>
                        </dd>
                    </template>
                </div>
                <div v-if="link.public_ips" class="col-span-2">
                    <dt class="text-xs text-slate-500">Adresses IP publiques</dt>
                    <dd class="truncate font-mono text-xs">{{ link.public_ips }}</dd>
                </div>
                <div v-if="link.line_reference">
                    <dt class="text-xs text-slate-500">Référence de ligne</dt>
                    <dd class="truncate font-mono text-xs">{{ link.line_reference }}</dd>
                </div>
                <div v-if="link.commitment_ends_at">
                    <dt class="text-xs text-slate-500">Fin d'engagement</dt>
                    <dd class="text-xs"><ExpiryBadge :date="link.commitment_ends_at" /></dd>
                </div>
            </dl>

            <div class="mt-auto flex flex-wrap items-center gap-x-4 gap-y-1 pt-4 text-xs text-slate-500">
                <span v-if="link.site" class="inline-flex items-center gap-1"><MapPin class="h-3.5 w-3.5" aria-hidden="true" />{{ link.site.name }}</span>
                <span v-if="link.equipment_count" class="inline-flex items-center gap-1"><Router class="h-3.5 w-3.5" aria-hidden="true" />{{ link.equipment_count }} équipement(s)</span>
                <span v-if="link.subnets_count" class="inline-flex items-center gap-1"><Globe class="h-3.5 w-3.5" aria-hidden="true" />{{ link.subnets_count }} réseau(x)</span>
                <TagList v-if="link.tags.length" :tags="link.tags" />
            </div>
        </Link>
    </div>

    <EmptyState
        v-else
        :icon="Wifi"
        title="Aucun lien Internet"
        description="Documentez les accès fibre, SDSL, 4G… du client : opérateur, débits, IP publiques, référence de ligne, support et engagement."
    >
        <Link v-if="canCreate" :href="route('clients.internet-links.create', context.id)" class="btn-primary"><Plus class="h-4 w-4" aria-hidden="true" /> Ajouter un lien</Link>
    </EmptyState>
</template>
