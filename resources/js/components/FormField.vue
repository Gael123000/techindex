<script setup lang="ts">
import { computed } from 'vue';

/**
 * Champ de formulaire : libellé associé, aide et message d'erreur reliés au
 * champ par aria-describedby pour les lecteurs d'écran.
 */
const props = defineProps<{
    id: string;
    label: string;
    error?: string;
    hint?: string;
    required?: boolean;
}>();

const describedBy = computed(
    () => [props.error ? `${props.id}-error` : null, props.hint ? `${props.id}-hint` : null].filter(Boolean).join(' ') || undefined,
);
</script>

<template>
    <div>
        <label :for="id" class="mb-1 block text-sm font-medium text-slate-700">
            {{ label }}
            <span v-if="required" class="text-red-700" aria-hidden="true">*</span>
            <span v-if="required" class="sr-only">(obligatoire)</span>
        </label>
        <slot :describedBy="describedBy" :invalid="!!error" />
        <p v-if="hint" :id="`${id}-hint`" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="mt-1 text-xs font-medium text-red-700" role="alert">{{ error }}</p>
    </div>
</template>
