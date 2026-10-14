<script setup lang="ts" generic="T extends string | number">
const model = defineModel<T | null | '' | undefined>();

defineProps<{
    id: string;
    options: Array<{ value: T; label: string }>;
    invalid?: boolean;
    describedBy?: string;
    placeholder?: string;
    required?: boolean;
}>();
</script>

<template>
    <select
        :id="id"
        v-model="model"
        :name="id"
        class="form-input"
        :class="invalid ? 'border-red-500 focus:border-red-600 focus:ring-red-600' : ''"
        :aria-invalid="invalid ? 'true' : undefined"
        :aria-describedby="describedBy"
        :required="required"
    >
        <option v-if="placeholder !== undefined" :value="null">{{ placeholder }}</option>
        <option v-for="option in options" :key="String(option.value)" :value="option.value">{{ option.label }}</option>
    </select>
</template>
