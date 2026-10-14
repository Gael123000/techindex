<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, X } from '@lucide/vue';
import { ref, watch } from 'vue';

interface Toast {
    id: number;
    type: 'success' | 'error';
    message: string;
}

const page = usePage();
const toasts = ref<Toast[]>([]);
let counter = 0;

function push(type: Toast['type'], message: string | null): void {
    if (!message) {
        return;
    }

    const id = ++counter;
    toasts.value.push({ id, type, message });
    setTimeout(() => dismiss(id), type === 'error' ? 10_000 : 5_000);
}

function dismiss(id: number): void {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

watch(
    () => page.props.flash,
    (flash) => {
        push('success', flash?.success ?? null);
        push('error', flash?.error ?? null);
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-3 z-[60] flex flex-col items-center gap-2 px-4 sm:items-end sm:pr-6" aria-live="polite">
        <div
            v-for="toast in toasts"
            :key="toast.id"
            :role="toast.type === 'error' ? 'alert' : 'status'"
            class="pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-lg border bg-white px-4 py-3 text-sm shadow-lg"
            :class="toast.type === 'error' ? 'border-red-200' : 'border-turquoise-200'"
        >
            <CircleAlert v-if="toast.type === 'error'" class="mt-0.5 h-4 w-4 shrink-0 text-red-700" aria-hidden="true" />
            <CircleCheck v-else class="mt-0.5 h-4 w-4 shrink-0 text-turquoise-700" aria-hidden="true" />
            <p class="flex-1 text-slate-800">{{ toast.message }}</p>
            <button type="button" class="text-slate-400 hover:text-slate-700" @click="dismiss(toast.id)">
                <X class="h-4 w-4" aria-hidden="true" />
                <span class="sr-only">Fermer le message</span>
            </button>
        </div>
    </div>
</template>
