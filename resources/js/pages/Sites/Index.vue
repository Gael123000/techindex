<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MapPin, Plus } from '@lucide/vue';
import { route } from 'ziggy-js';
import ClientHeader from '@/components/ClientHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import Pagination from '@/components/Pagination.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, Option, Paginated } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    context: ClientContext;
    sites: Paginated<{
        id: number;
        name: string;
        code: string | null;
        address_line1: string | null;
        postal_code: string | null;
        city: string | null;
        status: string;
        equipment_count: number;
        contacts_count: number;
    }>;
    filters: { q: string; status: string };
}>();

const { filters } = useFilters(() => route('clients.sites.index', props.context.id), { ...props.filters });
const statuses: Option[] = [
    { value: 'active', label: 'Actifs' },
    { value: 'archived', label: 'Archivés' },
    { value: 'all', label: 'Tous' },
];
</script>

<template>
    <ClientHeader :context="context" active="sites" title="Sites et établissements" :breadcrumbs="[{ label: 'Sites' }]">
        <template #actions>
            <Link v-if="context.permissions.edit && context.status === 'active'" :href="route('clients.sites.create', context.id)" class="btn-primary">
                <Plus class="h-4 w-4" aria-hidden="true" /> Nouveau site
            </Link>
        </template>
    </ClientHeader>

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="sites-q" class="sr-only">Rechercher un site</label>
                <input id="sites-q" v-model="filters.q" type="search" class="form-input" placeholder="Nom, code, ville…" />
            </div>
            <div>
                <label for="sites-status" class="sr-only">Statut</label>
                <SelectInput id="sites-status" v-model="filters.status" :options="statuses" />
            </div>
        </div>

        <div v-if="sites.data.length" class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th scope="col">Site</th>
                        <th scope="col">Adresse</th>
                        <th scope="col">Équipements</th>
                        <th scope="col">Contacts</th>
                        <th scope="col">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="site in sites.data" :key="site.id">
                        <td>
                            <Link :href="route('clients.sites.show', [context.id, site.id])" class="link">{{ site.name }}</Link>
                            <p v-if="site.code" class="text-xs text-slate-500">{{ site.code }}</p>
                        </td>
                        <td class="text-slate-600">{{ [site.address_line1, [site.postal_code, site.city].filter(Boolean).join(' ')].filter(Boolean).join(', ') || '—' }}</td>
                        <td>{{ site.equipment_count }}</td>
                        <td>{{ site.contacts_count }}</td>
                        <td><StatusBadge :status="site.status" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else :icon="MapPin" title="Aucun site" description="Ajoutez les établissements du client pour y rattacher équipements, contacts et réseaux." />
        <Pagination :paginator="sites" />
    </div>
</template>
