<script setup lang="ts">
import { computed } from 'vue';
import type { RecordPresentedSection } from '@/types';

/**
 * Valeurs renseignées des champs ajoutés, en une ligne compacte (tableaux).
 */
const props = defineProps<{ sections: RecordPresentedSection[] | null | undefined }>();

const items = computed(() =>
    (props.sections ?? []).flatMap((section) => section.fields).filter((field) => field.display !== null && field.display !== ''),
);
</script>

<template>
    <ul v-if="items.length" class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-slate-500">
        <li v-for="item in items" :key="item.key">
            <span class="text-slate-400">{{ item.label }} :</span> <span class="text-slate-700">{{ item.display }}</span>
        </li>
    </ul>
</template>
