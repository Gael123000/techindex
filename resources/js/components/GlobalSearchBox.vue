<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Building2, ClipboardList, FileText, Globe, Loader2, MapPin, Search, Server, Wifi, X } from '@lucide/vue';
import type { Component } from 'vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import { HttpError, requestJson } from '@/lib/http';
import { highlight } from '@/lib/highlight';
import DynamicIcon from './DynamicIcon.vue';

/**
 * Barre de recherche globale, compacte dans l'en-tête (largeur fixe pour ne
 * pas déplacer les onglets, simple icône sur petit écran) : résultats affichés pendant la
 * frappe (modèle « combobox » accessible au clavier), Entrée pour la
 * recherche complète.
 */
interface SuggestionItem {
    id: number;
    title: string;
    subtitle: string | null;
    excerpt?: string | null;
    url: string;
    status: string;
    client: { id: number; name: string } | null;
    icon?: string | null;
    logo_url?: string | null;
}

interface SuggestionGroup {
    type: string;
    label: string;
    has_more: boolean;
    items: SuggestionItem[];
}

const page = usePage();
const input = ref<HTMLInputElement | null>(null);
const query = ref(typeof page.props.query === 'string' ? page.props.query : '');
const open = ref(false);
const loading = ref(false);
const error = ref<string | null>(null);
const groups = ref<SuggestionGroup[]>([]);
const searchedFor = ref('');
const activeIndex = ref(-1);
/* Petit écran : la recherche s'ouvre par-dessus l'en-tête. */
const mobileOpen = ref(false);

let timer: number | undefined;
let sequence = 0;

const typeIcons: Record<string, Component> = {
    clients: Building2,
    sites: MapPin,
    equipment: Server,
    services: Globe,
    documents: FileText,
    internet_links: Wifi,
    records: ClipboardList,
};

const term = computed(() => query.value.trim());
const items = computed(() => groups.value.flatMap((group) => group.items));
// Dernière option : « voir tous les résultats ».
const allResultsIndex = computed(() => items.value.length);
const total = computed(() => items.value.length);
const activeId = computed(() => (open.value && activeIndex.value >= 0 ? optionId(activeIndex.value) : undefined));

function optionId(index: number): string {
    return `global-search-option-${index}`;
}

function indexOf(item: SuggestionItem): number {
    return items.value.indexOf(item);
}

watch(query, () => {
    window.clearTimeout(timer);
    error.value = null;

    if (term.value.length < 2) {
        sequence++;
        groups.value = [];
        loading.value = false;
        activeIndex.value = -1;
        open.value = false;

        return;
    }

    open.value = true;
    loading.value = true;
    timer = window.setTimeout(() => void load(term.value), 180);
});

async function load(value: string): Promise<void> {
    const current = ++sequence;

    try {
        const data = await requestJson<{ query: string; groups: SuggestionGroup[] }>(route('search.suggest', { q: value }));

        if (current !== sequence) {
            return;
        }

        groups.value = data.groups;
        searchedFor.value = value;
        activeIndex.value = -1;
    } catch (exception) {
        if (current === sequence) {
            groups.value = [];
            error.value = exception instanceof HttpError && exception.status === 429
                ? 'Trop de recherches successives : patientez quelques secondes.'
                : 'La recherche est momentanément indisponible.';
        }
    } finally {
        if (current === sequence) {
            loading.value = false;
        }
    }
}

function close(): void {
    open.value = false;
    activeIndex.value = -1;
}

function visit(item: SuggestionItem): void {
    close();
    router.visit(item.url);
}

function searchAll(): void {
    if (term.value.length === 0) {
        return;
    }

    close();
    router.get(route('search'), { q: term.value });
}

function move(step: number): void {
    if (term.value.length < 2) {
        return;
    }

    open.value = true;
    const count = allResultsIndex.value + 1;
    activeIndex.value = activeIndex.value < 0 && step < 0 ? count - 1 : (activeIndex.value + step + count) % count;

    void nextTick(() => document.getElementById(optionId(activeIndex.value))?.scrollIntoView({ block: 'nearest' }));
}

