<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Activity, Archive, ArrowDown, ArrowUp, ExternalLink, FilePlus2, Globe, Mail, Pencil, Phone, UserRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';
import AttachmentsPanel from '@/components/AttachmentsPanel.vue';
import Badge from '@/components/Badge.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CredentialsTable from '@/components/CredentialsTable.vue';
import DeleteResource from '@/components/DeleteResource.vue';
import DynamicIcon from '@/components/DynamicIcon.vue';
import ExpiryBadge from '@/components/ExpiryBadge.vue';
import HistoryList from '@/components/HistoryList.vue';
import IspLogo from '@/components/IspLogo.vue';
import RecordValuesDisplay from '@/components/RecordValuesDisplay.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TagList from '@/components/TagList.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatDateTime } from '@/lib/format';
import type { AttachmentsPayload, AuditEntry, ClientContext, CredentialRow, InternetLinkSummary, Option, RecordPresentedSection, Ref } from '@/types';

defineOptions({ layout: AppLayout });

interface LinkDetail extends InternetLinkSummary {
    operator_name: string | null;
    customer_number: string | null;
    contract_reference: string | null;
    connection_username: string | null;
    connection_mode_label: string | null;
    wan_vlan: number | null;
    wan_gateway: string | null;
    dns_servers: string | null;
    gtr: string | null;
    support_phone: string | null;
    monthly_cost: string | null;
    guaranteed_label: string | null;
    commissioned_at: string | null;
    description: string | null;
    notes: string | null;
    isp: {
        id: number;
        name: string;
        website: string | null;
        customer_area_url: string | null;
        status_url: string | null;
        support_phone: string | null;
        business_support_phone: string | null;
        support_email: string | null;
        notes: string | null;
        logo_url: string | null;
    } | null;
    equipment: Array<{ id: number; name: string; hostname: string | null; status: string; icon: string | null; type: string | null }>;
    documents: Array<{ id: number; title: string; status: string }>;
    subnets: Array<{ id: number; name: string; cidr: string; gateway: string | null; purpose_label: string | null }>;
    updated_at: string;
    editor: Ref | null;
}

const props = defineProps<{
    context: ClientContext;
    link: LinkDetail;
    credentials: CredentialRow[];
    accessTypes: Option[];
    attachments: AttachmentsPayload;
    history: AuditEntry[];
    commitmentWarnDays: number;
    can: { update: boolean; delete: boolean; write_secrets: boolean };
    /** Champs ajoutés avec le constructeur de fiches. */
    extension: RecordPresentedSection[] | null;
}>();

const confirmArchive = ref(false);
const roleColors: Record<string, string> = { primary: 'teal', backup: 'amber', aggregated: 'violet', other: 'slate' };

const cost = computed(() =>
    props.link.monthly_cost === null ? null : `${Number(props.link.monthly_cost).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })} HT / mois`,
);

const phones = computed(() =>
    [
        { label: 'Support du contrat', value: props.link.support_phone },
        { label: 'Assistance', value: props.link.isp?.support_phone ?? null },
        { label: 'Assistance professionnels', value: props.link.isp?.business_support_phone ?? null },
    ].filter((phone): phone is { label: string; value: string } => phone.value !== null && phone.value !== ''),
);

function telHref(value: string): string {
    return `tel:${value.replace(/[^\d+]/g, '')}`;
}

function speed(mbps: number | null): string | null {
    if (mbps === null) {
        return null;
    }

    return mbps >= 1000 && mbps % 100 === 0 ? `${(mbps / 1000).toLocaleString('fr-FR')} Gbit/s` : `${mbps} Mbit/s`;
}

function archive(): void {
    router.post(route('clients.internet-links.archive', [props.context.id, props.link.id]), {}, { onFinish: () => (confirmArchive.value = false) });
}
</script>

