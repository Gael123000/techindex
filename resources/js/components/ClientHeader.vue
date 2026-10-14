<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Archive } from '@lucide/vue';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import type { Breadcrumb, ClientContext } from '@/types';
import Badge from './Badge.vue';
import Breadcrumbs from './Breadcrumbs.vue';

/**
 * Contexte client toujours visible : identité, statut, onglets des
 * ressources et fil d'Ariane.
 */
const props = defineProps<{
    context: ClientContext;
    active: string;
    title: string;
    breadcrumbs?: Breadcrumb[];
    description?: string;
}>();

const tabs = computed(() => [
    { key: 'overview', label: 'Vue d\'ensemble', href: route('clients.show', props.context.id), count: null },
    { key: 'sites', label: 'Sites', href: route('clients.sites.index', props.context.id), count: props.context.counts.sites },
    { key: 'contacts', label: 'Contacts', href: route('clients.contacts.index', props.context.id), count: props.context.counts.contacts },
    { key: 'equipment', label: 'Équipements', href: route('clients.equipment.index', props.context.id), count: props.context.counts.equipment },
    { key: 'network', label: 'Réseau', href: route('clients.network.index', props.context.id), count: null },
    { key: 'services', label: 'Services', href: route('clients.services.index', props.context.id), count: props.context.counts.services },
    { key: 'licenses', label: 'Licences', href: route('clients.licenses.index', props.context.id), count: props.context.counts.licenses },
    { key: 'certificates', label: 'Certificats', href: route('clients.certificates.index', props.context.id), count: props.context.counts.certificates },
    { key: 'credentials', label: 'Accès', href: route('clients.credentials.index', props.context.id), count: props.context.counts.credentials },
    { key: 'documents', label: 'Documentation', href: route('clients.documents.index', props.context.id), count: props.context.counts.documents },
    { key: 'history', label: 'Historique', href: route('clients.history', props.context.id), count: null },
]);

const trail = computed<Breadcrumb[]>(() => [
    { label: 'Clients', href: route('clients.index') },
    { label: props.context.name, href: route('clients.show', props.context.id) },
    ...(props.breadcrumbs ?? []),
]);

const initials = computed(() =>
    props.context.name
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word.charAt(0).toUpperCase())
        .join(''),
);
</script>

<template>
    <Head :title="`${title} – ${context.name}`" />

    <Breadcrumbs :items="trail" class="mb-3" />

    <section class="card mb-5 overflow-hidden" aria-label="Client courant">
        <div class="flex items-center gap-3 px-4 py-3">
            <img v-if="context.logo_url" :src="context.logo_url" alt="" class="h-10 w-10 shrink-0 rounded-md border border-slate-200 bg-white object-contain" />
            <span v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-navy-900 text-sm font-semibold text-white" aria-hidden="true">
                {{ initials }}
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-base font-semibold text-navy-900">
                    <Link :href="route('clients.show', context.id)" class="hover:underline">{{ context.name }}</Link>
                </p>
                <p class="text-xs text-slate-500">{{ context.reference }}</p>
            </div>
            <Badge v-if="context.status === 'archived'" color="amber"><Archive class="h-3 w-3" aria-hidden="true" /> Client archivé : lecture seule</Badge>
        </div>
        <nav aria-label="Rubriques du client" class="overflow-x-auto border-t border-slate-200">
            <ul class="flex min-w-max gap-1 px-2">
                <li v-for="tab in tabs" :key="tab.key">
                    <Link
                        :href="tab.href"
                        class="inline-flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm whitespace-nowrap"
                        :class="
                            tab.key === active
                                ? 'border-turquoise-600 font-semibold text-navy-900'
                                : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-navy-900'
                        "
                        :aria-current="tab.key === active ? 'page' : undefined"
                    >
                        {{ tab.label }}
                        <span v-if="tab.count !== null" class="rounded-full bg-slate-100 px-1.5 text-xs text-slate-600">{{ tab.count }}</span>
                    </Link>
                </li>
            </ul>
        </nav>
    </section>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="truncate text-xl font-semibold text-navy-900">{{ title }}</h1>
            <p v-if="description" class="mt-0.5 text-sm text-slate-500">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
