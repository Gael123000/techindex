<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Paginated } from '@/types';

defineProps<{ paginator: Paginated<unknown> }>();

function label(raw: string): string {
    if (raw.includes('Previous') || raw.includes('Précédent') || raw.includes('&laquo;')) {
        return '‹ Précédent';
    }

    if (raw.includes('Next') || raw.includes('Suivant') || raw.includes('&raquo;')) {
        return 'Suivant ›';
    }

    return raw;
}
</script>

<template>
    <div v-if="paginator.total > 0" class="flex flex-col items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-sm sm:flex-row">
        <p class="text-slate-600">
            {{ paginator.from }}–{{ paginator.to }} sur <span class="font-medium">{{ paginator.total.toLocaleString('fr-FR') }}</span>
        </p>
        <nav v-if="paginator.last_page > 1" aria-label="Pagination" class="flex flex-wrap gap-1">
            <template v-for="(link, index) in paginator.links" :key="index">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    class="rounded-md px-2.5 py-1 ring-1 ring-inset"
                    :class="link.active ? 'bg-navy-900 text-white ring-navy-900' : 'text-slate-700 ring-slate-300 hover:bg-slate-50'"
                    :aria-current="link.active ? 'page' : undefined"
                >
                    {{ label(link.label) }}
                </Link>
                <span v-else class="rounded-md px-2.5 py-1 text-slate-400 ring-1 ring-slate-200 ring-inset" aria-disabled="true">
                    {{ label(link.label) }}
                </span>
            </template>
        </nav>
    </div>
</template>
