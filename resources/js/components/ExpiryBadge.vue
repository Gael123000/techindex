<script setup lang="ts">
import { computed } from 'vue';
import { daysUntil, formatDate } from '@/lib/format';
import Badge from './Badge.vue';

/**
 * Affiche une échéance avec un code couleur doublé d'un texte explicite
 * (l'information ne repose pas uniquement sur la couleur).
 */
const props = withDefaults(defineProps<{ date: string | null; warnDays?: number }>(), { warnDays: 60 });

const days = computed(() => daysUntil(props.date));

const state = computed(() => {
    if (days.value === null) {
        return null;
    }

    if (days.value < 0) {
        return { color: 'red', text: `Expiré depuis ${Math.abs(days.value)} j` };
    }

    if (days.value === 0) {
        return { color: 'red', text: 'Expire aujourd\'hui' };
    }

    if (days.value <= props.warnDays) {
        return { color: 'amber', text: `Dans ${days.value} j` };
    }

    return { color: 'green', text: formatDate(props.date) };
});
</script>

<template>
    <span v-if="state === null" class="text-slate-400">—</span>
    <span v-else class="inline-flex flex-wrap items-center gap-1.5">
        <span v-if="state.color !== 'green'" class="text-slate-700">{{ formatDate(date) }}</span>
        <Badge :color="state.color">{{ state.text }}</Badge>
    </span>
</template>
