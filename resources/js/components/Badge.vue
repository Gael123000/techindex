<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ color?: string }>(), { color: 'slate' });

// Couleurs avec un contraste texte / fond suffisant (AA).
const palette: Record<string, string> = {
    slate: 'bg-slate-100 text-slate-700 ring-slate-300',
    blue: 'bg-navy-50 text-navy-700 ring-navy-200',
    navy: 'bg-navy-50 text-navy-700 ring-navy-200',
    teal: 'bg-turquoise-50 text-turquoise-800 ring-turquoise-200',
    green: 'bg-green-50 text-green-800 ring-green-200',
    amber: 'bg-amber-50 text-amber-800 ring-amber-200',
    orange: 'bg-orange-50 text-orange-800 ring-orange-200',
    red: 'bg-red-50 text-red-800 ring-red-200',
    violet: 'bg-violet-50 text-violet-800 ring-violet-200',
};

const classes = computed(() => palette[props.color] ?? palette.slate);
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset" :class="classes">
        <slot />
    </span>
</template>
