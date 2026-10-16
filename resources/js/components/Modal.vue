<script setup lang="ts">
import { X } from '@lucide/vue';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { closeModal, isTopModal, openModal } from '@/lib/modalStack';

/**
 * Fenêtre modale accessible : rôle dialog, titre associé, focus placé dans
 * la fenêtre à l'ouverture et restauré à la fermeture, Échap pour fermer,
 * tabulation maintenue dans la fenêtre. La dernière fenêtre ouverte passe au
 * premier plan (voir lib/modalStack).
 */
const props = withDefaults(defineProps<{ show: boolean; title: string; size?: 'sm' | 'md' | 'lg' | 'xl' }>(), { size: 'md' });
const emit = defineEmits<{ close: [] }>();

const dialog = ref<HTMLElement | null>(null);
const titleId = `modal-title-${Math.random().toString(36).slice(2, 9)}`;
let previouslyFocused: HTMLElement | null = null;
let modalId: number | null = null;
const layer = ref(50);

const widths = { sm: 'max-w-md', md: 'max-w-xl', lg: 'max-w-3xl', xl: 'max-w-5xl' };

function focusables(): HTMLElement[] {
    return Array.from(
        dialog.value?.querySelectorAll<HTMLElement>(
            'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ) ?? [],
    );
}

function onKeydown(event: KeyboardEvent): void {
    // Fenêtres superposées : seule celle du dessus réagit au clavier.
    if (!props.show || modalId === null || !isTopModal(modalId)) {
        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        emit('close');

        return;
    }

    if (event.key === 'Tab') {
        const items = focusables();

        if (items.length === 0) {
            return;
        }

        const first = items[0]!;
        const last = items[items.length - 1]!;

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
}

watch(
    () => props.show,
    async (show) => {
        if (show) {
            previouslyFocused = document.activeElement as HTMLElement | null;
            const opened = openModal();
            modalId = opened.id;
            layer.value = opened.layer;
            document.addEventListener('keydown', onKeydown);
            await nextTick();
            const autofocus = dialog.value?.querySelector<HTMLElement>('[autofocus]');
            (autofocus ?? focusables()[0] ?? dialog.value)?.focus();
        } else {
            document.removeEventListener('keydown', onKeydown);
            release();
            previouslyFocused?.focus();
        }
    },
    { immediate: true },
);

function release(): void {
    if (modalId !== null) {
        closeModal(modalId);
        modalId = null;
    }
}

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    release();
});
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 flex items-start justify-center overflow-y-auto p-4 sm:p-8" :style="{ zIndex: layer }">
            <div class="fixed inset-0 bg-navy-950/60" aria-hidden="true" @click="emit('close')" />
            <div
                ref="dialog"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                tabindex="-1"
                class="relative w-full rounded-lg bg-white shadow-xl"
                :class="widths[size]"
            >
                <header class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
                    <h2 :id="titleId" class="text-base font-semibold text-navy-900">{{ title }}</h2>
                    <button type="button" class="btn-ghost btn-sm" @click="emit('close')">
                        <X class="h-4 w-4" aria-hidden="true" />
                        <span class="sr-only">Fermer</span>
                    </button>
                </header>
                <div class="px-5 py-4">
                    <slot />
                </div>
                <footer v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>
