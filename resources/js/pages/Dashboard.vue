<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AlertTriangle, BadgeCheck, Building2, CalendarClock, FileClock, FileText, KeyRound, Layers, Server, ShieldAlert } from '@lucide/vue';
import type { Component } from 'vue';
import { route } from 'ziggy-js';
import EmptyState from '@/components/EmptyState.vue';
import ExpiryBadge from '@/components/ExpiryBadge.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatDateTime } from '@/lib/format';
import type { AuditEntry } from '@/types';

defineOptions({ layout: AppLayout });

interface AlertItem {
    id: number;
    name: string;
    client: { id: number; name: string };
    date: string | null;
    url: string;
}

interface AlertList {
    total: number;
    items: AlertItem[];
}

const props = defineProps<{
    stats: { clients: number; equipment: number; services: number; documents: number };
    alertDays: Record<string, number>;
    alerts: Record<'warranties' | 'licenses' | 'certificates' | 'services' | 'end_of_life' | 'documents', AlertList>;
    clients: Array<{ id: number; name: string; reference: string; city: string | null; equipment_count: number; sites_count: number; documents_count: number }>;
    recentChanges: AuditEntry[];
}>();

const statCards: Array<{ key: keyof typeof props.stats; label: string; icon: Component }> = [
    { key: 'clients', label: 'Clients actifs', icon: Building2 },
    { key: 'equipment', label: 'Équipements', icon: Server },
    { key: 'services', label: 'Services actifs', icon: Layers },
    { key: 'documents', label: 'Fiches publiées', icon: FileText },
];

const alertPanels: Array<{ key: keyof typeof props.alerts; title: string; description: string; icon: Component; warn: number; dateLabel: string }> = [
    { key: 'warranties', title: 'Garanties', description: `Fin de garantie dans les ${props.alertDays.warranty_days} jours`, icon: ShieldAlert, warn: props.alertDays.warranty_days, dateLabel: 'Fin de garantie' },
    { key: 'licenses', title: 'Licences et abonnements', description: `Échéance dans les ${props.alertDays.license_days} jours ou dépassée`, icon: KeyRound, warn: props.alertDays.license_days, dateLabel: 'Échéance' },
    { key: 'certificates', title: 'Certificats', description: `Expiration dans les ${props.alertDays.certificate_days} jours ou dépassée`, icon: BadgeCheck, warn: props.alertDays.certificate_days, dateLabel: 'Expiration' },
    { key: 'services', title: 'Contrats et domaines', description: `Échéance dans les ${props.alertDays.service_days} jours ou dépassée`, icon: CalendarClock, warn: props.alertDays.service_days, dateLabel: 'Échéance' },
    { key: 'end_of_life', title: 'Équipements en fin de vie', description: `Fin de vie dans les ${props.alertDays.end_of_life_days} jours ou atteinte`, icon: AlertTriangle, warn: props.alertDays.end_of_life_days, dateLabel: 'Fin de vie' },
    { key: 'documents', title: 'Fiches à vérifier', description: 'Jamais vérifiées ou vérification échue', icon: FileClock, warn: 0, dateLabel: 'Dernière vérification' },
];
</script>

