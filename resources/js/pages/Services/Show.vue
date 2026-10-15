<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Archive, ExternalLink, FilePlus2, Pencil } from '@lucide/vue';
import { ref } from 'vue';
import { route } from 'ziggy-js';
import AttachmentsPanel from '@/components/AttachmentsPanel.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CredentialsTable from '@/components/CredentialsTable.vue';
import DeleteResource from '@/components/DeleteResource.vue';
import ExpiryBadge from '@/components/ExpiryBadge.vue';
import HistoryList from '@/components/HistoryList.vue';
import RecordValuesDisplay from '@/components/RecordValuesDisplay.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TagList from '@/components/TagList.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatDateTime } from '@/lib/format';
import type { AttachmentsPayload, AuditEntry, ClientContext, CredentialRow, Option, RecordPresentedSection, ServiceCategoryDefinition, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    context: ClientContext;
    service: {
        id: number;
        name: string;
        provider: string | null;
        reference: string | null;
        url: string | null;
        description: string | null;
        notes: string | null;
        details: Record<string, string | number | boolean | null> | null;
        category: string;
        category_label: string;
        status: string;
        status_label: string;
        start_date: string | null;
        end_date: string | null;
        site: { id: number; name: string } | null;
        tags: TagRef[];
        equipment: Array<{ id: number; name: string; hostname: string | null; status: string }>;
        documents: Array<{ id: number; title: string }>;
        certificates: Array<{ id: number; name: string; expires_at: string | null }>;
        created_at: string;
        updated_at: string;
        creator: { id: number; name: string } | null;
        editor: { id: number; name: string } | null;
    };
    definition: ServiceCategoryDefinition;
    credentials: CredentialRow[];
    accessTypes: Option[];
    attachments: AttachmentsPayload;
    history: AuditEntry[];
    can: { update: boolean; delete: boolean; write_secrets: boolean };
    /** Champs ajoutés avec le constructeur de fiches. */
    extension: RecordPresentedSection[] | null;
}>();

const confirmArchive = ref(false);

function detailValue(key: string, type: string): string {
    const value = props.service.details?.[key];

    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (type === 'boolean') {
        return value ? 'Oui' : 'Non';
    }

    if (type === 'date') {
        return formatDate(String(value));
    }

    return String(value);
}

function archive(): void {
    router.post(route('clients.services.archive', [props.context.id, props.service.id]), {}, { onFinish: () => (confirmArchive.value = false) });
}
</script>

