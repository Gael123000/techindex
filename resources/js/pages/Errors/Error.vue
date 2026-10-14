<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import AppLogo from '@/components/AppLogo.vue';

const props = defineProps<{ status: number; message?: string | null }>();
const page = usePage();

const titles: Record<number, string> = {
    403: 'Accès refusé',
    404: 'Page introuvable',
    429: 'Trop de requêtes',
    500: 'Erreur interne',
    503: 'Service indisponible',
};

const descriptions: Record<number, string> = {
    403: 'Vous n\'avez pas les droits nécessaires pour effectuer cette action.',
    404: 'Cette page n\'existe pas ou vous n\'y avez pas accès.',
    429: 'Trop de requêtes ont été envoyées. Patientez quelques instants.',
    500: 'Une erreur inattendue est survenue. Elle a été enregistrée dans les journaux du serveur.',
    503: 'TechIndex est en maintenance. Merci de réessayer dans quelques minutes.',
};

const title = computed(() => titles[props.status] ?? 'Erreur');
const description = computed(() => props.message ?? descriptions[props.status] ?? '');
</script>

<template>
    <Head :title="title" />
    <div class="flex min-h-screen flex-col items-center justify-center bg-slate-50 px-4 py-12 text-center">
        <AppLogo class="mb-8" />
        <p class="text-sm font-semibold text-turquoise-700">Erreur {{ status }}</p>
        <h1 class="mt-2 text-2xl font-semibold text-navy-900">{{ title }}</h1>
        <p class="mt-2 max-w-md text-sm text-slate-600">{{ description }}</p>
        <Link :href="page.props.auth ? route('dashboard') : route('login')" class="btn-primary mt-6">
            {{ page.props.auth ? 'Retour au tableau de bord' : 'Aller à la connexion' }}
        </Link>
    </div>
</template>
