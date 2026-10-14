<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <section class="card">
        <header class="border-b border-slate-200 px-5 py-3.5">
            <h2 class="text-sm font-semibold text-navy-900">{{ title }}</h2>
            <p v-if="description" class="mt-0.5 text-xs text-slate-500">{{ description }}</p>
        </header>
        <div class="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">
            <slot />
        </div>
    </section>
</template>
