<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { CircleCheck, Pencil } from '@lucide/vue';
import { route } from 'ziggy-js';
import AttachmentsPanel from '@/components/AttachmentsPanel.vue';
import Badge from '@/components/Badge.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import DeleteResource from '@/components/DeleteResource.vue';
import HistoryList from '@/components/HistoryList.vue';
import RecordValuesDisplay from '@/components/RecordValuesDisplay.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TagList from '@/components/TagList.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatDateTime } from '@/lib/format';
import type { AttachmentsPayload, AuditEntry, ClientContext, RecordPresentedSection, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    context: ClientContext;
    document: {
        id: number;
        title: string;
        content_html: string;
        status: string;
        status_label: string;
        category: { id: number; name: string } | null;
        owner: { id: number; name: string } | null;
        verifier: { id: number; name: string } | null;
        last_verified_at: string | null;
        next_review_at: string | null;
        needs_review: boolean;
        review_interval_days: number | null;
        current_version: number;
        tags: TagRef[];
        equipment: Array<{ id: number; name: string; hostname: string | null }>;
        services: Array<{ id: number; name: string; category: string }>;
        internet_links: Array<{ id: number; name: string; operator: string | null }>;
        updated_at: string;
        editor: { id: number; name: string } | null;
    };
    versions: Array<{ id: number; version: number; title: string; change_summary: string | null; created_at: string; author: { id: number; name: string } | null }>;
    attachments: AttachmentsPayload;
    history: AuditEntry[];
    can: { update: boolean; delete: boolean };
    /** Champs ajoutés avec le constructeur de fiches. */
    extension: RecordPresentedSection[] | null;
}>();

function verify(): void {
    router.post(route('clients.documents.verify', [props.context.id, props.document.id]), {}, { preserveScroll: true });
}
</script>

<template>
    <ClientHeader
        :context="context"
        active="documents"
        :title="document.title"
        :breadcrumbs="[{ label: 'Documentation', href: route('clients.documents.index', context.id) }, { label: document.title }]"
    >
        <template #actions>
            <button v-if="can.update" type="button" class="btn-secondary" @click="verify"><CircleCheck class="h-4 w-4" aria-hidden="true" /> Marquer comme vérifiée</button>
            <Link v-if="can.update" :href="route('clients.documents.edit', [context.id, document.id])" class="btn-primary"><Pencil class="h-4 w-4" aria-hidden="true" /> Modifier</Link>
            <DeleteResource v-if="can.delete" :url="route('clients.documents.destroy', [context.id, document.id])" :name="document.title" />
        </template>
    </ClientHeader>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <div v-if="document.needs_review" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
                Cette fiche doit être vérifiée : relisez-la puis cliquez sur « Marquer comme vérifiée ».
            </div>
            <article class="card p-6">
                <!-- HTML nettoyé côté serveur (liste blanche stricte) à l'enregistrement et à l'affichage. -->
                <div v-if="document.content_html" class="doc-content" v-html="document.content_html" />
                <p v-else class="text-sm text-slate-500">Cette fiche est vide.</p>
            </article>
            <section class="card">
                <header class="border-b border-slate-200 px-4 py-3"><h2 class="text-sm font-semibold text-navy-900">Historique</h2></header>
                <HistoryList :entries="history" />
            </section>

            <RecordValuesDisplay v-if="extension?.length" :sections="extension" />
        </div>

        <div class="space-y-5">
            <section class="card p-4">
                <dl class="space-y-3 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge :status="document.status" :label="document.status_label" />
                        <Badge color="slate">Version {{ document.current_version }}</Badge>
                        <Badge v-if="document.needs_review" color="amber">À vérifier</Badge>
                    </div>
                    <div><dt class="text-xs text-slate-500">Catégorie</dt><dd>{{ document.category?.name ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Responsable</dt><dd>{{ document.owner?.name ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-xs text-slate-500">Dernière vérification</dt>
                        <dd>
                            {{ document.last_verified_at ? formatDateTime(document.last_verified_at) : 'Jamais' }}
                            <template v-if="document.verifier"> par {{ document.verifier.name }}</template>
                        </dd>
                    </div>
                    <div><dt class="text-xs text-slate-500">Prochaine vérification</dt><dd>{{ document.next_review_at ? formatDate(document.next_review_at) : '—' }}</dd></div>
                    <div>
                        <dt class="text-xs text-slate-500">Dernière modification</dt>
                        <dd>{{ formatDateTime(document.updated_at) }}<template v-if="document.editor"> par {{ document.editor.name }}</template></dd>
                    </div>
                    <div v-if="document.tags.length"><dt class="sr-only">Étiquettes</dt><dd><TagList :tags="document.tags" /></dd></div>
                </dl>
            </section>

            <section class="card">
                <header class="border-b border-slate-200 px-4 py-3"><h2 class="text-sm font-semibold text-navy-900">S'applique à</h2></header>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="item in document.equipment" :key="`e${item.id}`" class="px-4 py-2">
                        <span class="text-xs text-slate-500">Équipement · </span>
                        <Link :href="route('clients.equipment.show', [context.id, item.id])" class="link">{{ item.name }}</Link>
                    </li>
                    <li v-for="item in document.services" :key="`s${item.id}`" class="px-4 py-2">
                        <span class="text-xs text-slate-500">{{ item.category }} · </span>
                        <Link :href="route('clients.services.show', [context.id, item.id])" class="link">{{ item.name }}</Link>
                    </li>
                    <li v-for="item in document.internet_links" :key="`i${item.id}`" class="px-4 py-2">
                        <span class="text-xs text-slate-500">Lien Internet<template v-if="item.operator"> {{ item.operator }}</template> · </span>
                        <Link :href="route('clients.internet-links.show', [context.id, item.id])" class="link">{{ item.name }}</Link>
                    </li>
                    <li v-if="!document.equipment.length && !document.services.length && !document.internet_links.length" class="px-4 py-3 text-slate-500">Aucun élément lié.</li>
                </ul>
            </section>

            <section class="card">
                <header class="border-b border-slate-200 px-4 py-3"><h2 class="text-sm font-semibold text-navy-900">Versions</h2></header>
                <ol class="divide-y divide-slate-100 text-sm">
                    <li v-for="version in versions" :key="version.id" class="px-4 py-2">
                        <Link :href="route('clients.documents.versions.show', [context.id, document.id, version.id])" class="link">Version {{ version.version }}</Link>
                        <span class="text-xs text-slate-500"> · {{ formatDateTime(version.created_at) }}<template v-if="version.author"> · {{ version.author.name }}</template></span>
                        <p v-if="version.change_summary" class="text-xs text-slate-600">{{ version.change_summary }}</p>
                    </li>
                </ol>
            </section>

            <AttachmentsPanel :attachments="attachments" />
        </div>
    </div>
</template>
