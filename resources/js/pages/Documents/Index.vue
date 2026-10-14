<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BookOpen, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import CheckboxInput from '@/components/CheckboxInput.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import Pagination from '@/components/Pagination.vue';
import SelectInput from '@/components/SelectInput.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TagList from '@/components/TagList.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import type { ClientContext, Option, Paginated, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    context: ClientContext;
    documents: Paginated<{
        id: number;
        title: string;
        status: string;
        current_version: number;
        last_verified_at: string | null;
        needs_review: boolean;
        next_review_at: string | null;
        updated_at: string;
        category: { id: number; name: string } | null;
        owner: { id: number; name: string } | null;
        tags: TagRef[];
    }>;
    filters: { q: string; category: number | null; status: string; review: boolean; tag: number | null; sort: string; direction: string };
    categories: Option<number>[];
    statuses: Option[];
    tags: TagRef[];
}>();

const { filters, sortBy } = useFilters(() => route('clients.documents.index', props.context.id), { ...props.filters });
const statusOptions = computed<Option[]>(() => [{ value: 'current', label: 'Hors archivées' }, ...props.statuses, { value: 'all', label: 'Toutes' }]);
const tagOptions = computed(() => props.tags.map((tag) => ({ value: tag.id, label: tag.name })));
const canCreate = props.context.permissions.edit && props.context.status === 'active';
</script>

<template>
    <ClientHeader
        :context="context"
        active="documents"
        title="Base de connaissances"
        description="Procédures, guides de dépannage, architecture, maintenance, sauvegarde et restauration."
        :breadcrumbs="[{ label: 'Documentation' }]"
    >
        <template #actions>
            <Link v-if="canCreate" :href="route('clients.documents.create', context.id)" class="btn-primary"><Plus class="h-4 w-4" aria-hidden="true" /> Nouvelle fiche</Link>
        </template>
    </ClientHeader>

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-5">
            <div class="sm:col-span-2">
                <label for="doc-q" class="sr-only">Rechercher</label>
                <input id="doc-q" v-model="filters.q" type="search" class="form-input" placeholder="Titre ou contenu…" />
            </div>
            <div>
                <label for="doc-category" class="sr-only">Catégorie</label>
                <SelectInput id="doc-category" v-model="filters.category" :options="categories" placeholder="Toutes catégories" />
            </div>
            <div>
                <label for="doc-status" class="sr-only">Statut</label>
                <SelectInput id="doc-status" v-model="filters.status" :options="statusOptions" />
            </div>
            <div>
                <label for="doc-tag" class="sr-only">Étiquette</label>
                <SelectInput id="doc-tag" v-model="filters.tag" :options="tagOptions" placeholder="Toutes étiquettes" />
            </div>
            <CheckboxInput id="doc-review" v-model="filters.review" label="À vérifier uniquement" class="sm:col-span-5" />
        </div>

        <div v-if="documents.data.length" class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <SortableHeader column="title" label="Fiche" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th scope="col">Catégorie</th>
                        <th scope="col">Responsable</th>
                        <SortableHeader column="last_verified_at" label="Vérification" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortableHeader column="updated_at" label="Mise à jour" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="doc in documents.data" :key="doc.id">
                        <td>
                            <Link :href="route('clients.documents.show', [context.id, doc.id])" class="link">{{ doc.title }}</Link>
                            <p class="mt-0.5 flex flex-wrap items-center gap-1 text-xs text-slate-500">
                                v{{ doc.current_version }}
                                <StatusBadge v-if="doc.status !== 'published'" :status="doc.status" />
                                <TagList :tags="doc.tags" />
                            </p>
                        </td>
                        <td>{{ doc.category?.name ?? '—' }}</td>
                        <td>{{ doc.owner?.name ?? '—' }}</td>
                        <td class="text-xs">
                            <span>{{ doc.last_verified_at ? formatDate(doc.last_verified_at) : 'Jamais' }}</span>
                            <Badge v-if="doc.needs_review" color="amber" class="ml-1">À vérifier</Badge>
                        </td>
                        <td class="text-xs">{{ formatDate(doc.updated_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else :icon="BookOpen" title="Aucune fiche" description="Rédigez les procédures d'exploitation, de dépannage et de restauration du client.">
            <Link v-if="canCreate" :href="route('clients.documents.create', context.id)" class="btn-primary"><Plus class="h-4 w-4" aria-hidden="true" /> Rédiger une fiche</Link>
        </EmptyState>
        <Pagination :paginator="documents" />
    </div>
</template>
