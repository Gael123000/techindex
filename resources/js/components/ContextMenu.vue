<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { ContextMenuAction } from '@/composables/useContextMenu';
import { closeContextMenu, useContextMenu } from '@/composables/useContextMenu';

/**
 * Rendu du menu contextuel (une seule instance, dans la mise en page) :
 * placé sous le pointeur sans déborder de la fenêtre, navigable au clavier.
 */
const menu = useContextMenu();
const element = ref<HTMLElement | null>(null);
const position = ref({ left: 0, top: 0 });

function items(): HTMLButtonElement[] {
    return Array.from(element.value?.querySelectorAll<HTMLButtonElement>('[role="menuitem"]:not([disabled])') ?? []);
}

watch(
    () => [menu.open, menu.x, menu.y] as const,
    async ([open]) => {
        if (!open) {
            return;
        }

        position.value = { left: menu.x, top: menu.y };
        await nextTick();

        const rect = element.value?.getBoundingClientRect();

        if (rect) {
            const margin = 8;
            position.value = {
                left: Math.max(margin, Math.min(menu.x, window.innerWidth - rect.width - margin)),
                top: menu.y + rect.height + margin > window.innerHeight ? Math.max(margin, menu.y - rect.height) : menu.y,
            };
        }

        items()[0]?.focus();
    },
);

function run(item: ContextMenuAction): void {
    if (item.disabled) {
        return;
    }

    closeContextMenu();
    item.action();
}

function onKeydown(event: KeyboardEvent): void {
    const list = items();
    const index = list.indexOf(document.activeElement as HTMLButtonElement);

    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            list[(index + 1) % list.length]?.focus();
            break;
        case 'ArrowUp':
            event.preventDefault();
            list[(index - 1 + list.length) % list.length]?.focus();
            break;
        case 'Home':
            event.preventDefault();
            list[0]?.focus();
            break;
        case 'End':
            event.preventDefault();
            list[list.length - 1]?.focus();
            break;
        case 'Escape':
            event.preventDefault();
            closeContextMenu(true);
            break;
        case 'Tab':
            event.preventDefault();
            closeContextMenu(true);
            break;
    }
}

function onPointerDown(event: PointerEvent): void {
    if (menu.open && !element.value?.contains(event.target as Node)) {
        closeContextMenu();
    }
}

function close(): void {
    closeContextMenu();
}

let offNavigation: VoidFunction | undefined;

onMounted(() => {
    window.addEventListener('pointerdown', onPointerDown, true);
    window.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);
    window.addEventListener('blur', close);
    offNavigation = router.on('start', close);
});

onBeforeUnmount(() => {
    window.removeEventListener('pointerdown', onPointerDown, true);
    window.removeEventListener('scroll', close, true);
    window.removeEventListener('resize', close);
    window.removeEventListener('blur', close);
    offNavigation?.();
    closeContextMenu();
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="menu.open"
            ref="element"
            role="menu"
            :aria-label="menu.title ?? 'Menu contextuel'"
            class="fixed z-[80] min-w-56 max-w-80 rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-xl ring-1 ring-black/5"
            :style="{ left: `${position.left}px`, top: `${position.top}px` }"
            @keydown="onKeydown"
            @contextmenu.prevent
        >
            <p v-if="menu.title" class="truncate border-b border-slate-100 px-3 pt-1 pb-1.5 text-xs text-slate-500" :title="menu.title">{{ menu.title }}</p>
            <template v-for="(item, index) in menu.items" :key="index">
                <div v-if="item === 'separator'" role="separator" class="my-1 border-t border-slate-100" />
                <button
                    v-else
                    type="button"
                    role="menuitem"
                    tabindex="-1"
                    :disabled="item.disabled"
                    class="flex w-full items-center gap-2.5 px-3 py-1.5 text-left outline-none disabled:cursor-not-allowed disabled:opacity-50"
                    :class="item.danger ? 'text-red-700 hover:bg-red-50 focus:bg-red-50' : 'text-slate-700 hover:bg-slate-100 focus:bg-slate-100'"
                    @click="run(item)"
                >
                    <component :is="item.icon" v-if="item.icon" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                    <span v-else class="w-4 shrink-0" aria-hidden="true" />
                    <span class="flex-1 truncate">{{ item.label }}</span>
                    <span v-if="item.hint" class="text-xs text-slate-400">{{ item.hint }}</span>
                </button>
            </template>
        </div>
    </Teleport>
</template>
