<script setup lang="ts">
import {
    BadgeCheck,
    Building2,
    ClipboardList,
    Contact,
    Copy,
    FileText,
    FileUp,
    FolderCog,
    FolderOpen,
    Globe,
    History,
    KeyRound,
    LayoutDashboard,
    MapPin,
    Network,
    Plug,
    Plus,
    RefreshCw,
    Search,
    Server,
    Shapes,
    ShieldCheck,
    SlidersHorizontal,
    Tags,
    UserRound,
    Users,
    Wifi,
    X,
} from '@lucide/vue';
import type { Component } from 'vue';
import { nextTick, onMounted, ref, watch } from 'vue';
import type { ContextMenuItem } from '@/composables/useContextMenu';
import { openContextMenu } from '@/composables/useContextMenu';
import type { AppTab } from '@/composables/useTabs';
import {
    activateTab,
    closeOtherTabs,
    closeTab,
    closeTabsToRight,
    duplicateTab,
    MAX_TABS,
    moveTab,
    newTab,
    openTab,
    reloadTab,
    toAppUrl,
    useTabs,
} from '@/composables/useTabs';

/**
 * Barre d'onglets de l'en-tête : un clic active l'onglet, clic molette ou
 * croix pour le fermer, glisser-déposer pour le déplacer, clic droit pour
 * les autres actions. Un lien déposé sur la barre s'ouvre dans un nouvel onglet.
 */
const { tabs, activeId, canOpen } = useTabs();
const strip = ref<HTMLElement | null>(null);
const dragged = ref<string | null>(null);
const dropTarget = ref(false);

/* Icône de l'onglet, déduite de l'adresse. */
const sectionIcons: Array<[RegExp, Component]> = [
    [/^\/clients\/[^/]+\/sites/, MapPin],
    [/^\/clients\/[^/]+\/contacts/, Contact],
    [/^\/clients\/[^/]+\/equipment/, Server],
    [/^\/clients\/[^/]+\/network/, Network],
    [/^\/clients\/[^/]+\/internet-links/, Wifi],
    [/^\/clients\/[^/]+\/services/, Globe],
    [/^\/clients\/[^/]+\/licenses/, BadgeCheck],
    [/^\/clients\/[^/]+\/certificates/, ShieldCheck],
    [/^\/clients\/[^/]+\/credentials/, KeyRound],
    [/^\/clients\/[^/]+\/documents/, FileText],
    [/^\/clients\/[^/]+\/(fiches|records)/, ClipboardList],
    [/^\/clients\/[^/]+\/history/, History],
    [/^\/clients/, Building2],
    [/^\/equipment/, Server],
    [/^\/vault/, KeyRound],
    [/^\/files/, FolderOpen],
    [/^\/search/, Search],
    [/^\/imports/, FileUp],
    [/^\/audit/, History],
    [/^\/admin\/users/, Users],
    [/^\/admin\/equipment-types/, Shapes],
    [/^\/admin\/record-types/, SlidersHorizontal],
    [/^\/admin\/document-categories/, FolderCog],
    [/^\/admin\/isps/, Wifi],
    [/^\/admin\/tags/, Tags],
    [/^\/admin\/integrations/, Plug],
    [/^\/admin\/updates/, RefreshCw],
    [/^\/profile/, UserRound],
];