<template>
    <PageHeader title="Tableau de bord" description="Vue d'ensemble des clients qui vous sont accessibles." />

    <section aria-label="Indicateurs" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div v-for="card in statCards" :key="card.key" class="card flex items-center gap-3 p-4">
            <div class="rounded-md bg-navy-50 p-2 text-navy-700">
                <component :is="card.icon" class="h-5 w-5" aria-hidden="true" />
            </div>
            <div>
                <p class="text-2xl font-semibold text-navy-900">{{ stats[card.key].toLocaleString('fr-FR') }}</p>
                <p class="text-xs text-slate-500">{{ card.label }}</p>
            </div>
        </div>
    </section>

    <section aria-labelledby="alerts-title" class="mt-6">
        <h2 id="alerts-title" class="mb-3 text-sm font-semibold tracking-wide text-slate-500 uppercase">Actions à prévoir</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article v-for="panel in alertPanels" :key="panel.key" class="card flex flex-col">
                <header class="flex items-start gap-2.5 border-b border-slate-200 px-4 py-3">
                    <component :is="panel.icon" class="mt-0.5 h-4 w-4 text-turquoise-700" aria-hidden="true" />
                    <div class="flex-1">
                        <h3 class="text-sm font-semibold text-navy-900">
                            {{ panel.title }}
                            <span
                                class="ml-1 rounded-full px-1.5 py-0.5 text-xs"
                                :class="alerts[panel.key].total > 0 ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-600'"
                            >
                                {{ alerts[panel.key].total }}
                            </span>
                        </h3>
                        <p class="text-xs text-slate-500">{{ panel.description }}</p>
                    </div>
                </header>
                <ul v-if="alerts[panel.key].items.length" class="divide-y divide-slate-100 text-sm">
                    <li v-for="item in alerts[panel.key].items" :key="item.id" class="flex items-start justify-between gap-3 px-4 py-2.5">
                        <div class="min-w-0">
                            <Link :href="item.url" class="link block truncate">{{ item.name }}</Link>
                            <p class="truncate text-xs text-slate-500">{{ item.client.name }}</p>
                        </div>
                        <div class="shrink-0 text-right text-xs">
                            <span class="sr-only">{{ panel.dateLabel }} :</span>
                            <template v-if="panel.key === 'documents'">
                                <span class="text-slate-600">{{ item.date ? formatDate(item.date) : 'Jamais vérifiée' }}</span>
                            </template>
                            <ExpiryBadge v-else :date="item.date" :warn-days="panel.warn" />
                        </div>
                    </li>
                </ul>
                <p v-else class="px-4 py-6 text-center text-sm text-slate-500">Rien à signaler.</p>
                <p v-if="alerts[panel.key].total > alerts[panel.key].items.length" class="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
                    {{ alerts[panel.key].total - alerts[panel.key].items.length }} autre(s) élément(s) non affiché(s).
                </p>
            </article>
        </div>
    </section>

    <div class="mt-6 grid grid-cols-1 gap-4 xl:grid-cols-2">
        <section class="card" aria-labelledby="clients-title">
            <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 id="clients-title" class="text-sm font-semibold text-navy-900">Clients récemment modifiés</h2>
                <Link :href="route('clients.index')" class="link text-xs">Tous les clients</Link>
            </header>
            <ul v-if="clients.length" class="divide-y divide-slate-100">
                <li v-for="client in clients" :key="client.id" class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                    <div class="min-w-0">
                        <Link :href="route('clients.show', client.id)" class="link">{{ client.name }}</Link>
                        <p class="text-xs text-slate-500">{{ client.reference }}<template v-if="client.city"> · {{ client.city }}</template></p>
                    </div>
                    <p class="shrink-0 text-xs text-slate-500">
                        {{ client.sites_count }} site(s) · {{ client.equipment_count }} équip. · {{ client.documents_count }} fiche(s)
                    </p>
                </li>
            </ul>
            <EmptyState v-else title="Aucun client accessible" description="Un administrateur doit vous attribuer des clients pour que leurs informations apparaissent ici." />
        </section>

        <section class="card" aria-labelledby="changes-title">
            <header class="border-b border-slate-200 px-4 py-3">
                <h2 id="changes-title" class="text-sm font-semibold text-navy-900">Derniers éléments modifiés</h2>
            </header>
            <ul v-if="recentChanges.length" class="divide-y divide-slate-100">
                <li v-for="entry in recentChanges" :key="entry.id" class="px-4 py-2.5 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate">
                                <span class="text-slate-500">{{ entry.subject_type_label }} :</span>
                                <Link v-if="entry.subject_url" :href="entry.subject_url" class="link">{{ entry.subject_label }}</Link>
                                <span v-else>{{ entry.subject_label }}</span>
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ entry.event_label }}<template v-if="entry.client"> · {{ entry.client.name }}</template
                                ><template v-if="entry.user"> · par {{ entry.user.name }}</template>
                            </p>
                        </div>
                        <time class="shrink-0 text-xs text-slate-500" :datetime="entry.created_at">{{ formatDateTime(entry.created_at) }}</time>
                    </div>
                </li>
            </ul>
            <EmptyState v-else title="Aucune modification récente" />
        </section>
    </div>
</template>
