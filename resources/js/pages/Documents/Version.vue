<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import ClientHeader from '@/components/ClientHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import type { ClientContext } from '@/types';

defineOptions({ layout: AppLayout });

defineProps<{
    context: ClientContext;
    document: { id: number; title: string; current_version: number };
    version: {
        id: number;
        version: number;
        title: string;
        content_html: string | null;
        change_summary: string | null;
        author: { id: number; name: string } | null;
        created_at: string;
    };
}>();
</script>

<template>
    <ClientHeader
        :context="context"
        active="documents"
        :title="`${version.title} — version ${version.version}`"
        :breadcrumbs="[
            { label: 'Documentation', href: route('clients.documents.index', context.id) },
            { label: document.title, href: route('clients.documents.show', [context.id, document.id]) },
            { label: `Version ${version.version}` },
        ]"
    >
        <template #actions>
            <Link :href="route('clients.documents.show', [context.id, document.id])" class="btn-secondary">Version actuelle (v{{ document.current_version }})</Link>
        </template>
    </ClientHeader>

    <p class="mb-3 rounded-md bg-slate-100 px-4 py-2 text-sm text-slate-700" role="note">
        Version archivée du {{ formatDateTime(version.created_at) }}<template v-if="version.author"> par {{ version.author.name }}</template>
        <template v-if="version.change_summary"> — {{ version.change_summary }}</template>
    </p>
    <article class="card p-6">
        <div v-if="version.content_html" class="doc-content" v-html="version.content_html" />
        <p v-else class="text-sm text-slate-500">Version vide.</p>
    </article>
</template>
