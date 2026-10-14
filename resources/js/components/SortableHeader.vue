<script setup lang="ts">
import { ArrowDown, ArrowUp, ArrowUpDown } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{ column: string; label: string; sort?: string | null; direction?: string | null }>();
const emit = defineEmits<{ sort: [column: string] }>();

const active = computed(() => props.sort === props.column);
const ariaSort = computed(() => (active.value ? (props.direction === 'desc' ? 'descending' : 'ascending') : 'none'));
</script>

<template>
    <th scope="col" :aria-sort="ariaSort">
        <button type="button" class="inline-flex items-center gap-1 uppercase hover:text-navy-900" @click="emit('sort', column)">
            {{ label }}
            <ArrowUp v-if="active && direction !== 'desc'" class="h-3 w-3" aria-hidden="true" />
            <ArrowDown v-else-if="active" class="h-3 w-3" aria-hidden="true" />
            <ArrowUpDown v-else class="h-3 w-3 text-slate-400" aria-hidden="true" />
        </button>
    </th>
</template>
