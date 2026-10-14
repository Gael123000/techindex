<script setup lang="ts">
import { computed } from 'vue';
import Badge from './Badge.vue';

const props = defineProps<{ status: string; label?: string }>();

const colors: Record<string, string> = {
    active: 'green',
    in_service: 'green',
    published: 'green',
    completed: 'green',
    maintenance: 'amber',
    draft: 'amber',
    queued: 'amber',
    processing: 'blue',
    validated: 'blue',
    uploaded: 'slate',
    inactive: 'slate',
    out_of_service: 'orange',
    cancelled: 'orange',
    revoked: 'red',
    failed: 'red',
    archived: 'slate',
};

const labels: Record<string, string> = {
    active: 'Actif',
    archived: 'Archivé',
    in_service: 'En service',
    maintenance: 'Maintenance',
    out_of_service: 'Hors service',
    inactive: 'Inactif',
    published: 'Publié',
    draft: 'Brouillon',
    cancelled: 'Résilié',
    revoked: 'Révoqué',
};

const color = computed(() => colors[props.status] ?? 'slate');
</script>

<template>
    <Badge :color="color">{{ label ?? labels[status] ?? status }}</Badge>
</template>
