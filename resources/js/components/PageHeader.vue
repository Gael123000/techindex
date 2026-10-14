<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { Breadcrumb } from '@/types';
import Breadcrumbs from './Breadcrumbs.vue';

defineProps<{ title: string; description?: string; breadcrumbs?: Breadcrumb[] }>();
</script>

<template>
    <Head :title="title" />
    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <Breadcrumbs v-if="breadcrumbs?.length" :items="breadcrumbs" class="mb-1.5" />
            <h1 class="truncate text-xl font-semibold text-navy-900">{{ title }}</h1>
            <p v-if="description" class="mt-0.5 text-sm text-slate-500">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
