<script setup lang="ts">
import { computed, ref, watch } from 'vue';

/**
 * Logo d'un opérateur (FAI) ; à défaut, ses initiales.
 */
const props = withDefaults(defineProps<{ url?: string | null; name?: string | null; size?: 'xs' | 'sm' | 'md' | 'lg' }>(), {
    url: null,
    name: null,
    size: 'md',
});

const failed = ref(false);
watch(
    () => props.url,
    () => (failed.value = false),
);

const sizes: Record<string, string> = {
    xs: 'h-5 w-5 text-[9px]',
    sm: 'h-8 w-8 text-[10px]',
    md: 'h-11 w-11 text-xs',
    lg: 'h-16 w-16 text-sm',
};

const initials = computed(() =>
    (props.name ?? '?')
        .replace(/[^\p{L}\p{N}\s&]/gu, ' ')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word.charAt(0).toUpperCase())
        .join('') || '?',
);
</script>

<template>
    <span class="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-white" :class="sizes[size]">
        <img v-if="url && !failed" :src="url" alt="" class="h-full w-full object-contain p-0.5" loading="lazy" @error="failed = true" />
        <span v-else class="font-semibold text-navy-700" aria-hidden="true">{{ initials }}</span>
    </span>
</template>
