<script setup lang="ts">
import type { TagRef } from '@/types';
import Badge from './Badge.vue';

defineProps<{ tags: TagRef[] | undefined }>();
</script>

<template>
    <ul v-if="tags?.length" class="flex flex-wrap gap-1" aria-label="Étiquettes">
        <li v-for="tag in tags" :key="tag.id">
            <Badge :color="tag.color">{{ tag.name }}</Badge>
        </li>
    </ul>
</template>
