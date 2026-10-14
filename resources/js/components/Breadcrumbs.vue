<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import type { Breadcrumb } from '@/types';

defineProps<{ items: Breadcrumb[] }>();
</script>

<template>
    <nav aria-label="Fil d'Ariane">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-slate-500">
            <li v-for="(item, index) in items" :key="index" class="flex items-center gap-1">
                <ChevronRight v-if="index > 0" class="h-3 w-3 text-slate-400" aria-hidden="true" />
                <Link v-if="item.href && index < items.length - 1" :href="item.href" class="hover:text-turquoise-700 hover:underline">
                    {{ item.label }}
                </Link>
                <span v-else :aria-current="index === items.length - 1 ? 'page' : undefined" class="font-medium text-slate-700">
                    {{ item.label }}
                </span>
            </li>
        </ol>
    </nav>
</template>
