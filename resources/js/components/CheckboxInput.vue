<script setup lang="ts">
const model = defineModel<boolean>();

defineProps<{ id: string; label: string; description?: string; disabled?: boolean }>();
</script>

<template>
    <div class="flex items-start gap-2.5">
        <input
            :id="id"
            v-model="model"
            type="checkbox"
            :name="id"
            :disabled="disabled"
            class="mt-0.5 h-4 w-4 rounded border-slate-300 focus:ring-turquoise-600"
            :aria-describedby="description ? `${id}-description` : undefined"
        />
        <div class="text-sm">
            <label :for="id" class="font-medium text-slate-700">{{ label }}</label>
            <p v-if="description" :id="`${id}-description`" class="text-xs text-slate-500">{{ description }}</p>
        </div>
    </div>
</template>