function iconFor(tab: AppTab): Component {
    const path = tab.url.split(/[?#]/)[0] ?? '';

    return sectionIcons.find(([pattern]) => pattern.test(path))?.[1] ?? LayoutDashboard;
}

function label(tab: AppTab): string {
    return tab.title || 'Chargement…';
}

/* ---------- Défilement de la barre ---------- */

function revealActive(): void {
    void nextTick(() => {
        strip.value?.querySelector<HTMLElement>(`[data-tab-id="${activeId.value}"]`)?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    });
}

watch(activeId, revealActive);
watch(() => tabs.value.length, revealActive);
onMounted(revealActive);

function onWheel(event: WheelEvent): void {
    if (strip.value && Math.abs(event.deltaY) > Math.abs(event.deltaX)) {
        strip.value.scrollLeft += event.deltaY;
    }
}

/* ---------- Souris et clavier ---------- */

/*
 * Fermeture à la souris : les onglets restants gardent leur largeur tant que
 * le pointeur reste sur la barre (comme dans un navigateur), pour que la
 * croix suivante arrive sous le pointeur. Ils s'ajustent quand il en sort.
 */
const frozenWidth = ref<number | null>(null);

function closeWithMouse(tab: AppTab): void {
    const width = strip.value?.querySelector<HTMLElement>('[data-tab-id]')?.getBoundingClientRect().width;

    if (width && tabs.value.length > 2) {
        frozenWidth.value = width;
    }

    closeTab(tab.id);
}

function onNewTab(): void {
    frozenWidth.value = null;
    newTab();
}

function onAuxClick(event: MouseEvent, tab: AppTab): void {
    if (event.button === 1) {
        event.preventDefault();
        closeWithMouse(tab);
    }
}

function focusTab(index: number): void {
    const target = tabs.value[(index + tabs.value.length) % tabs.value.length];

    if (target) {
        strip.value?.querySelector<HTMLElement>(`[data-tab-id="${target.id}"] [role="tab"]`)?.focus();
    }
}

function onKeydown(event: KeyboardEvent, tab: AppTab, index: number): void {
    switch (event.key) {
        case 'ArrowRight':
            event.preventDefault();
            focusTab(index + 1);
            break;
        case 'ArrowLeft':
            event.preventDefault();
            focusTab(index - 1);
            break;
        case 'Home':
            event.preventDefault();
            focusTab(0);
            break;
        case 'End':
            event.preventDefault();
            focusTab(tabs.value.length - 1);
            break;
        case 'Delete':
            if (tabs.value.length > 1) {
                event.preventDefault();
                closeTab(tab.id);
                void nextTick(() => focusTab(Math.min(index, tabs.value.length - 1)));
            }
            break;
    }
}

function showMenu(event: MouseEvent | KeyboardEvent, tab: AppTab, index: number): void {
    const single = tabs.value.length === 1;
    const items: ContextMenuItem[] = [
        { label: 'Nouvel onglet', icon: Plus, disabled: !canOpen.value, action: () => newTab() },
        { label: 'Actualiser', icon: RefreshCw, action: () => reloadTab(tab.id) },
        { label: 'Dupliquer l\'onglet', icon: Copy, disabled: !canOpen.value, action: () => duplicateTab(tab.id) },
        'separator',
        { label: 'Fermer l\'onglet', icon: X, hint: 'Molette', disabled: single, action: () => closeTab(tab.id) },
        { label: 'Fermer les autres onglets', disabled: single, action: () => closeOtherTabs(tab.id) },
        { label: 'Fermer les onglets à droite', disabled: index === tabs.value.length - 1, action: () => closeTabsToRight(tab.id) },
    ];

    openContextMenu(event, items, label(tab));
}

/* ---------- Glisser-déposer ---------- */

function onDragStart(event: DragEvent, tab: AppTab): void {
    dragged.value = tab.id;

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'copyMove';
        // Adresse complète : l'onglet peut aussi être déposé dans la barre d'adresse.
        event.dataTransfer.setData('text/uri-list', new URL(tab.url, window.location.origin).href);
        event.dataTransfer.setData('text/plain', new URL(tab.url, window.location.origin).href);
    }
}

function onDragOverTab(event: DragEvent, index: number): void {
    if (dragged.value === null) {
        return;
    }

    event.preventDefault();

    const from = tabs.value.findIndex((tab) => tab.id === dragged.value);
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const after = event.clientX > rect.left + rect.width / 2;
    // Avant ou après l'onglet survolé, selon la moitié pointée.
    const target = from < index ? (after ? index : index - 1) : (after ? index + 1 : index);

    if (from !== -1 && target !== from) {
        moveTab(dragged.value, target);
    }
}

function onDragEnd(): void {
    dragged.value = null;
    dropTarget.value = false;
}

/* Lien externe à la barre (menu, contenu de la page) déposé sur les onglets. */
function onDragOverStrip(event: DragEvent): void {
    if (dragged.value !== null || !event.dataTransfer?.types.includes('text/uri-list')) {
        return;
    }

    event.preventDefault();
    event.dataTransfer.dropEffect = canOpen.value ? 'copy' : 'none';
    dropTarget.value = true;
}

function onDrop(event: DragEvent): void {
    dropTarget.value = false;

    if (dragged.value !== null) {
        event.preventDefault();

        return;
    }

    const href = event.dataTransfer?.getData('text/uri-list').split(/\r?\n/).find((line) => line !== '' && !line.startsWith('#'));
    const url = href ? toAppUrl(href) : null;

    if (url !== null) {
        event.preventDefault();
        openTab(url, { position: 'end' });
    }
}
</script>

<template>
    <!--
        La barre occupe tout l'espace jusqu'à la recherche : les onglets gardent
        leur largeur fixe et ne rétrécissent (tous ensemble) que lorsqu'ils
        atteignent la recherche, puis la barre défile.
    -->
    <div class="flex min-w-0 flex-1 items-end self-stretch" @dragover="onDragOverStrip" @dragleave="dropTarget = false" @drop="onDrop">
        <div
            ref="strip"
            role="tablist"
            aria-label="Onglets ouverts"
            class="scrollbar-none -mb-px flex min-w-0 flex-1 items-end gap-0.5 overflow-x-auto pt-2"
            :class="dropTarget ? 'rounded-t-lg bg-turquoise-50' : ''"
            @wheel.passive="onWheel"
            @pointerleave="frozenWidth = null"
        >
            <div
                v-for="(tab, index) in tabs"
                :key="tab.id"
                :data-tab-id="tab.id"
                draggable="true"
                class="group relative flex min-w-30 shrink grow-0 basis-64 items-center border text-[13px] transition-colors"
                :style="frozenWidth !== null ? { flex: `0 0 ${frozenWidth}px` } : undefined"
                :class="[
                    tab.id === activeId
                        ? 'z-10 h-9 rounded-t-lg border-b-0 border-slate-200 bg-slate-50 text-navy-900'
                        : 'mb-0.5 h-8 rounded-lg border-transparent text-slate-500 hover:bg-slate-100 hover:text-slate-800',
                    dragged === tab.id ? 'opacity-60' : '',
                ]"
                @dragstart="onDragStart($event, tab)"
                @dragover="onDragOverTab($event, index)"
                @dragend="onDragEnd"
                @auxclick="onAuxClick($event, tab)"
                @mousedown.middle.prevent
                @contextmenu="showMenu($event, tab, index)"
            >
                <span v-if="tab.id === activeId" class="absolute inset-x-2 -top-px h-0.5 rounded-full bg-turquoise-500" aria-hidden="true" />
                <button
                    type="button"
                    role="tab"
                    :aria-selected="tab.id === activeId"
                    aria-controls="contenu"
                    :tabindex="tab.id === activeId ? 0 : -1"
                    class="flex h-full min-w-0 flex-1 items-center gap-2 rounded-t-lg pr-1 pl-3 text-left focus-visible:outline-offset-[-2px]"
                    :title="label(tab)"
                    @click="activateTab(tab.id)"
                    @keydown="onKeydown($event, tab, index)"
                >
                    <component :is="iconFor(tab)" class="h-3.5 w-3.5 shrink-0" :class="tab.id === activeId ? 'text-turquoise-600' : 'text-slate-400'" aria-hidden="true" />
                    <span class="truncate" :class="tab.id === activeId ? 'font-medium' : ''">{{ label(tab) }}</span>
                </button>
                <button
                    v-if="tabs.length > 1"
                    type="button"
                    class="mr-1.5 flex h-5 w-5 shrink-0 items-center justify-center rounded text-slate-400 hover:bg-slate-200 hover:text-slate-700 focus-visible:opacity-100"
                    :class="tab.id === activeId ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'"
                    :aria-label="`Fermer l'onglet ${label(tab)}`"
                    tabindex="-1"
                    @click.stop="closeWithMouse(tab)"
                >
                    <X class="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            </div>

            <!-- Juste après le dernier onglet, et toujours visible quand la barre défile -->
            <div class="sticky right-0 z-20 flex shrink-0 self-stretch bg-white pl-1">
                <button
                    type="button"
                    class="mb-1 flex h-7 w-7 items-center justify-center self-end rounded-md text-slate-500 hover:bg-slate-100 hover:text-navy-900 disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="!canOpen"
                    :title="canOpen ? 'Nouvel onglet' : `${MAX_TABS} onglets au maximum`"
                    @click="onNewTab"
                >
                    <Plus class="h-4 w-4" aria-hidden="true" />
                    <span class="sr-only">Nouvel onglet</span>
                </button>
            </div>
        </div>
    </div>
</template>
