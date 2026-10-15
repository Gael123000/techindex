<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Download, FolderOpen, Mail, MapPin, Pencil, Phone } from '@lucide/vue';
import { ref } from 'vue';
import { route } from 'ziggy-js';
import AttachmentsPanel from '@/components/AttachmentsPanel.vue';
import Badge from '@/components/Badge.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DeleteResource from '@/components/DeleteResource.vue';
import ExpiryBadge from '@/components/ExpiryBadge.vue';
import HistoryList from '@/components/HistoryList.vue';
import RecordValuesDisplay from '@/components/RecordValuesDisplay.vue';
import TagList from '@/components/TagList.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import type { AttachmentsPayload, AuditEntry, ClientContext, RecordPresentedSection, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

interface Dated {
    id: number;
    client_id: number;
    name: string;
    warranty_expires_at?: string;
    expires_at?: string;
}

const props = defineProps<{
    context: ClientContext;
    client: {
        id: number;
        name: string;
        reference: string;
        description: string | null;
        status: 'active' | 'archived';
        archived_at: string | null;
        address_line1: string | null;
        address_line2: string | null;
        postal_code: string | null;
        city: string | null;
        country: string | null;
        phone: string | null;
        email: string | null;
        website: string | null;
        notes: string | null;
        created_at: string;
        updated_at: string;
        account_manager: { id: number; name: string; email: string } | null;
        tags: TagRef[];
    };
    contacts: Array<{
        id: number;
        full_name: string;
        job_title: string | null;
        email: string | null;
        phone: string | null;
        mobile: string | null;
        is_primary: boolean;
        is_technical: boolean;
        site: { id: number; name: string } | null;
    }>;
    sites: Array<{ id: number; name: string; city: string | null; equipment_count: number }>;
    upcoming: { warranties: Dated[]; licenses: Dated[]; certificates: Dated[] };
    attachments: AttachmentsPayload;
    history: AuditEntry[];
    can: { update: boolean; archive: boolean; restore: boolean; delete: boolean; export: boolean };
    /** Champs ajoutés avec le constructeur de fiches. */
    extension: RecordPresentedSection[] | null;
}>();

const confirmArchive = ref(false);
const datasets = [
    { key: 'equipment', label: 'Équipements' },
    { key: 'sites', label: 'Sites' },
    { key: 'contacts', label: 'Contacts' },
    { key: 'services', label: 'Services' },
    { key: 'licenses', label: 'Licences' },
    { key: 'certificates', label: 'Certificats' },
    { key: 'subnets', label: 'Plan d\'adressage' },
    { key: 'documents', label: 'Index documentation' },
];

function archive(): void {
    router.post(route('clients.archive', props.client.id), {}, { onFinish: () => (confirmArchive.value = false) });
}

function restore(): void {
    router.post(route('clients.restore', props.client.id));
}

const address = [props.client.address_line1, props.client.address_line2, [props.client.postal_code, props.client.city].filter(Boolean).join(' '), props.client.country]
    .filter(Boolean)
    .join(', ');
</script>

