<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { route } from 'ziggy-js';
import ClientHeader from '@/components/ClientHeader.vue';
import DeleteResource from '@/components/DeleteResource.vue';
import ExpiryBadge from '@/components/ExpiryBadge.vue';
import HistoryList from '@/components/HistoryList.vue';
import RecordValuesDisplay from '@/components/RecordValuesDisplay.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency, formatDate, formatDateTime } from '@/lib/format';
import type { AuditEntry, ClientContext, RecordPresentedSection } from '@/types';

defineOptions({ layout: AppLayout });

defineProps<{
    context: ClientContext;
    license: {
        id: number;
        name: string;
        kind_label: string;
        status: string;
        status_label: string;
        billing_period_label: string | null;
        publisher: string | null;
        supplier: string | null;
        contract_reference: string | null;
        quantity: number | null;
        assigned_quantity: number | null;
        cost: string | null;
        purchase_date: string | null;
        starts_at: string | null;
        expires_at: string | null;
        auto_renewal: boolean;
        renewal_notice_days: number | null;
        renewal_notes: string | null;
        notes: string | null;
        site: { id: number; name: string } | null;
        equipment: Array<{ id: number; name: string; hostname: string | null }>;
    };
    /** Intégration d'où provient la licence (null : saisie à la main). */
    source: { label: string; account: string; synced_at: string | null } | null;
    history: AuditEntry[];
    warnDays: number;
    can: { update: boolean; delete: boolean };
    /** Champs ajoutés avec le constructeur de fiches. */
    extension: RecordPresentedSection[] | null;
}>();
</script>

<template>
    <ClientHeader
        :context="context"
        active="licenses"
        :title="license.name"
        :description="license.kind_label"
        :breadcrumbs="[{ label: 'Licences', href: route('clients.licenses.index', context.id) }, { label: license.name }]"
    >
        <template #actions>
            <Link v-if="can.update" :href="route('clients.licenses.edit', [context.id, license.id])" class="btn-secondary"><Pencil class="h-4 w-4" aria-hidden="true" /> Modifier</Link>
            <DeleteResource v-if="can.delete" :url="route('clients.licenses.destroy', [context.id, license.id])" :name="license.name" />
        </template>
    </ClientHeader>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <section class="card p-5">
                <div class="mb-4"><StatusBadge :status="license.status" :label="license.status_label" /></div>
                <p v-if="source" class="mb-4 rounded-md border border-turquoise-200 bg-turquoise-50 px-3 py-2 text-xs text-turquoise-900">
                    Synchronisée depuis <strong>{{ source.label }}</strong> (compte « {{ source.account }} »)<template v-if="source.synced_at">, le {{ formatDateTime(source.synced_at) }}</template>.
                    Nom, quantités, dates et statut sont mis à jour automatiquement ; le site, le coût et les notes restent modifiables.
                </p>
                <dl class="dl-grid">
                    <div><dt>Éditeur</dt><dd>{{ license.publisher ?? '—' }}</dd></div>
                    <div><dt>Fournisseur</dt><dd>{{ license.supplier ?? '—' }}</dd></div>
                    <div><dt>Référence du contrat</dt><dd>{{ license.contract_reference ?? '—' }}</dd></div>
                    <div><dt>Quantité</dt><dd>{{ license.quantity ?? '—' }}</dd></div>
                    <div v-if="license.assigned_quantity !== null"><dt>Attribuées ou utilisées</dt><dd>{{ license.assigned_quantity }}<template v-if="license.quantity"> sur {{ license.quantity }}</template></dd></div>
                    <div><dt>Périodicité</dt><dd>{{ license.billing_period_label ?? '—' }}</dd></div>
                    <div><dt>Coût</dt><dd>{{ formatCurrency(license.cost) }}</dd></div>
                    <div><dt>Date d'achat</dt><dd>{{ formatDate(license.purchase_date) }}</dd></div>
                    <div><dt>Début</dt><dd>{{ formatDate(license.starts_at) }}</dd></div>
                    <div><dt>Échéance</dt><dd><ExpiryBadge :date="license.expires_at" :warn-days="warnDays" /></dd></div>
                    <div><dt>Renouvellement automatique</dt><dd>{{ license.auto_renewal ? 'Oui' : 'Non' }}</dd></div>
                    <div><dt>Préavis</dt><dd>{{ license.renewal_notice_days !== null ? `${license.renewal_notice_days} jours` : '—' }}</dd></div>
                    <div><dt>Site</dt><dd>{{ license.site?.name ?? '—' }}</dd></div>
                </dl>
                <div v-if="license.renewal_notes" class="mt-4">
                    <h2 class="text-xs font-medium tracking-wide text-slate-500 uppercase">Informations de renouvellement</h2>
                    <p class="mt-1 text-sm whitespace-pre-line">{{ license.renewal_notes }}</p>
                </div>
                <div v-if="license.notes" class="mt-4 rounded-md bg-slate-50 p-3 text-sm whitespace-pre-line text-slate-700">{{ license.notes }}</div>
            </section>

            <RecordValuesDisplay v-if="extension?.length" :sections="extension" />
            <section class="card">
                <header class="border-b border-slate-200 px-4 py-3"><h2 class="text-sm font-semibold text-navy-900">Historique</h2></header>
                <HistoryList :entries="history" />
            </section>
        </div>
        <section class="card self-start">
            <header class="border-b border-slate-200 px-4 py-3"><h2 class="text-sm font-semibold text-navy-900">Équipements concernés</h2></header>
            <ul v-if="license.equipment.length" class="divide-y divide-slate-100 text-sm">
                <li v-for="item in license.equipment" :key="item.id" class="px-4 py-2">
                    <Link :href="route('clients.equipment.show', [context.id, item.id])" class="link">{{ item.name }}</Link>
                </li>
            </ul>
            <p v-else class="px-4 py-4 text-sm text-slate-500">Aucun équipement lié.</p>
        </section>
    </div>
</template>
