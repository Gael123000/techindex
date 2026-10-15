<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, watch } from 'vue';
import { route } from 'ziggy-js';
import DynamicIcon from '@/components/DynamicIcon.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option, Paginated } from '@/types';

defineOptions({ layout: AppLayout });

interface ResultItem {
    id: number;
    title: string;
    subtitle: string | null;
    excerpt?: string | null;
    url: string;
    status: string;
    client: { id: number; name: string } | null;
    icon?: string | null;
    logo_url?: string | null;
}

interface Group {
    type: string;
    label: string;
    total: number;
    items: ResultItem[];
}

const props = defineProps<{
    query: string;
    filters: { type: string | null; client: number | null; site: number | null; status: string | null };
    results: { mode: 'groups'; groups: Group[] } | { mode: 'type'; type: string; label: string; results: Paginated<ResultItem> } | null;
    options: { types: Option[]; clients: Option<number>[]; sites: Option<number>[] };
}>();

const form = reactive({ q: props.query, ...props.filters });
const statusOptions: Option[] = [
    { value: 'active', label: 'Hors archivés' },
    { value: 'archived', label: 'Archivés' },
];

const total = computed(() => {
    if (!props.results) {
        return 0;
    }

    return props.results.mode === 'groups' ? props.results.groups.reduce((sum, group) => sum + group.total, 0) : props.results.results.total;
});

function submit(): void {
    const query: Record<string, string | number> = {};

    for (const [key, value] of Object.entries(form)) {
        if (value !== null && value !== '') {
            query[key] = value;
        }
    }

    router.get(route('search'), query, { preserveState: true, replace: true });
}

watch(
    () => [form.type, form.client, form.site, form.status],
    () => {
        if (form.client === null) {
            form.site = null;
        }

        if (form.q.trim().length >= 2) {
            submit();
        }
    },
);

// Recherche en direct : les résultats suivent la saisie après une courte pause.
let typingTimer: number | undefined;

watch(
    () => form.q,
    (value) => {
        window.clearTimeout(typingTimer);
        typingTimer = window.setTimeout(() => {
            const term = value.trim();

            if (term.length >= 2 || (term === '' && props.query !== '')) {
                submit();
            }
        }, 300);
    },
);

onBeforeUnmount(() => window.clearTimeout(typingTimer));

function showAll(type: string): void {
    form.type = type;
}
</script>

<template>
    <PageHeader title="Recherche" description="Clients, sites, équipements (nom, modèle, n° de série, IP, MAC), accès Internet (FAI), services, documentation et étiquettes." :breadcrumbs="[{ label: 'Recherche' }]" />

    <form role="search" class="card mb-5 grid grid-cols-1 gap-3 p-4 sm:grid-cols-6" @submit.prevent="submit">
        <div class="sm:col-span-6">
            <label for="search-q" class="sr-only">Termes recherchés</label>
            <div class="flex gap-2">
                <input id="search-q" v-model="form.q" type="search" class="form-input" placeholder="Ex. srv-ad01, 192.168.10., Fortigate, sauvegarde Veeam…" autofocus />
                <button type="submit" class="btn-primary"><Search class="h-4 w-4" aria-hidden="true" /> Rechercher</button>
            </div>
        </div>
        <div class="sm:col-span-2">
            <label for="search-type" class="mb-1 block text-xs font-medium text-slate-600">Type</label>
            <SelectInput id="search-type" v-model="form.type" :options="options.types" placeholder="Tous les types" />
        </div>
        <div class="sm:col-span-2">
            <label for="search-client" class="mb-1 block text-xs font-medium text-slate-600">Client</label>
            <SelectInput id="search-client" v-model="form.client" :options="options.clients" placeholder="Tous les clients" />
        </div>
        <div>
            <label for="search-site" class="mb-1 block text-xs font-medium text-slate-600">Site</label>
            <SelectInput id="search-site" v-model="form.site" :options="options.sites" placeholder="Tous" />
        </div>
        <div>
            <label for="search-status" class="mb-1 block text-xs font-medium text-slate-600">Statut</label>
            <SelectInput id="search-status" v-model="form.status" :options="statusOptions" placeholder="Tous" />
        </div>
    </form>

    <EmptyState v-if="results === null" :icon="Search" title="Saisissez au moins 2 caractères" description="La recherche ne porte que sur les clients qui vous sont attribués. Les secrets ne sont jamais recherchés." />

    <template v-else>
        <p class="mb-3 text-sm text-slate-600" role="status">{{ total.toLocaleString('fr-FR') }} résultat(s) pour « {{ query }} ».</p>

        <template v-if="results.mode === 'groups'">
            <EmptyState v-if="total === 0" :icon="Search" title="Aucun résultat" description="Vérifiez l'orthographe ou élargissez les filtres." />
            <section v-for="group in results.groups.filter((g) => g.total > 0)" :key="group.type" class="card mb-4" :aria-labelledby="`group-${group.type}`">
                <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h2 :id="`group-${group.type}`" class="text-sm font-semibold text-navy-900">{{ group.label }} <span class="text-slate-500">({{ group.total }})</span></h2>
                    <button v-if="group.total > group.items.length" type="button" class="link text-xs" @click="showAll(group.type)">Voir les {{ group.total }} résultats</button>
                </header>
                <ul class="divide-y divide-slate-100">
                    <li v-for="item in group.items" :key="item.id" class="px-4 py-2.5 text-sm">
                        <div class="flex items-start gap-2">
                            <img v-if="item.logo_url" :src="item.logo_url" alt="" class="mt-0.5 h-5 w-5 shrink-0 rounded-sm object-contain" />
                            <DynamicIcon v-else-if="item.icon" :name="item.icon" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                            <div class="min-w-0 flex-1">
                                <Link :href="item.url" class="link">{{ item.title }}</Link>
                                <StatusBadge v-if="item.status === 'archived'" status="archived" class="ml-2" />
                                <p class="truncate text-xs text-slate-500">{{ [item.client?.name, item.subtitle].filter(Boolean).join(' · ') }}</p>
                                <p v-if="item.excerpt" class="mt-0.5 text-xs text-slate-600">{{ item.excerpt }}</p>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>
        </template>

        <section v-else class="card" aria-labelledby="type-results">
            <header class="border-b border-slate-200 px-4 py-3">
                <h2 id="type-results" class="text-sm font-semibold text-navy-900">{{ results.label }}</h2>
            </header>
            <ul v-if="results.results.data.length" class="divide-y divide-slate-100">
                <li v-for="item in results.results.data" :key="item.id" class="px-4 py-2.5 text-sm">
                    <Link :href="item.url" class="link">{{ item.title }}</Link>
                    <StatusBadge v-if="item.status === 'archived'" status="archived" class="ml-2" />
                    <p class="text-xs text-slate-500">{{ [item.client?.name, item.subtitle].filter(Boolean).join(' · ') }}</p>
                    <p v-if="item.excerpt" class="mt-0.5 text-xs text-slate-600">{{ item.excerpt }}</p>
                </li>
            </ul>
            <EmptyState v-else :icon="Search" title="Aucun résultat" />
            <Pagination :paginator="results.results" />
        </section>
    </template>
</template>