<template>
    <ClientHeader
        :context="context"
        active="services"
        :title="service.name"
        :description="service.category_label"
        :breadcrumbs="[{ label: 'Services', href: route('clients.services.index', context.id) }, { label: service.name }]"
    >
        <template #actions>
            <Link v-if="can.update" :href="route('clients.documents.create', { client: context.id, service: service.id })" class="btn-secondary">
                <FilePlus2 class="h-4 w-4" aria-hidden="true" /> Rédiger une fiche
            </Link>
            <Link v-if="can.update" :href="route('clients.services.edit', [context.id, service.id])" class="btn-secondary"><Pencil class="h-4 w-4" aria-hidden="true" /> Modifier</Link>
            <button v-if="can.update && service.status !== 'archived'" type="button" class="btn-secondary" @click="confirmArchive = true">
                <Archive class="h-4 w-4" aria-hidden="true" /> Archiver
            </button>
            <DeleteResource v-if="can.delete" :url="route('clients.services.destroy', [context.id, service.id])" :name="service.name" />
        </template>
    </ClientHeader>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <section class="card p-5" aria-label="Informations du service">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <StatusBadge :status="service.status" :label="service.status_label" />
                    <TagList :tags="service.tags" />
                </div>
                <dl class="dl-grid">
                    <div>
                        <dt>{{ definition.provider_label }}</dt>
                        <dd>{{ service.provider ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Référence</dt>
                        <dd>{{ service.reference ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Site</dt>
                        <dd>{{ service.site?.name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Début</dt>
                        <dd>{{ formatDate(service.start_date) }}</dd>
                    </div>
                    <div>
                        <dt>Échéance</dt>
                        <dd><ExpiryBadge :date="service.end_date" /></dd>
                    </div>
                    <div>
                        <dt>URL</dt>
                        <dd>
                            <a v-if="service.url" :href="service.url" target="_blank" rel="noopener noreferrer" class="link inline-flex items-center gap-1 break-all">
                                {{ service.url }} <ExternalLink class="h-3 w-3" aria-hidden="true" />
                            </a>
                            <template v-else>—</template>
                        </dd>
                    </div>
                    <div v-for="field in definition.fields" :key="field.key" :class="field.type === 'textarea' ? 'sm:col-span-2' : ''">
                        <dt>{{ field.label }}</dt>
                        <dd class="whitespace-pre-line">{{ detailValue(field.key, field.type) }}</dd>
                    </div>
                </dl>
                <p v-if="service.description" class="mt-4 text-sm whitespace-pre-line text-slate-700">{{ service.description }}</p>
                <div v-if="service.notes" class="mt-4 rounded-md bg-slate-50 p-3 text-sm whitespace-pre-line text-slate-700">{{ service.notes }}</div>
                <p class="mt-4 text-xs text-slate-400">
                    Modifié le {{ formatDateTime(service.updated_at) }}<template v-if="service.editor"> par {{ service.editor.name }}</template>
                </p>
            </section>

            <RecordValuesDisplay v-if="extension?.length" :sections="extension" />

            <CredentialsTable
                :client-id="context.id"
                :credentials="credentials"
                :types="accessTypes"
                :can-edit="can.update"
                :can-write-secrets="can.write_secrets"
                :fixed-service-id="service.id"
                :show-target="false"
                :vault-url="route('vault.clients.show', { client: context.id, service: service.id })"
            />

            <section class="card" aria-labelledby="srv-history">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="srv-history" class="text-sm font-semibold text-navy-900">Historique</h2></header>
                <HistoryList :entries="history" />
            </section>
        </div>

        <div class="space-y-5">
            <section class="card" aria-labelledby="srv-equipment">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="srv-equipment" class="text-sm font-semibold text-navy-900">Équipements concernés</h2></header>
                <ul v-if="service.equipment.length" class="divide-y divide-slate-100 text-sm">
                    <li v-for="item in service.equipment" :key="item.id" class="px-4 py-2">
                        <Link :href="route('clients.equipment.show', [context.id, item.id])" class="link">{{ item.name }}</Link>
                        <span v-if="item.hostname" class="text-xs text-slate-500"> · {{ item.hostname }}</span>
                    </li>
                </ul>
                <p v-else class="px-4 py-4 text-sm text-slate-500">Aucun équipement lié.</p>
            </section>

            <section class="card" aria-labelledby="srv-docs">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="srv-docs" class="text-sm font-semibold text-navy-900">Documentation et certificats</h2></header>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="doc in service.documents" :key="`d${doc.id}`" class="px-4 py-2">
                        <Link :href="route('clients.documents.show', [context.id, doc.id])" class="link">{{ doc.title }}</Link>
                    </li>
                    <li v-for="cert in service.certificates" :key="`c${cert.id}`" class="flex items-center justify-between gap-2 px-4 py-2">
                        <Link :href="route('clients.certificates.show', [context.id, cert.id])" class="link">{{ cert.name }}</Link>
                        <ExpiryBadge :date="cert.expires_at" :warn-days="30" />
                    </li>
                    <li v-if="!service.documents.length && !service.certificates.length" class="px-4 py-4 text-slate-500">Aucun élément lié.</li>
                </ul>
            </section>

            <AttachmentsPanel :attachments="attachments" />
        </div>
    </div>

    <ConfirmDialog
        :show="confirmArchive"
        title="Archiver le service"
        :message="`« ${service.name} » sera conservé avec son historique mais masqué des listes courantes.`"
        confirm-label="Archiver"
        @close="confirmArchive = false"
        @confirm="archive"
    />
</template>
