<script setup lang="ts">
withDefaults(defineProps<{ inverted?: boolean; showTagline?: boolean }>(), {
    inverted: false,
    showTagline: false,
});
</script>

<template>
    <div class="flex items-center gap-2.5">
        <svg class="h-8 w-8 shrink-0" viewBox="0 0 32 32" aria-hidden="true">
            <rect width="32" height="32" rx="7" :fill="inverted ? '#ffffff' : '#10203f'" />
            <path d="M8 10h16M16 10v13" :stroke="inverted ? '#10203f' : '#ffffff'" stroke-width="3" stroke-linecap="round" />
            <circle cx="23.5" cy="22.5" r="3" fill="#14b3a4" />
        </svg>
        <div class="leading-tight">
            <span class="block text-base font-semibold tracking-tight" :class="inverted ? 'text-white' : 'text-navy-900'">
                Tech<span class="text-turquoise-500">Index</span>
            </span>
            <span v-if="showTagline" class="block text-xs" :class="inverted ? 'text-navy-200' : 'text-slate-500'">
                Le référentiel technique de vos clients.
            </span>
        </div>
    </div>
</template>
