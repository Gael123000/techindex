<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    ChevronDown,
    FileUp,
    FolderCog,
    FolderOpen,
    History,
    KeyRound,
    LayoutDashboard,
    PanelLeftClose,
    PanelLeftOpen,
    Plug,
    RefreshCw,
    Search,
    Server,
    Settings,
    Shapes,
    SlidersHorizontal,
    Tags,
    Users,
    Wifi,
    X,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, reactive, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import AppLogo from './AppLogo.vue';

/**
 * Navigation principale. Sur grand écran elle peut être réduite aux icônes
 * (libellés en infobulle) ; les catégories (Administration...) sont placées en
 * bas, séparées par un trait, et se déplient ou se replient. Ces choix sont
 * mémorisés dans le navigateur.
 */
interface NavItem {
    label: string;
    href: string;
    icon: Component;
    active: boolean;
    badge?: string;
}

interface NavSection {
    key: string;
    label: string;
    icon: Component;
    items: NavItem[];
}

const props = defineProps<{ open: boolean; collapsed: boolean }>();
const emit = defineEmits<{ close: []; 'toggle-collapsed': [] }>();

const SECTIONS_KEY = 'techindex.sidebar.sections';

const page = usePage();
const auth = computed(() => page.props.auth);

const mainNav = computed<NavItem[]>(() => {
    // Dépendance explicite à l'URL : route().current() n'est pas réactif.
    void page.url;

    const items: NavItem[] = [
        { label: 'Tableau de bord', href: route('dashboard'), icon: LayoutDashboard, active: route().current('dashboard') },
        { label: 'Clients', href: route('clients.index'), icon: Building2, active: route().current('clients.*') },
        { label: 'Équipements', href: route('equipment.index'), icon: Server, active: route().current('equipment.index') },
        { label: 'Mots de passe', href: route('vault.index'), icon: KeyRound, active: route().current('vault.*') },
        { label: 'Fichiers', href: route('files.index'), icon: FolderOpen, active: route().current('files.*') },
        { label: 'Recherche', href: route('search'), icon: Search, active: route().current('search') },
    ];

    if (auth.value?.can.import) {
        items.push({ label: 'Imports', href: route('imports.index'), icon: FileUp, active: route().current('imports.*') });
    }

    if (auth.value?.can.view_audit) {
        items.push({ label: 'Journal d\'audit', href: route('audit.index'), icon: History, active: route().current('audit.*') });
    }

    return items;
});

const sections = computed<NavSection[]>(() => {
    void page.url;

    const admin: NavItem[] = [];

    if (auth.value?.can.manage_users) {
        admin.push({ label: 'Utilisateurs', href: route('admin.users.index'), icon: Users, active: route().current('admin.users.*') });
    }

    if (auth.value?.can.manage_settings) {
        admin.push(
            { label: 'Types d\'équipements', href: route('admin.equipment-types.index'), icon: Shapes, active: route().current('admin.equipment-types.*') },
            { label: 'Fiches et champs', href: route('admin.record-types.index'), icon: SlidersHorizontal, active: route().current('admin.record-types.*') },
            { label: 'Catégories de documents', href: route('admin.document-categories.index'), icon: FolderCog, active: route().current('admin.document-categories.*') },
            { label: 'Fournisseurs d\'accès', href: route('admin.isps.index'), icon: Wifi, active: route().current('admin.isps.*') },
            { label: 'Étiquettes', href: route('admin.tags.index'), icon: Tags, active: route().current('admin.tags.*') },
            { label: 'Intégrations', href: route('admin.integrations.index'), icon: Plug, active: route().current('admin.integrations.*') },
        );
    }

    if (auth.value?.can.manage_updates) {
        admin.push({
            label: 'Mises à jour',
            href: route('admin.updates.index'),
            icon: RefreshCw,
            active: route().current('admin.updates.*'),
            badge: page.props.update_available ? 'Nouvelle' : undefined,
        });
    }

    return [{ key: 'admin', label: 'Administration', icon: Settings, items: admin }].filter((section) => section.items.length > 0);
});

/* ---------- Catégories dépliées ---------- */

