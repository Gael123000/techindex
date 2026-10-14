<script setup lang="ts">
import { Inbox } from '@lucide/vue';
import type { Component } from 'vue';

defineProps<{ title: string; description?: string; icon?: Component }>();
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-12 text-center">
        <div class="rounded-full bg-turquoise-50 p-3 text-turquoise-700">
            <component :is="icon ?? Inbox" class="h-6 w-6" aria-hidden="true" />
        </div>
        <h3 class="mt-3 text-sm font-semibold text-navy-900">{{ title }}</h3>
        <p v-if="description" class="mt-1 max-w-md text-sm text-slate-500">{{ description }}</p>
        <div v-if="$slots.default" class="mt-4 flex flex-wrap justify-center gap-2">
            <slot />
        </div>
    </div>
</template>
