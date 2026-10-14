<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { route } from 'ziggy-js';
import ClientHeader from '@/components/ClientHeader.vue';
import DeleteResource from '@/components/DeleteResource.vue';
import ExpiryBadge from '@/components/ExpiryBadge.vue';
import HistoryList from '@/components/HistoryList.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import type { AuditEntry, ClientContext } from '@/types';

defineOptions({ layout: AppLayout });

defineProps<{
    context: ClientContext;
    certificate: {
        id: number;
        name: string;
        common_name: string | null;
        subject_alt_names: string | null;
        issuer: string | null;
        type_label: string;
        serial_number: string | null;
        fingerprint_sha256: string | null;
        issued_at: string | null;
        expires_at: string | null;
        auto_renewal: boolean;
        renewal_method: string | null;
        status: string;
        status_label: string;
        notes: string | null;
        site: { id: number; name: string } | null;
        service: { id: number; name: string } | null;
        equipment: Array<{ id: number; name: string }>;
    };
    history: AuditEntry[];
    warnDays: number;
    can: { update: boolean; delete: boolean };
}>();
</script>

<template>
    <ClientHeader
        :context="context"
        active="certificates"
        :title="certificate.name"
        :description="certificate.type_label"
        :breadcrumbs="[{ label: 'Certificats', href: route('clients.certificates.index', context.id) }, { label: certificate.name }]"
    >
        <template #actions>
            <Link v-if="can.update" :href="route('clients.certificates.edit', [context.id, certificate.id])" class="btn-secondary"><Pencil class="h-4 w-4" aria-hidden="true" /> Modifier</Link>
            <DeleteResource v-if="can.delete" :url="route('clients.certificates.destroy', [context.id, certificate.id])" :name="certificate.name" />
        </template>
    </ClientHeader>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <section class="card p-5">
                <div class="mb-4"><StatusBadge :status="certificate.status" :label="certificate.status_label" /></div>
                <dl class="dl-grid">
                    <div><dt>Nom commun</dt><dd class="font-mono text-xs">{{ certificate.common_name ?? '—' }}</dd></div>
                    <div><dt>Émetteur</dt><dd>{{ certificate.issuer ?? '—' }}</dd></div>
                    <div><dt>Émis le</dt><dd>{{ formatDate(certificate.issued_at) }}</dd></div>
                    <div><dt>Expire le</dt><dd><ExpiryBadge :date="certificate.expires_at" :warn-days="warnDays" /></dd></div>
                    <div><dt>Numéro de série</dt><dd class="font-mono text-xs break-all">{{ certificate.serial_number ?? '—' }}</dd></div>
                    <div><dt>Empreinte SHA-256</dt><dd class="font-mono text-xs break-all">{{ certificate.fingerprint_sha256 ?? '—' }}</dd></div>
                    <div><dt>Renouvellement automatique</dt><dd>{{ certificate.auto_renewal ? 'Oui' : 'Non' }}</dd></div>
                    <div>
                        <dt>Service</dt>
                        <dd>
                            <Link v-if="certificate.service" :href="route('clients.services.show', [context.id, certificate.service.id])" class="link">{{ certificate.service.name }}</Link>
                            <template v-else>—</template>
                        </dd>
                    </div>
                    <div class="sm:col-span-2"><dt>Noms alternatifs</dt><dd class="font-mono text-xs whitespace-pre-line">{{ certificate.subject_alt_names ?? '—' }}</dd></div>
                </dl>
                <div v-if="certificate.renewal_method" class="mt-4">
                    <h2 class="text-xs font-medium tracking-wide text-slate-500 uppercase">Méthode de renouvellement</h2>
                    <p class="mt-1 text-sm whitespace-pre-line">{{ certificate.renewal_method }}</p>
                </div>
                <div v-if="certificate.notes" class="mt-4 rounded-md bg-slate-50 p-3 text-sm whitespace-pre-line text-slate-700">{{ certificate.notes }}</div>
            </section>
            <section class="card">
                <header class="border-b border-slate-200 px-4 py-3"><h2 class="text-sm font-semibold text-navy-900">Historique</h2></header>
                <HistoryList :entries="history" />
            </section>
        </div>
        <section class="card self-start">
            <header class="border-b border-slate-200 px-4 py-3"><h2 class="text-sm font-semibold text-navy-900">Équipements</h2></header>
            <ul v-if="certificate.equipment.length" class="divide-y divide-slate-100 text-sm">
                <li v-for="item in certificate.equipment" :key="item.id" class="px-4 py-2">
                    <Link :href="route('clients.equipment.show', [context.id, item.id])" class="link">{{ item.name }}</Link>
                </li>
            </ul>
            <p v-else class="px-4 py-4 text-sm text-slate-500">Aucun équipement lié.</p>
        </section>
    </div>
</template>