function readSections(): Record<string, boolean> {
    try {
        const value: unknown = JSON.parse(window.localStorage.getItem(SECTIONS_KEY) ?? '{}');

        return typeof value === 'object' && value !== null ? (value as Record<string, boolean>) : {};
    } catch {
        return {};
    }
}

const expanded = reactive<Record<string, boolean>>(readSections());

watch(expanded, (value) => {
    try {
        window.localStorage.setItem(SECTIONS_KEY, JSON.stringify(value));
    } catch {
        // Préférence non mémorisée.
    }
});

function isExpanded(section: NavSection): boolean {
    return expanded[section.key] === true;
}

function toggleSection(section: NavSection): void {
    expanded[section.key] = !isExpanded(section);
}

// Arrivée sur une page d'une catégorie repliée : elle se déplie pour montrer où l'on est.
watch(
    () => sections.value.filter((section) => section.items.some((item) => item.active)).map((section) => section.key),
    (keys, previous) => keys.filter((key) => !previous?.includes(key)).forEach((key) => (expanded[key] = true)),
    { immediate: true },
);

/* ---------- Infobulles en mode réduit ---------- */

const tooltip = ref<{ text: string; top: number } | null>(null);

function showTooltip(event: Event, text: string): void {
    if (!props.collapsed || !window.matchMedia('(min-width: 1024px)').matches) {
        return;
    }

    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    tooltip.value = { text, top: rect.top + rect.height / 2 };
}

function hideTooltip(): void {
    tooltip.value = null;
}

watch(() => [props.collapsed, page.url], hideTooltip);

function itemClass(item: NavItem): string[] {
    return [
        item.active ? 'bg-navy-800 text-white' : 'text-navy-100 hover:bg-navy-800/60 hover:text-white',
        props.collapsed ? 'lg:justify-center lg:px-0' : '',
    ];
}
</script>

