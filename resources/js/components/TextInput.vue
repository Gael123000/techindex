<script setup lang="ts">
import { ref } from 'vue';

const model = defineModel<string | number | null>();

defineProps<{
    id: string;
    type?: string;
    invalid?: boolean;
    describedBy?: string;
    placeholder?: string;
    autocomplete?: string;
    required?: boolean;
    disabled?: boolean;
    list?: string;
    maxlength?: number;
    inputmode?: 'text' | 'numeric' | 'decimal' | 'email' | 'tel' | 'url' | 'search';
}>();

const input = ref<HTMLInputElement | null>(null);

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <input
        :id="id"
        ref="input"
        v-model="model"
        :type="type ?? 'text'"
        :name="id"
        class="form-input"
        :class="invalid ? 'border-red-500 focus:border-red-600 focus:ring-red-600' : ''"
        :aria-invalid="invalid ? 'true' : undefined"
        :aria-describedby="describedBy"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        :required="required"
        :disabled="disabled"
        :list="list"
        :maxlength="maxlength"
        :inputmode="inputmode"
    />
</template>