function onKeydown(event: KeyboardEvent): void {
    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            move(1);
            break;
        case 'ArrowUp':
            event.preventDefault();
            move(-1);
            break;
        case 'Enter': {
            event.preventDefault();
            const item = open.value ? items.value[activeIndex.value] : undefined;

            if (item) {
                visit(item);
            } else {
                searchAll();
            }
            break;
        }
        case 'Escape':
            if (open.value) {
                event.preventDefault();
                close();
            } else if (query.value !== '') {
                query.value = '';
            } else if (mobileOpen.value) {
                mobileOpen.value = false;
            }
            break;
    }
}

function onFocus(): void {
    if (term.value.length >= 2 && (groups.value.length > 0 || error.value !== null)) {
        open.value = true;
    }
}

function onFocusOut(event: FocusEvent): void {
    const next = event.relatedTarget as Node | null;

    if (!next || !(event.currentTarget as HTMLElement).contains(next)) {
        close();
    }
}

// Ctrl+K (ou Cmd+K) et « / » placent le curseur dans la recherche.
function onShortcut(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    const typing = target !== null && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

    if ((event.key === 'k' && (event.ctrlKey || event.metaKey)) || (event.key === '/' && !typing)) {
        event.preventDefault();
        void openSearch();
    }
}

async function openSearch(): Promise<void> {
    // Champ masqué sur petit écran : il s'affiche d'abord.
    if (input.value?.offsetParent === null) {
        mobileOpen.value = true;
        await nextTick();
    }

    input.value?.focus();
    input.value?.select();
}

// Passage en grand écran : le champ redevient visible dans l'en-tête.
const wideScreen = window.matchMedia('(min-width: 768px)');

function onScreenChange(event: MediaQueryListEvent): void {
    if (event.matches) {
        mobileOpen.value = false;
    }
}

onMounted(() => {
    window.addEventListener('keydown', onShortcut);
    wideScreen.addEventListener('change', onScreenChange);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onShortcut);
    wideScreen.removeEventListener('change', onScreenChange);
    window.clearTimeout(timer);
});

watch(
    () => page.url,
    () => {
        close();
        mobileOpen.value = false;

        if (typeof page.props.query === 'string') {
            query.value = page.props.query;
        }
    },
);
</script>