<template>
    <aside
        class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-navy-900 text-navy-100 transition-[width,translate] duration-200 lg:translate-x-0"
        :class="[open ? 'translate-x-0' : '-translate-x-full', collapsed ? 'lg:w-16' : '']"
        aria-label="Navigation principale"
    >
        <!-- En-tête : logo et bouton de réduction -->
        <div class="flex h-14 shrink-0 items-center justify-between gap-2 px-3" :class="collapsed ? 'lg:justify-center lg:px-0' : ''">
            <Link :href="route('dashboard')" class="min-w-0 rounded focus-visible:outline-turquoise-400" :class="collapsed ? 'lg:hidden' : ''">
                <AppLogo inverted />
            </Link>
            <button
                type="button"
                class="group/toggle hidden h-9 w-9 shrink-0 items-center justify-center rounded-md text-navy-200 hover:bg-navy-800 hover:text-white lg:flex"
                :aria-label="collapsed ? 'Développer le menu' : 'Réduire le menu'"
                :aria-expanded="!collapsed"
                @click="emit('toggle-collapsed')"
                @mouseenter="showTooltip($event, 'Développer le menu')"
                @mouseleave="hideTooltip"
            >
                <template v-if="collapsed">
                    <AppLogo compact inverted class="group-hover/toggle:hidden group-focus-visible/toggle:hidden" />
                    <PanelLeftOpen class="hidden h-5 w-5 group-hover/toggle:block group-focus-visible/toggle:block" aria-hidden="true" />
                </template>
                <PanelLeftClose v-else class="h-5 w-5" aria-hidden="true" />
            </button>
            <button type="button" class="rounded p-1 text-navy-200 hover:text-white lg:hidden" @click="emit('close')">
                <X class="h-5 w-5" aria-hidden="true" />
                <span class="sr-only">Fermer le menu</span>
            </button>
        </div>

        <nav class="flex flex-1 flex-col overflow-x-hidden overflow-y-auto px-3 pb-3" :class="collapsed ? 'lg:px-2' : ''">
            <ul class="space-y-0.5">
                <li v-for="item in mainNav" :key="item.href">
                    <Link
                        :href="item.href"
                        class="flex h-9 items-center gap-3 rounded-md px-3 text-sm font-medium whitespace-nowrap"
                        :class="itemClass(item)"
                        :aria-current="item.active ? 'page' : undefined"
                        @mouseenter="showTooltip($event, item.label)"
                        @mouseleave="hideTooltip"
                        @focus="showTooltip($event, item.label)"
                        @blur="hideTooltip"
                    >
                        <component :is="item.icon" class="h-4 w-4 shrink-0" :class="item.active ? 'text-turquoise-400' : ''" aria-hidden="true" />
                        <span class="truncate" :class="collapsed ? 'lg:sr-only' : ''">{{ item.label }}</span>
                    </Link>
                </li>
            </ul>

            <!-- Catégories, en bas de la navigation -->
            <div v-if="sections.length" class="mt-auto pt-4">
                <section v-for="section in sections" :key="section.key" class="border-t border-navy-700/70 pt-2" :aria-labelledby="`nav-section-${section.key}`">
                    <button
                        :id="`nav-section-${section.key}`"
                        type="button"
                        class="flex h-8 w-full items-center gap-2 rounded-md px-3 text-xs font-semibold tracking-wider whitespace-nowrap text-navy-300 uppercase hover:bg-navy-800/60 hover:text-white"
                        :class="collapsed ? 'lg:justify-center lg:gap-0.5 lg:px-0' : ''"
                        :aria-expanded="isExpanded(section)"
                        :aria-controls="`nav-section-items-${section.key}`"
                        @click="toggleSection(section)"
                        @mouseenter="showTooltip($event, section.label)"
                        @mouseleave="hideTooltip"
                    >
                        <component :is="section.icon" class="hidden h-4 w-4 shrink-0" :class="collapsed ? 'lg:block' : ''" aria-hidden="true" />
                        <span class="flex-1 truncate text-left" :class="collapsed ? 'lg:sr-only' : ''">{{ section.label }}</span>
                        <ChevronDown
                            class="shrink-0 transition-transform"
                            :class="[isExpanded(section) ? '' : '-rotate-90', collapsed ? 'h-3 w-3' : 'h-4 w-4']"
                            aria-hidden="true"
                        />
                    </button>
                    <ul v-show="isExpanded(section)" :id="`nav-section-items-${section.key}`" class="mt-1 space-y-0.5">
                        <li v-for="item in section.items" :key="item.href">
                            <Link
                                :href="item.href"
                                class="relative flex h-9 items-center gap-3 rounded-md px-3 text-sm whitespace-nowrap"
                                :class="itemClass(item)"
                                :aria-current="item.active ? 'page' : undefined"
                                @mouseenter="showTooltip($event, item.badge ? `${item.label} (${item.badge})` : item.label)"
                                @mouseleave="hideTooltip"
                                @focus="showTooltip($event, item.label)"
                                @blur="hideTooltip"
                            >
                                <component :is="item.icon" class="h-4 w-4 shrink-0" :class="item.active ? 'text-turquoise-400' : ''" aria-hidden="true" />
                                <span class="truncate" :class="collapsed ? 'lg:sr-only' : ''">{{ item.label }}</span>
                                <template v-if="item.badge">
                                    <span class="ml-auto rounded-full bg-turquoise-400 px-2 py-0.5 text-[10px] font-semibold text-navy-950" :class="collapsed ? 'lg:hidden' : ''">{{ item.badge }}</span>
                                    <span class="absolute top-1.5 right-2.5 hidden h-2 w-2 rounded-full bg-turquoise-400" :class="collapsed ? 'lg:block' : ''" aria-hidden="true" />
                                </template>
                            </Link>
                        </li>
                    </ul>
                </section>
            </div>
        </nav>

        <p class="border-t border-navy-800 px-4 py-3 text-xs text-navy-300" :class="collapsed ? 'lg:hidden' : ''">{{ page.props.app.tagline }}</p>

        <!-- Infobulle du mode réduit (hors de la zone défilante pour ne pas être coupée) -->
        <Teleport to="body">
            <div
                v-if="collapsed && tooltip"
                class="pointer-events-none fixed left-[4.5rem] z-[60] hidden -translate-y-1/2 rounded-md bg-navy-950 px-2.5 py-1.5 text-xs font-medium whitespace-nowrap text-white shadow-lg lg:block"
                :style="{ top: `${tooltip.top}px` }"
                aria-hidden="true"
            >
                {{ tooltip.text }}
            </div>
        </Teleport>
    </aside>
</template>
