<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Pencil } from '@lucide/vue';
import { route } from 'ziggy-js';
import ClientHeader from '@/components/ClientHeader.vue';
import DeleteResource from '@/components/DeleteResource.vue';
import DynamicIcon from '@/components/DynamicIcon.vue';
import HistoryList from '@/components/HistoryList.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AuditEntry, ClientContext } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    context: ClientContext;
    site: {
        id: number;
        name: string;
        code: string | null;
        address_line1: string | null;
        address_line2: string | null;
        postal_code: string | null;
        city: string | null;
        country: string | null;
        phone: string | null;
        access_instructions: string | null;
        notes: string | null;
        status: string;
    };
    contacts: Array<{ id: number; full_name: string; job_title: string | null; email: string | null; phone: string | null; mobile: string | null }>;
    equipment: Array<{
        id: number;
        name: string;
        hostname: string | null;
        model: string | null;
        status: string;
        type: { id: number; name: string; icon: string } | null;
        interfaces: Array<{ ip_address: string | null }>;
    }>;
    subnets: Array<{ id: number; name: string; cidr: string; gateway: string | null; vlan: { number: number; name: string } | null }>;
    services: Array<{ id: number; name: string; provider: string | null; status: string }>;
    history: AuditEntry[];
    can: { update: boolean; delete: boolean };
}>();

function toggleArchive(): void {
    router.post(route(props.site.status === 'archived' ? 'clients.sites.restore' : 'clients.sites.archive', [props.context.id, props.site.id]));
}
</script>

<template>
    <ClientHeader :context="context" active="sites" :title="site.name" :breadcrumbs="[{ label: 'Sites', href: route('clients.sites.index', context.id) }, { label: site.name }]">
        <template #actions>
            <Link v-if="can.update" :href="route('clients.sites.edit', [context.id, site.id])" class="btn-secondary"><Pencil class="h-4 w-4" aria-hidden="true" /> Modifier</Link>
            <button v-if="can.update" type="button" class="btn-secondary" @click="toggleArchive">
                <template v-if="site.status === 'archived'"><ArchiveRestore class="h-4 w-4" aria-hidden="true" /> Restaurer</template>
                <template v-else><Archive class="h-4 w-4" aria-hidden="true" /> Archiver</template>
            </button>
            <DeleteResource v-if="can.delete" :url="route('clients.sites.destroy', [context.id, site.id])" :name="site.name" message="Le site sera supprimé ; ses équipements, contacts et réseaux sont conservés sans site." />
        </template>
    </ClientHeader>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <section class="card p-5">
                <dl class="dl-grid">
                    <div>
                        <dt>Code</dt>
                        <dd>{{ site.code ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Statut</dt>
                        <dd><StatusBadge :status="site.status" /></dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt>Adresse</dt>
                        <dd>{{ [site.address_line1, site.address_line2, [site.postal_code, site.city].filter(Boolean).join(' '), site.country].filter(Boolean).join(', ') || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Téléphone</dt>
                        <dd>{{ site.phone ?? '—' }}</dd>
                    </div>
                </dl>
                <div v-if="site.access_instructions" class="mt-4">
                    <h2 class="text-xs font-medium tracking-wide text-slate-500 uppercase">Consignes d'accès</h2>
                    <p class="mt-1 text-sm whitespace-pre-line text-slate-800">{{ site.access_instructions }}</p>
                </div>
                <div v-if="site.notes" class="mt-4 rounded-md bg-slate-50 p-3 text-sm whitespace-pre-line text-slate-700">{{ site.notes }}</div>
            </section>

            <section class="card" aria-labelledby="site-equipment">
                <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h2 id="site-equipment" class="text-sm font-semibold text-navy-900">Équipements du site ({{ equipment.length }})</h2>
                    <Link :href="route('clients.equipment.index', { client: context.id, site: site.id })" class="link text-xs">Liste filtrée</Link>
                </header>
                <ul v-if="equipment.length" class="divide-y divide-slate-100">
                    <li v-for="item in equipment" :key="item.id" class="flex items-center gap-3 px-4 py-2.5 text-sm">
                        <DynamicIcon :name="item.type?.icon" class="h-4 w-4 text-slate-400" />
                        <Link :href="route('clients.equipment.show', [context.id, item.id])" class="link">{{ item.name }}</Link>
                        <span class="text-xs text-slate-500">{{ [item.type?.name, item.model, item.interfaces[0]?.ip_address].filter(Boolean).join(' · ') }}</span>
                        <StatusBadge :status="item.status" class="ml-auto" />
                    </li>
                </ul>
                <p v-else class="px-4 py-4 text-sm text-slate-500">Aucun équipement rattaché à ce site.</p>
            </section>

            <section class="card" aria-labelledby="site-history">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="site-history" class="text-sm font-semibold text-navy-900">Historique</h2></header>
                <HistoryList :entries="history" />
            </section>
        </div>

        <div class="space-y-5">
            <section class="card" aria-labelledby="site-contacts">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="site-contacts" class="text-sm font-semibold text-navy-900">Contacts sur site</h2></header>
                <ul v-if="contacts.length" class="divide-y divide-slate-100 text-sm">
                    <li v-for="contact in contacts" :key="contact.id" class="px-4 py-2.5">
                        <p class="font-medium">{{ contact.full_name }}</p>
                        <p class="text-xs text-slate-500">{{ [contact.job_title, contact.email, contact.mobile ?? contact.phone].filter(Boolean).join(' · ') }}</p>
                    </li>
                </ul>
                <p v-else class="px-4 py-4 text-sm text-slate-500">Aucun contact.</p>
            </section>

            <section class="card" aria-labelledby="site-subnets">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="site-subnets" class="text-sm font-semibold text-navy-900">Réseaux</h2></header>
                <ul v-if="subnets.length" class="divide-y divide-slate-100 text-sm">
                    <li v-for="subnet in subnets" :key="subnet.id" class="px-4 py-2.5">
                        <p><span class="font-mono">{{ subnet.cidr }}</span> – {{ subnet.name }}</p>
                        <p class="text-xs text-slate-500">
                            <template v-if="subnet.vlan">VLAN {{ subnet.vlan.number }} · </template>passerelle {{ subnet.gateway ?? '—' }}
                        </p>
                    </li>
                </ul>
                <p v-else class="px-4 py-4 text-sm text-slate-500">Aucun sous-réseau.</p>
            </section>

            <section class="card" aria-labelledby="site-services">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="site-services" class="text-sm font-semibold text-navy-900">Services</h2></header>
                <ul v-if="services.length" class="divide-y divide-slate-100 text-sm">
                    <li v-for="service in services" :key="service.id" class="px-4 py-2.5">
                        <Link :href="route('clients.services.show', [context.id, service.id])" class="link">{{ service.name }}</Link>
                        <span v-if="service.provider" class="text-xs text-slate-500"> · {{ service.provider }}</span>
                    </li>
                </ul>
                <p v-else class="px-4 py-4 text-sm text-slate-500">Aucun service.</p>
            </section>
        </div>
    </div>
</template>