<template>
    <button type="button" class="btn-ghost px-2 md:hidden" title="Rechercher (Ctrl K)" @click="openSearch">
        <Search class="h-5 w-5" aria-hidden="true" />
        <span class="sr-only">Rechercher</span>
    </button>

    <form
        role="search"
        class="md:relative md:block"
        :class="mobileOpen ? 'fixed inset-x-0 top-0 z-50 flex h-14 items-center gap-2 border-b border-slate-200 bg-white px-3 shadow-sm' : 'hidden'"
        @submit.prevent="searchAll"
        @focusout="onFocusOut"
    >
        <label for="global-search" class="sr-only">Rechercher dans TechIndex</label>
        <div class="relative min-w-0 flex-1">
            <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
            <input
                id="global-search"
                ref="input"
                v-model="query"
                type="search"
                class="form-input h-9 pr-9 pl-9 md:w-56 md:bg-slate-50 md:focus:bg-white xl:w-64"
                placeholder="Rechercher…"
                title="Client, équipement, IP, n° de série, FAI, document…"
                autocomplete="off"
                spellcheck="false"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="global-search-listbox"
                :aria-expanded="open"
                :aria-activedescendant="activeId"
                @keydown="onKeydown"
                @focus="onFocus"
            />
            <Loader2 v-if="loading" class="absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 animate-spin text-slate-400" aria-hidden="true" />
            <kbd v-else-if="query === ''" class="pointer-events-none absolute top-1/2 right-2.5 hidden -translate-y-1/2 rounded border border-slate-200 bg-white px-1.5 text-[10px] font-medium text-slate-400 md:block">Ctrl K</kbd>
        </div>
        <button v-if="mobileOpen" type="button" class="btn-ghost shrink-0 px-2 md:hidden" @click="mobileOpen = false">
            <X class="h-5 w-5" aria-hidden="true" />
            <span class="sr-only">Fermer la recherche</span>
        </button>

        <p class="sr-only" role="status" aria-live="polite">
            <template v-if="open && !loading && searchedFor === term">{{ total === 0 ? 'Aucun résultat' : `${total} résultat(s) proposés` }}</template>
        </p>

        <div
            v-show="open"
            class="absolute top-full z-40 mt-1 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl max-md:inset-x-2 md:right-0 md:w-[min(32rem,calc(100vw-2rem))]"
        >
            <div id="global-search-listbox" role="listbox" aria-label="Résultats de la recherche" class="max-h-[min(70vh,32rem)] overflow-y-auto py-1">
                <p v-if="error" class="px-4 py-3 text-sm text-red-700">{{ error }}</p>
                <p v-else-if="!loading && searchedFor === term && total === 0" class="px-4 py-3 text-sm text-slate-500">
                    Aucun résultat rapide pour « {{ term }} ».
                </p>
                <p v-else-if="total === 0" class="px-4 py-3 text-sm text-slate-500">Recherche…</p>

                <div v-for="group in groups" :key="group.type" role="group" :aria-labelledby="`global-search-group-${group.type}`">
                    <p :id="`global-search-group-${group.type}`" class="px-4 pt-2 pb-1 text-[11px] font-semibold tracking-wider text-slate-500 uppercase">
                        {{ group.label }}
                    </p>
                    <div
                        v-for="item in group.items"
                        :id="optionId(indexOf(item))"
                        :key="`${group.type}-${item.id}`"
                        role="option"
                        :aria-selected="activeIndex === indexOf(item)"
                        class="flex cursor-pointer items-start gap-3 px-4 py-2 text-sm"
                        :class="activeIndex === indexOf(item) ? 'bg-turquoise-50' : 'hover:bg-slate-50'"
                        @mousedown.prevent
                        @mouseenter="activeIndex = indexOf(item)"
                        @click="visit(item)"
                    >
                        <img v-if="item.logo_url" :src="item.logo_url" alt="" class="mt-0.5 h-5 w-5 shrink-0 rounded-sm object-contain" />
                        <DynamicIcon v-else-if="item.icon" :name="item.icon" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                        <component :is="typeIcons[group.type] ?? Search" v-else class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-navy-900">
                                <template v-for="(segment, index) in highlight(item.title, term)" :key="index">
                                    <mark v-if="segment.match" class="rounded-sm bg-turquoise-100 text-inherit">{{ segment.text }}</mark>
                                    <template v-else>{{ segment.text }}</template>
                                </template>
                                <span v-if="item.status === 'archived'" class="ml-1.5 text-xs font-normal text-slate-500">(archivé)</span>
                            </span>
                            <span class="block truncate text-xs text-slate-500">{{ [item.client?.name, item.subtitle].filter(Boolean).join(' · ') }}</span>
                        </span>
                    </div>
                    <p v-if="group.has_more" class="px-4 pb-1 text-xs text-slate-400">D'autres {{ group.label.toLowerCase() }} correspondent : voir tous les résultats.</p>
                </div>

                <div
                    :id="optionId(allResultsIndex)"
                    role="option"
                    :aria-selected="activeIndex === allResultsIndex"
                    class="mt-1 flex cursor-pointer items-center gap-2 border-t border-slate-100 px-4 py-2.5 text-sm font-medium text-navy-800"
                    :class="activeIndex === allResultsIndex ? 'bg-turquoise-50' : 'hover:bg-slate-50'"
                    @mousedown.prevent
                    @mouseenter="activeIndex = allResultsIndex"
                    @click="searchAll"
                >
                    <Search class="h-4 w-4 text-slate-400" aria-hidden="true" />
                    Voir tous les résultats pour « {{ term }} »
                </div>
            </div>
            <p class="hidden border-t border-slate-100 bg-slate-50 px-4 py-1.5 text-[11px] text-slate-500 sm:block" aria-hidden="true">
                ↑ ↓ pour parcourir · Entrée pour ouvrir · Échap pour fermer
            </p>
        </div>
    </form>
</template>
