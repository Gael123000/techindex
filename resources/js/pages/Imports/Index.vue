<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { FileUp, Plus } from '@lucide/vue';
import { route } from 'ziggy-js';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import type { ImportSummary, Paginated } from '@/types';

defineOptions({ layout: AppLayout });

defineProps<{ imports: Paginated<ImportSummary> }>();
</script>

<template>
    <PageHeader title="Imports" description="Import de clients et d'équipements depuis un fichier CSV ou Excel." :breadcrumbs="[{ label: 'Imports' }]">
        <template #actions>
            <Link :href="route('imports.create')" class="btn-primary"><Plus class="h-4 w-4" aria-hidden="true" /> Nouvel import</Link>
        </template>
    </PageHeader>

    <div class="card">
        <div v-if="imports.data.length" class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th scope="col">Fichier</th>
                        <th scope="col">Type</th>
                        <th scope="col">Statut</th>
                        <th scope="col">Résultat</th>
                        <th scope="col">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="item in imports.data" :key="item.id">
                        <td>
                            <Link :href="route('imports.show', item.id)" class="link">{{ item.original_filename }}</Link>
                            <p class="text-xs text-slate-500">{{ item.user?.name }}</p>
                        </td>
                        <td>{{ item.type_label }}<span v-if="item.client" class="text-xs text-slate-500"> · {{ item.client.name }}</span></td>
                        <td><StatusBadge :status="item.status" :label="item.status_label" /></td>
                        <td class="text-xs text-slate-600">
                            <template v-if="item.status === 'completed'">{{ item.created_count }} créé(s), {{ item.updated_count }} mis à jour, {{ item.skipped_count }} ignoré(s)</template>
                            <template v-else>{{ item.total_rows }} ligne(s)</template>
                        </td>
                        <td class="text-xs">{{ formatDateTime(item.created_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else :icon="FileUp" title="Aucun import" description="Téléchargez un fichier modèle, complétez-le puis importez-le avec aperçu avant validation.">
            <Link :href="route('imports.create')" class="btn-primary">Commencer un import</Link>
        </EmptyState>
        <Pagination :paginator="imports" />
    </div>
</template>
