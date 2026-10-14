<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { formatDateTime } from '@/lib/format';
import type { AuditEntry } from '@/types';

/**
 * Historique : qui, quoi, quand et, pour les champs non sensibles,
 * l'ancienne et la nouvelle valeur. Les secrets ne sont jamais affichés.
 */
withDefaults(defineProps<{ entries: AuditEntry[]; showSubject?: boolean; showClient?: boolean }>(), {
    showSubject: false,
    showClient: false,
});

function metadataText(metadata: Record<string, unknown> | null): string | null {
    if (!metadata) {
        return null;
    }

    const parts = Object.entries(metadata)
        .filter(([, value]) => value !== null && value !== '')
        .map(([key, value]) => `${key} : ${typeof value === 'boolean' ? (value ? 'oui' : 'non') : String(value)}`);

    return parts.length ? parts.join(' · ') : null;
}
</script>

<template>
    <ol v-if="entries.length" class="divide-y divide-slate-100">
        <li v-for="entry in entries" :key="entry.id" class="px-4 py-3 text-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <p class="text-slate-800">
                    <span class="font-medium">{{ entry.event_label }}</span>
                    <template v-if="showSubject && entry.subject_label">
                        <span class="text-slate-500"> – {{ entry.subject_type_label }} </span>
                        <Link v-if="entry.subject_url" :href="entry.subject_url" class="link">{{ entry.subject_label }}</Link>
                        <span v-else>{{ entry.subject_label }}</span>
                    </template>
                    <template v-if="showClient && entry.client">
                        <span class="text-slate-500"> · {{ entry.client.name }}</span>
                    </template>
                </p>
                <p class="text-xs text-slate-500">
                    <time :datetime="entry.created_at">{{ formatDateTime(entry.created_at) }}</time>
                    · {{ entry.user?.name ?? 'Système' }}<template v-if="entry.ip_address"> · {{ entry.ip_address }}</template>
                </p>
            </div>
            <table v-if="entry.changes.length" class="mt-2 w-full text-xs">
                <caption class="sr-only">Détail des changements</caption>
                <thead class="sr-only">
                    <tr>
                        <th scope="col">Champ</th>
                        <th scope="col">Avant</th>
                        <th scope="col">Après</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="change in entry.changes" :key="change.field" class="align-top">
                        <th scope="row" class="w-1/4 py-0.5 pr-3 text-left font-medium text-slate-600">{{ change.label }}</th>
                        <td class="w-[37%] py-0.5 pr-3 break-words text-slate-500 line-through decoration-slate-300">{{ change.old ?? '—' }}</td>
                        <td class="py-0.5 break-words text-slate-800">{{ change.new ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="metadataText(entry.metadata)" class="mt-1 text-xs text-slate-500">{{ metadataText(entry.metadata) }}</p>
        </li>
    </ol>
    <p v-else class="px-4 py-6 text-center text-sm text-slate-500">Aucun événement enregistré.</p>
</template>