<template>
    <ClientHeader
        :context="context"
        active="internet"
        :title="link.name"
        :description="[link.operator, link.technology_label].filter(Boolean).join(' · ')"
        :breadcrumbs="[{ label: 'FAI', href: route('clients.internet-links.index', context.id) }, { label: link.name }]"
    >
        <template #actions>
            <Link v-if="can.update" :href="route('clients.documents.create', { client: context.id, internet_link: link.id })" class="btn-secondary">
                <FilePlus2 class="h-4 w-4" aria-hidden="true" /> Rédiger une fiche
            </Link>
            <Link v-if="can.update" :href="route('clients.internet-links.edit', [context.id, link.id])" class="btn-secondary"><Pencil class="h-4 w-4" aria-hidden="true" /> Modifier</Link>
            <button v-if="can.update && link.status !== 'archived'" type="button" class="btn-secondary" @click="confirmArchive = true">
                <Archive class="h-4 w-4" aria-hidden="true" /> Archiver
            </button>
            <DeleteResource v-if="can.delete" :url="route('clients.internet-links.destroy', [context.id, link.id])" :name="link.name" />
        </template>
    </ClientHeader>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <section class="card p-5" aria-label="Lien Internet">
                <div class="flex flex-wrap items-center gap-4">
                    <IspLogo :url="link.logo_url" :name="link.operator" size="lg" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-slate-500">{{ link.operator ?? 'Opérateur non précisé' }}</p>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            <Badge :color="roleColors[link.role] ?? 'slate'">{{ link.role_label }}</Badge>
                            <StatusBadge :status="link.status" :label="link.status_label" />
                            <TagList :tags="link.tags" />
                        </div>
                    </div>
                    <div v-if="link.download_mbps !== null || link.upload_mbps !== null" class="flex gap-3">
                        <div v-if="link.download_mbps !== null" class="rounded-lg bg-turquoise-50 px-3 py-2 text-center">
                            <p class="flex items-center justify-center gap-1 text-xs text-turquoise-800"><ArrowDown class="h-3.5 w-3.5" aria-hidden="true" /> Descendant</p>
                            <p class="text-lg font-semibold text-navy-900">{{ speed(link.download_mbps) }}</p>
                        </div>
                        <div v-if="link.upload_mbps !== null" class="rounded-lg bg-slate-100 px-3 py-2 text-center">
                            <p class="flex items-center justify-center gap-1 text-xs text-slate-600"><ArrowUp class="h-3.5 w-3.5" aria-hidden="true" /> Montant</p>
                            <p class="text-lg font-semibold text-navy-900">{{ speed(link.upload_mbps) }}</p>
                        </div>
                    </div>
                </div>

                <h2 class="mt-6 mb-2 text-xs font-semibold tracking-wider text-slate-500 uppercase">Lien</h2>
                <dl class="dl-grid">
                    <div>
                        <dt>Technologie</dt>
                        <dd>{{ link.technology_label ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Débit garanti</dt>
                        <dd>{{ link.guaranteed_label ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Site desservi</dt>
                        <dd>
                            <Link v-if="link.site" :href="route('clients.sites.show', [context.id, link.site.id])" class="link">{{ link.site.name }}</Link>
                            <template v-else>—</template>
                        </dd>
                    </div>
                    <div>
                        <dt>Mise en service</dt>
                        <dd>{{ formatDate(link.commissioned_at) }}</dd>
                    </div>
                </dl>

                <h2 class="mt-6 mb-2 text-xs font-semibold tracking-wider text-slate-500 uppercase">Raccordement WAN</h2>
                <dl class="dl-grid">
                    <div class="sm:col-span-2">
                        <dt>Adresses IP publiques</dt>
                        <dd class="font-mono">{{ link.public_ips ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Mode de connexion</dt>
                        <dd>{{ link.connection_mode_label ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Identifiant de connexion</dt>
                        <dd class="font-mono">{{ link.connection_username ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>VLAN opérateur</dt>
                        <dd class="font-mono">{{ link.wan_vlan ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Passerelle</dt>
                        <dd class="font-mono">{{ link.wan_gateway ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Serveurs DNS</dt>
                        <dd class="font-mono">{{ link.dns_servers ?? '—' }}</dd>
                    </div>
                </dl>

                <h2 class="mt-6 mb-2 text-xs font-semibold tracking-wider text-slate-500 uppercase">Contrat</h2>
                <dl class="dl-grid">
                    <div>
                        <dt>Numéro de client</dt>
                        <dd class="font-mono">{{ link.customer_number ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Référence du contrat</dt>
                        <dd class="font-mono">{{ link.contract_reference ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Référence de ligne</dt>
                        <dd class="font-mono">{{ link.line_reference ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>GTR</dt>
                        <dd>{{ link.gtr ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Coût</dt>
                        <dd>{{ cost ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Fin d'engagement</dt>
                        <dd><ExpiryBadge :date="link.commitment_ends_at" :warn-days="commitmentWarnDays" /></dd>
                    </div>
                </dl>

                <p v-if="link.description" class="mt-5 text-sm whitespace-pre-line text-slate-700">{{ link.description }}</p>
                <div v-if="link.notes" class="mt-4 rounded-md bg-slate-50 p-3 text-sm whitespace-pre-line text-slate-700">{{ link.notes }}</div>
                <p class="mt-4 text-xs text-slate-400">
                    Modifié le {{ formatDateTime(link.updated_at) }}<template v-if="link.editor"> par {{ link.editor.name }}</template>
                </p>
            </section>

            <RecordValuesDisplay v-if="extension?.length" :sections="extension" />

            <CredentialsTable
                :client-id="context.id"
                :credentials="credentials"
                :types="accessTypes"
                :can-edit="can.update"
                :can-write-secrets="can.write_secrets"
                :fixed-internet-link-id="link.id"
                :show-target="false"
            />

            <section class="card" aria-labelledby="link-history">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="link-history" class="text-sm font-semibold text-navy-900">Historique</h2></header>
                <HistoryList :entries="history" />
            </section>
        </div>

        <div class="space-y-5">
            <section class="card" aria-labelledby="link-operator">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="link-operator" class="text-sm font-semibold text-navy-900">Opérateur et support</h2></header>
                <div class="space-y-3 p-4 text-sm">
                    <p class="font-medium text-navy-900">{{ link.operator ?? 'Opérateur non précisé' }}</p>
                    <ul v-if="phones.length" class="space-y-1.5">
                        <li v-for="phone in phones" :key="phone.label" class="flex items-center gap-2">
                            <Phone class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                            <span class="text-slate-500">{{ phone.label }} :</span>
                            <a :href="telHref(phone.value)" class="link font-medium">{{ phone.value }}</a>
                        </li>
                    </ul>
                    <p v-if="link.isp?.support_email" class="flex items-center gap-2">
                        <Mail class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                        <a :href="`mailto:${link.isp.support_email}`" class="link break-all">{{ link.isp.support_email }}</a>
                    </p>
                    <ul v-if="link.isp" class="space-y-1.5">
                        <li v-if="link.isp.customer_area_url">
                            <a :href="link.isp.customer_area_url" target="_blank" rel="noopener noreferrer" class="link inline-flex items-center gap-2">
                                <UserRound class="h-4 w-4 text-slate-400" aria-hidden="true" /> Espace client <ExternalLink class="h-3 w-3" aria-hidden="true" />
                            </a>
                        </li>
                        <li v-if="link.isp.status_url">
                            <a :href="link.isp.status_url" target="_blank" rel="noopener noreferrer" class="link inline-flex items-center gap-2">
                                <Activity class="h-4 w-4 text-slate-400" aria-hidden="true" /> État du réseau et incidents <ExternalLink class="h-3 w-3" aria-hidden="true" />
                            </a>
                        </li>
                        <li v-if="link.isp.website">
                            <a :href="link.isp.website" target="_blank" rel="noopener noreferrer" class="link inline-flex items-center gap-2">
                                <Globe class="h-4 w-4 text-slate-400" aria-hidden="true" /> Site de l'opérateur <ExternalLink class="h-3 w-3" aria-hidden="true" />
                            </a>
                        </li>
                    </ul>
                    <p v-if="link.isp?.notes" class="rounded-md bg-slate-50 p-2 text-xs whitespace-pre-line text-slate-600">{{ link.isp.notes }}</p>
                    <p v-if="!link.isp" class="text-xs text-slate-500">Opérateur hors catalogue : les coordonnées de support ne sont pas connues.</p>
                </div>
            </section>

            <section class="card" aria-labelledby="link-equipment">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="link-equipment" class="text-sm font-semibold text-navy-900">Équipements raccordés</h2></header>
                <ul v-if="link.equipment.length" class="divide-y divide-slate-100 text-sm">
                    <li v-for="item in link.equipment" :key="item.id" class="flex items-center gap-2 px-4 py-2">
                        <DynamicIcon :name="item.icon" class="h-4 w-4 shrink-0 text-slate-400" />
                        <span class="min-w-0 flex-1">
                            <Link :href="route('clients.equipment.show', [context.id, item.id])" class="link">{{ item.name }}</Link>
                            <span v-if="item.type" class="block text-xs text-slate-500">{{ item.type }}<template v-if="item.hostname"> · {{ item.hostname }}</template></span>
                        </span>
                    </li>
                </ul>
                <p v-else class="px-4 py-4 text-sm text-slate-500">Aucun équipement lié.</p>
            </section>

            <section class="card" aria-labelledby="link-subnets">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="link-subnets" class="text-sm font-semibold text-navy-900">Réseaux desservis</h2></header>
                <ul v-if="link.subnets.length" class="divide-y divide-slate-100 text-sm">
                    <li v-for="subnet in link.subnets" :key="subnet.id" class="px-4 py-2">
                        <Link :href="route('clients.network.index', context.id)" class="link font-mono text-xs">{{ subnet.cidr }}</Link>
                        <span class="text-slate-700"> · {{ subnet.name }}</span>
                        <span v-if="subnet.purpose_label" class="block text-xs text-slate-500">{{ subnet.purpose_label }}<template v-if="subnet.gateway"> · passerelle {{ subnet.gateway }}</template></span>
                    </li>
                </ul>
                <p v-else class="px-4 py-4 text-sm text-slate-500">
                    Aucun réseau lié. Reliez un sous-réseau (WAN par exemple) à ce lien depuis l'onglet
                    <Link :href="route('clients.network.index', context.id)" class="link">Réseau</Link>.
                </p>
            </section>

            <section class="card" aria-labelledby="link-docs">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="link-docs" class="text-sm font-semibold text-navy-900">Documentation</h2></header>
                <ul v-if="link.documents.length" class="divide-y divide-slate-100 text-sm">
                    <li v-for="doc in link.documents" :key="doc.id" class="px-4 py-2">
                        <Link :href="route('clients.documents.show', [context.id, doc.id])" class="link">{{ doc.title }}</Link>
                    </li>
                </ul>
                <p v-else class="px-4 py-4 text-sm text-slate-500">Aucun document lié.</p>
            </section>

            <AttachmentsPanel :attachments="attachments" />
        </div>
    </div>

    <ConfirmDialog
        :show="confirmArchive"
        title="Archiver le lien"
        :message="`« ${link.name} » sera conservé avec son historique mais masqué des listes courantes.`"
        confirm-label="Archiver"
        @close="confirmArchive = false"
        @confirm="archive"
    />
</template>
