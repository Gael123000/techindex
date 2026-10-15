<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Pencil } from '@lucide/vue';
import { ref } from 'vue';
import { route } from 'ziggy-js';
import AttachmentsPanel from '@/components/AttachmentsPanel.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DeleteResource from '@/components/DeleteResource.vue';
import HistoryList from '@/components/HistoryList.vue';
import RecordValuesDisplay from '@/components/RecordValuesDisplay.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TagList from '@/components/TagList.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import type { AttachmentsPayload, AuditEntry, ClientContext, RecordPresentedSection, Ref, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    context: ClientContext;
    recordType: { id: number; key: string; name: string; plural_name: string; description: string | null; icon: string; has_site: boolean; has_tags: boolean; has_attachments: boolean };
    record: {
        id: number;
        title: string;
        status: string;
        site: Ref | null;
        tags: TagRef[];
        created_at: string;
        updated_at: string;
        creator: Ref | null;
        editor: Ref | null;
    };
    sections: RecordPresentedSection[];
    backlinks: Array<{ id: number; title: string; status: string; type: string; field: string; url: string }>;
    attachments: AttachmentsPayload | null;
    history: AuditEntry[];
    can: { update: boolean; delete: boolean };
}>();

const confirmArchive = ref(false);

function toggleArchive(): void {
    const name = props.record.status === 'archived' ? 'clients.records.restore' : 'clients.records.archive';
    router.post(route(name, [props.context.id, props.record.id]), {}, { preserveScroll: true, onFinish: () => (confirmArchive.value = false) });
}
</script>

<template>
    <ClientHeader
        :context="context"
        :active="`record:${recordType.key}`"
        :title="record.title"
        :description="recordType.name"
        :breadcrumbs="[{ label: recordType.plural_name, href: route('clients.records.index', [context.id, recordType.key]) }, { label: record.title }]"
    >
        <template #actions>
            <Link v-if="can.update" :href="route('clients.records.edit', [context.id, record.id])" class="btn-secondary"><Pencil class="h-4 w-4" aria-hidden="true" /> Modifier</Link>
            <button v-if="can.update && record.status !== 'archived'" type="button" class="btn-secondary" @click="confirmArchive = true">
                <Archive class="h-4 w-4" aria-hidden="true" /> Archiver
            </button>
            <button v-if="can.update && record.status === 'archived'" type="button" class="btn-secondary" @click="toggleArchive">
                <ArchiveRestore class="h-4 w-4" aria-hidden="true" /> Restaurer
            </button>
            <DeleteResource v-if="can.delete" :url="route('clients.records.destroy', [context.id, record.id])" :name="record.title" />
        </template>
    </ClientHeader>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <div v-if="record.status === 'archived' || record.tags.length || record.site" class="flex flex-wrap items-center gap-2 text-sm">
                <StatusBadge v-if="record.status === 'archived'" status="archived" />
                <span v-if="record.site" class="text-slate-600">
                    Site :
                    <Link :href="route('clients.sites.show', [context.id, record.site.id])" class="link">{{ record.site.name }}</Link>
                </span>
                <TagList :tags="record.tags" />
            </div>

            <RecordValuesDisplay :sections="sections" />
            <p v-if="!sections.length" class="card p-5 text-sm text-slate-500">Aucune information saisie.</p>

            <p class="text-xs text-slate-400">
                Créé le {{ formatDateTime(record.created_at) }}<template v-if="record.creator"> par {{ record.creator.name }}</template> ·
                modifié le {{ formatDateTime(record.updated_at) }}<template v-if="record.editor"> par {{ record.editor.name }}</template>
            </p>

            <section class="card" aria-labelledby="rec-history">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="rec-history" class="text-sm font-semibold text-navy-900">Historique</h2></header>
                <HistoryList :entries="history" />
            </section>
        </div>

        <div class="space-y-5">
            <section v-if="backlinks.length" class="card" aria-labelledby="rec-backlinks">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="rec-backlinks" class="text-sm font-semibold text-navy-900">Fiches liées</h2></header>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="link in backlinks" :key="`${link.type}-${link.id}`" class="px-4 py-2">
                        <Link :href="link.url" class="link">{{ link.title }}</Link>
                        <StatusBadge v-if="link.status === 'archived'" status="archived" class="ml-1" />
                        <p class="text-xs text-slate-500">{{ link.type }} · champ « {{ link.field }} »</p>
                    </li>
                </ul>
            </section>

            <AttachmentsPanel v-if="attachments" :attachments="attachments" />
        </div>
    </div>

    <ConfirmDialog
        :show="confirmArchive"
        title="Archiver"
        :message="`« ${record.title} » sera conservé avec son historique mais masqué de la liste courante.`"
        confirm-label="Archiver"
        @close="confirmArchive = false"
        @confirm="toggleArchive"
    />
</template>
