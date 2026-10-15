<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, CircleAlert, CircleCheck, CircleDashed, FlaskConical } from '@lucide/vue';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';

defineOptions({ layout: AppLayout });

interface ProviderCard {
    key: string;
    label: string;
    vendor: string;
    category: string;
    category_label: string;
    description: string;
    initials: string;
    color: string;
    beta: boolean;
    account_label: string;
    configured: boolean;
    enabled: boolean;
    last_synced_at: string | null;
    last_status: string | null;
    last_status_label: string | null;
    accounts_count: number;
    linked_accounts_count: number;
}

const props = defineProps<{ providers: ProviderCard[] }>();

const categories = computed(() => {
    const groups = new Map<string, { label: string; providers: ProviderCard[] }>();

    for (const provider of props.providers) {
        const group = groups.get(provider.category) ?? { label: provider.category_label, providers: [] };
        group.providers.push(provider);
        groups.set(provider.category, group);
    }

    return [...groups.entries()].map(([key, group]) => ({ key, ...group }));
});

const activeCount = computed(() => props.providers.filter((provider) => provider.enabled).length);

function state(provider: ProviderCard): { label: string; classes: string; icon: typeof CircleCheck } {
    if (provider.enabled && provider.last_status === 'failed') {
        return { label: 'Erreur à la dernière synchronisation', classes: 'bg-red-50 text-red-800 ring-red-200', icon: CircleAlert };
    }

    if (provider.enabled) {
        return { label: provider.last_status === 'partial' ? 'Active, erreurs partielles' : 'Active', classes: 'bg-green-50 text-green-800 ring-green-200', icon: CircleCheck };
    }

    if (provider.configured) {
        return { label: 'Configurée, désactivée', classes: 'bg-amber-50 text-amber-800 ring-amber-200', icon: CircleDashed };
    }

    return { label: 'Non configurée', classes: 'bg-slate-100 text-slate-600 ring-slate-200', icon: CircleDashed };
}
</script>

<template>
    <PageHeader
        title="Intégrations"
        :description="`Licences et abonnements remontés automatiquement depuis vos distributeurs et consoles d'éditeurs. ${activeCount} intégration(s) active(s).`"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Intégrations' }]"
    />

    <div class="mb-5 rounded-lg border border-turquoise-200 bg-turquoise-50/60 px-4 py-3 text-sm text-turquoise-900">
        Configurez une intégration avec un compte d'API du fournisseur, rattachez ses clients (ou locataires) à vos clients TechIndex :
        leurs licences apparaissent alors dans l'onglet « Licences » de chaque client et restent à jour. Les identifiants sont chiffrés et ne sont jamais réaffichés.
    </div>

    <section v-for="category in categories" :key="category.key" class="mb-6" :aria-labelledby="`category-${category.key}`">
        <h2 :id="`category-${category.key}`" class="mb-2 text-xs font-semibold tracking-wider text-slate-500 uppercase">{{ category.label }}</h2>
        <ul class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            <li v-for="provider in category.providers" :key="provider.key">
                <Link
                    :href="route('admin.integrations.show', provider.key)"
                    class="card group flex h-full flex-col gap-3 p-4 transition-shadow hover:border-turquoise-300 hover:shadow-md"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-xs font-bold tracking-tight text-white"
                            :style="{ backgroundColor: provider.color }"
                            aria-hidden="true"
                        >
                            {{ provider.initials }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-2 font-semibold text-navy-900">
                                <span class="line-clamp-2">{{ provider.label }}</span>
                                <span
                                    v-if="provider.beta"
                                    class="inline-flex items-center gap-1 rounded-full bg-violet-50 px-1.5 py-0.5 text-[10px] font-medium text-violet-800 ring-1 ring-violet-200 ring-inset"
                                    title="Connecteur réalisé d'après la documentation publique, sans compte réel de test : vérifiez la première synchronisation."
                                >
                                    <FlaskConical class="h-3 w-3" aria-hidden="true" /> Bêta
                                </span>
                            </p>
                            <p class="text-xs text-slate-500">{{ provider.vendor }}</p>
                        </div>
                        <ChevronRight class="h-4 w-4 shrink-0 text-slate-300 group-hover:text-turquoise-600" aria-hidden="true" />
                    </div>
                    <p class="flex-1 text-sm text-slate-600">{{ provider.description }}</p>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ring-1 ring-inset" :class="state(provider).classes">
                            <component :is="state(provider).icon" class="h-3 w-3" aria-hidden="true" />
                            {{ state(provider).label }}
                        </span>
                        <span v-if="provider.accounts_count" class="text-slate-500">
                            {{ provider.linked_accounts_count }} / {{ provider.accounts_count }} {{ provider.account_label }}(s) rattaché(s)
                        </span>
                        <span v-if="provider.last_synced_at" class="text-slate-500">Synchro : {{ formatDateTime(provider.last_synced_at) }}</span>
                    </div>
                </Link>
            </li>
        </ul>
    </section>
</template>
