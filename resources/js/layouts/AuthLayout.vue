<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';

defineProps<{ title: string; description?: string }>();
</script>

<template>
    <Head :title="title" />
    <div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-b from-navy-950 to-navy-800 px-4 py-12">
        <AppLogo inverted show-tagline class="mb-8" />
        <main class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl sm:p-8">
            <h1 class="text-lg font-semibold text-navy-900">{{ title }}</h1>
            <p v-if="description" class="mt-1 text-sm text-slate-600">{{ description }}</p>
            <div class="mt-6">
                <slot />
            </div>
        </main>
        <p class="mt-6 text-xs text-navy-200">Accès réservé aux collaborateurs autorisés. Les connexions sont journalisées.</p>
    </div>
</template>
