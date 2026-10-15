import type { Component } from 'vue';
import { markRaw, reactive } from 'vue';

/**
 * Menu contextuel de l'application (clic droit), affiché à la place de celui
 * du navigateur. Chaque appelant fournit ses propres entrées : liens, onglets,
 * et plus tard d'autres éléments de l'interface.
 */
export interface ContextMenuAction {
    label: string;
    icon?: Component;
    /** Raccourci affiché à titre indicatif (« Molette »...). */
    hint?: string;
    disabled?: boolean;
    danger?: boolean;
    action: () => void;
}

export type ContextMenuItem = ContextMenuAction | 'separator';

interface ContextMenuState {
    open: boolean;
    x: number;
    y: number;
    /** Titre facultatif affiché en tête (adresse du lien, nom de l'onglet). */
    title: string | null;
    items: ContextMenuItem[];
    /** Élément qui avait le focus, rendu à la fermeture. */
    returnFocus: HTMLElement | null;
}

const state = reactive<ContextMenuState>({
    open: false,
    x: 0,
    y: 0,
    title: null,
    items: [],
    returnFocus: null,
});

export function openContextMenu(event: MouseEvent | KeyboardEvent, items: ContextMenuItem[], title: string | null = null): void {
    event.preventDefault();
    event.stopPropagation();

    let x: number;
    let y: number;

    if (event instanceof MouseEvent && (event.clientX !== 0 || event.clientY !== 0)) {
        x = event.clientX;
        y = event.clientY;
    } else {
        // Ouverture au clavier (touche Menu, Maj+F10) : sous l'élément ciblé.
        const rect = (event.target as HTMLElement).getBoundingClientRect();
        x = rect.left + 8;
        y = rect.bottom + 4;
    }

    state.items = items.map((item) => (item !== 'separator' && item.icon ? { ...item, icon: markRaw(item.icon) } : item));
    state.title = title;
    state.x = x;
    state.y = y;
    state.returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    state.open = true;
}

export function closeContextMenu(restoreFocus = false): void {
    if (!state.open) {
        return;
    }

    state.open = false;

    if (restoreFocus) {
        state.returnFocus?.focus();
    }

    state.returnFocus = null;
}

export function useContextMenu(): ContextMenuState {
    return state;
}