<template>
    <ClientHeader :context="context" active="overview" title="Vue d'ensemble">
        <template #actions>
            <Link :href="route('files.clients.show', client.id)" class="btn-secondary"><FolderOpen class="h-4 w-4" aria-hidden="true" /> Dossier</Link>
            <Link v-if="can.update" :href="route('clients.edit', client.id)" class="btn-secondary"><Pencil class="h-4 w-4" aria-hidden="true" /> Modifier</Link>
            <button v-if="can.archive" type="button" class="btn-secondary" @click="confirmArchive = true"><Archive class="h-4 w-4" aria-hidden="true" /> Archiver</button>
            <button v-if="can.restore" type="button" class="btn-secondary" @click="restore"><ArchiveRestore class="h-4 w-4" aria-hidden="true" /> Restaurer</button>
            <DeleteResource
                v-if="can.delete"
                :url="route('clients.destroy', client.id)"
                :name="client.name"
                message="Le client, ses sites, équipements, accès, documents et fichiers seront supprimés définitivement. L'historique d'audit est conservé."
            />
        </template>
    </ClientHeader>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <section class="card p-5" aria-labelledby="identity-title">
                <h2 id="identity-title" class="sr-only">Informations générales</h2>
                <p v-if="client.description" class="mb-4 text-sm whitespace-pre-line text-slate-700">{{ client.description }}</p>
                <dl class="dl-grid">
                    <div>
                        <dt>Responsable interne</dt>
                        <dd>{{ client.account_manager?.name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Statut</dt>
                        <dd>
                            <Badge :color="client.status === 'active' ? 'green' : 'amber'">{{ client.status === 'active' ? 'Actif' : 'Archivé' }}</Badge>
                            <span v-if="client.archived_at" class="ml-1 text-xs text-slate-500">depuis le {{ formatDateTime(client.archived_at) }}</span>
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt>Adresse du siège</dt>
                        <dd class="flex items-start gap-1.5"><MapPin class="mt-0.5 h-3.5 w-3.5 text-slate-400" aria-hidden="true" />{{ address || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Téléphone</dt>
                        <dd>
                            <a v-if="client.phone" :href="`tel:${client.phone}`" class="link">{{ client.phone }}</a>
                            <template v-else>—</template>
                        </dd>
                    </div>
                    <div>
                        <dt>E-mail</dt>
                        <dd>
                            <a v-if="client.email" :href="`mailto:${client.email}`" class="link">{{ client.email }}</a>
                            <template v-else>—</template>
                        </dd>
                    </div>
                    <div>
                        <dt>Site web</dt>
                        <dd>
                            <a v-if="client.website" :href="client.website" target="_blank" rel="noopener noreferrer" class="link break-all">{{ client.website }}</a>
                            <template v-else>—</template>
                        </dd>
                    </div>
                    <div>
                        <dt>Étiquettes</dt>
                        <dd><TagList :tags="client.tags" /><span v-if="!client.tags.length">—</span></dd>
                    </div>
                </dl>
                <div v-if="client.notes" class="mt-4 rounded-md bg-slate-50 p-3 text-sm whitespace-pre-line text-slate-700">{{ client.notes }}</div>
            </section>

            <RecordValuesDisplay v-if="extension?.length" :sections="extension" />

            <section class="card" aria-labelledby="contacts-title">
                <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h2 id="contacts-title" class="text-sm font-semibold text-navy-900">Interlocuteurs</h2>
                    <Link :href="route('clients.contacts.index', client.id)" class="link text-xs">Tous les contacts</Link>
                </header>
                <ul v-if="contacts.length" class="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-2 sm:divide-y-0">
                    <li v-for="contact in contacts" :key="contact.id" class="px-4 py-3 text-sm">
                        <p class="font-medium text-slate-900">
                            {{ contact.full_name }}
                            <Badge v-if="contact.is_primary" color="teal" class="ml-1">Principal</Badge>
                            <Badge v-if="contact.is_technical" color="blue" class="ml-1">Technique</Badge>
                        </p>
                        <p class="text-xs text-slate-500">{{ [contact.job_title, contact.site?.name].filter(Boolean).join(' · ') }}</p>
                        <p class="mt-1 flex flex-wrap gap-x-3 text-xs">
                            <a v-if="contact.email" :href="`mailto:${contact.email}`" class="link inline-flex items-center gap-1"><Mail class="h-3 w-3" aria-hidden="true" />{{ contact.email }}</a>
                            <a v-if="contact.phone || contact.mobile" :href="`tel:${contact.mobile ?? contact.phone}`" class="link inline-flex items-center gap-1">
                                <Phone class="h-3 w-3" aria-hidden="true" />{{ contact.mobile ?? contact.phone }}
                            </a>
                        </p>
                    </li>
                </ul>
                <p v-else class="px-4 py-4 text-sm text-slate-500">Aucun contact enregistré.</p>
            </section>

            <section class="card" aria-labelledby="history-title">
                <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h2 id="history-title" class="text-sm font-semibold text-navy-900">Dernières modifications</h2>
                    <Link :href="route('clients.history', client.id)" class="link text-xs">Historique complet</Link>
                </header>
                <HistoryList :entries="history" show-subject />
            </section>
        </div>

        <div class="space-y-5">
            <section class="card" aria-labelledby="sites-title">
                <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h2 id="sites-title" class="text-sm font-semibold text-navy-900">Sites</h2>
                    <Link :href="route('clients.sites.index', client.id)" class="link text-xs">Tous les sites</Link>
                </header>
                <ul v-if="sites.length" class="divide-y divide-slate-100">
                    <li v-for="site in sites" :key="site.id" class="flex items-center justify-between px-4 py-2.5 text-sm">
                        <Link :href="route('clients.sites.show', [client.id, site.id])" class="link">{{ site.name }}</Link>
                        <span class="text-xs text-slate-500">{{ site.city }} · {{ site.equipment_count }} équip.</span>
                    </li>
                </ul>
                <p v-else class="px-4 py-4 text-sm text-slate-500">Aucun site.</p>
            </section>

            <section class="card" aria-labelledby="upcoming-title">
                <header class="border-b border-slate-200 px-4 py-3">
                    <h2 id="upcoming-title" class="text-sm font-semibold text-navy-900">Échéances (60 jours)</h2>
                </header>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="item in upcoming.warranties" :key="`w${item.id}`" class="flex items-center justify-between gap-2 px-4 py-2">
                        <span class="min-w-0 truncate">
                            <span class="text-xs text-slate-500">Garantie · </span>
                            <Link :href="route('clients.equipment.show', [client.id, item.id])" class="link">{{ item.name }}</Link>
                        </span>
                        <ExpiryBadge :date="item.warranty_expires_at ?? null" />
                    </li>
                    <li v-for="item in upcoming.licenses" :key="`l${item.id}`" class="flex items-center justify-between gap-2 px-4 py-2">
                        <span class="min-w-0 truncate">
                            <span class="text-xs text-slate-500">Licence · </span>
                            <Link :href="route('clients.licenses.show', [client.id, item.id])" class="link">{{ item.name }}</Link>
                        </span>
                        <ExpiryBadge :date="item.expires_at ?? null" />
                    </li>
                    <li v-for="item in upcoming.certificates" :key="`c${item.id}`" class="flex items-center justify-between gap-2 px-4 py-2">
                        <span class="min-w-0 truncate">
                            <span class="text-xs text-slate-500">Certificat · </span>
                            <Link :href="route('clients.certificates.show', [client.id, item.id])" class="link">{{ item.name }}</Link>
                        </span>
                        <ExpiryBadge :date="item.expires_at ?? null" :warn-days="30" />
                    </li>
                    <li v-if="!upcoming.warranties.length && !upcoming.licenses.length && !upcoming.certificates.length" class="px-4 py-4 text-slate-500">
                        Aucune échéance proche.
                    </li>
                </ul>
            </section>

            <section v-if="can.export" class="card" aria-labelledby="export-title">
                <header class="border-b border-slate-200 px-4 py-3">
                    <h2 id="export-title" class="text-sm font-semibold text-navy-900">Exporter</h2>
                    <p class="text-xs text-slate-500">Les secrets ne sont jamais exportés.</p>
                </header>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="dataset in datasets" :key="dataset.key" class="flex items-center justify-between px-4 py-2">
                        <span>{{ dataset.label }}</span>
                        <span class="flex gap-1">
                            <a :href="route('clients.export', [client.id, dataset.key, 'xlsx'])" class="btn-ghost btn-sm">
                                <Download class="h-3.5 w-3.5" aria-hidden="true" /> Excel<span class="sr-only"> – {{ dataset.label }}</span>
                            </a>
                            <a :href="route('clients.export', [client.id, dataset.key, 'csv'])" class="btn-ghost btn-sm">
                                CSV<span class="sr-only"> – {{ dataset.label }}</span>
                            </a>
                        </span>
                    </li>
                </ul>
            </section>

            <AttachmentsPanel :attachments="attachments" />
        </div>
    </div>

    <ConfirmDialog
        :show="confirmArchive"
        title="Archiver le client"
        :message="`« ${client.name} » passera en lecture seule. Ses données restent consultables et il pourra être restauré.`"
        confirm-label="Archiver"
        @close="confirmArchive = false"
        @confirm="archive"
    />
</template>
