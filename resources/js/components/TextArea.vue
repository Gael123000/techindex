<script setup lang="ts">
const model = defineModel<string | null>();

defineProps<{
    id: string;
    invalid?: boolean;
    describedBy?: string;
    rows?: number;
    placeholder?: string;
}>();
</script>

<template>
    <textarea
        :id="id"
        v-model="model"
        :name="id"
        :rows="rows ?? 3"
        class="form-input"
        :class="invalid ? 'border-red-500 focus:border-red-600 focus:ring-red-600' : ''"
        :aria-invalid="invalid ? 'true' : undefined"
        :aria-describedby="describedBy"
        :placeholder="placeholder"
    />
</template>
