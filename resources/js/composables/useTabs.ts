import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { computed, nextTick, reactive, watch } from 'vue';
import type { ComputedRef } from 'vue';
import { route } from 'ziggy-js';

/**
 * Onglets de navigation internes, comme dans un navigateur : chaque onglet
 * mémorise la page où l'utilisateur se trouve et la navigation reste dans
 * l'onglet actif. Une seule page est affichée à la fois : changer d'onglet
 * recharge sa page depuis le serveur (données à jour, droits revérifiés).
 *
 * Sauvegarde : sessionStorage pour la fenêtre en cours (actualisation) et
 * localStorage pour retrouver les onglets plus tard. Seuls des chemins
 * internes et des titres de page y figurent, jamais de données de formulaire
 * ni de secret.
 */
export interface AppTab {
    id: string;
    /** Chemin interne relatif (« /clients/3?page=2 »). */
    url: string;
    title: string;
    /** Position de défilement au moment où l'onglet a été quitté. */
    scroll: number;
}

interface TabsState {
    tabs: AppTab[];
    activeId: string;
}

type Source = 'memory' | 'session' | 'local' | 'none';

export const MAX_TABS = 30;

const STORAGE_PREFIX = 'techindex.tabs.';
const REMEMBER_KEY = 'techindex:tab';
const MAX_URL_LENGTH = 2048;
const MAX_TITLE_LENGTH = 160;

/* Pages hors de l'interface principale : jamais enregistrées dans un onglet. */
const UNTRACKED_COMPONENTS = /^(Auth|Errors)\//;
const LOGIN_COMPONENTS = ['Auth/Login', 'Auth/TwoFactorChallenge'];

const state = reactive<TabsState>({ tabs: [], activeId: '' });

let userId: number | null = null;
let connected = false;
/* Onglet dont la page est réellement affichée (diffère de l'onglet actif pendant un chargement). */
let shownId = '';
let lastTitle: { url: string; title: string } | null = null;
let shownComponent: string | null = null;
let previousComponent: string | null = null;
/* Dernier onglet ouvert en arrière-plan depuis l'onglet actif : les suivants se placent après lui. */
let backgroundChain: { from: string; lastId: string } | null = null;

function newId(): string {
    return window.crypto?.randomUUID?.() ?? `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}

function homeUrl(): string {
    return toAppUrl(route('dashboard')) ?? '/';
}

/**
 * Chemin interne d'une adresse, ou null si elle sort de l'application
 * (autre origine, protocole tel:, mailto:...).
 */
export function toAppUrl(href: string): string | null {
    let url: URL;

    try {
        url = new URL(href, window.location.origin);
    } catch {
        return null;
    }

    if (url.origin !== window.location.origin || !['http:', 'https:'].includes(url.protocol)) {
        return null;
    }

    const path = `${url.pathname}${url.search}${url.hash}`;

    return path.length <= MAX_URL_LENGTH ? path : null;
}

function samePage(a: string, b: string): boolean {
    return a.split('#')[0] === b.split('#')[0];
}

function cleanTitle(title: string): string {
    return title.replace(/\s+/g, ' ').trim().slice(0, MAX_TITLE_LENGTH);
}

function find(id: string): AppTab | undefined {
    return state.tabs.find((tab) => tab.id === id);
}

function indexOf(id: string): number {
    return state.tabs.findIndex((tab) => tab.id === id);
}

function activeTab(): AppTab | undefined {
    return find(state.activeId);
}

function createTab(url: string, title = ''): AppTab {
    return { id: newId(), url, title: cleanTitle(title), scroll: 0 };
}

/* ---------- Sauvegarde ---------- */

function storageKey(): string | null {
    return userId === null ? null : `${STORAGE_PREFIX}${userId}`;
}

function readStorage(storage: Storage): TabsState | null {
    const key = storageKey();

    if (key === null) {
        return null;
    }

    try {
        return sanitize(JSON.parse(storage.getItem(key) ?? 'null'));
    } catch {
        return null;
    }
}

/* Contenu relu du navigateur : seuls des chemins internes bien formés sont repris. */
function sanitize(raw: unknown): TabsState | null {
    if (typeof raw !== 'object' || raw === null || !Array.isArray((raw as TabsState).tabs)) {
        return null;
    }

    const tabs: AppTab[] = [];

    for (const item of (raw as TabsState).tabs.slice(0, MAX_TABS) as unknown[]) {
        if (typeof item !== 'object' || item === null) {
            continue;
        }

        const { id, url, title, scroll } = item as Partial<Record<keyof AppTab, unknown>>;
        const path = typeof url === 'string' && url.startsWith('/') && !url.startsWith('//') ? toAppUrl(url) : null;

        if (typeof id !== 'string' || id === '' || id.length > 64 || path === null || tabs.some((tab) => tab.id === id)) {
            continue;
        }

        tabs.push({
            id,
            url: path,
            title: typeof title === 'string' ? cleanTitle(title) : '',
            scroll: typeof scroll === 'number' && Number.isFinite(scroll) && scroll > 0 ? Math.round(scroll) : 0,
        });
    }

    if (tabs.length === 0) {
        return null;
    }

    const activeId = (raw as TabsState).activeId;

    return { tabs, activeId: tabs.some((tab) => tab.id === activeId) ? activeId : tabs[0]!.id };
}

function persist(): void {
    const key = storageKey();

    if (key === null || state.tabs.length === 0) {
        return;
    }

    const value = JSON.stringify({ tabs: state.tabs, activeId: state.activeId });

    for (const storage of [window.sessionStorage, window.localStorage]) {
        try {
            storage.setItem(key, value);
        } catch {
            // Stockage indisponible (navigation privée, quota) : onglets non sauvegardés.
        }
    }
}

watch(state, persist, { deep: true, flush: 'post' });

/* ---------- Titres ---------- */

function applyTitle(): void {
    const tab = activeTab();

    if (tab && lastTitle && samePage(lastTitle.url, tab.url)) {
        tab.title = lastTitle.title;
    }
}

/* Le gestionnaire d'en-tête transmet le titre échappé pour le HTML (&amp;, &#39;...). */
function decodeEntities(value: string): string {
    return value.includes('&') ? (new DOMParser().parseFromString(value, 'text/html').documentElement.textContent ?? value) : value;
}

/**
 * Titre de la page affichée (transmis par le gestionnaire d'en-tête
 * d'Inertia), repris comme libellé de l'onglet actif.
 */
export function notePageTitle(title: string, page: Page | undefined): void {
    const value = cleanTitle(decodeEntities(title));

    if (value === '' || !page?.url) {
        return;
    }

    lastTitle = { url: page.url, title: value };
    applyTitle();
}

/* ---------- Synchronisation avec la navigation ---------- */

/* L'entrée d'historique est marquée avec son onglet, pour les boutons Précédent et Suivant. */
function tagHistoryEntry(): void {
    if (state.activeId !== '' && router.restore(REMEMBER_KEY) !== state.activeId) {
        router.remember(state.activeId, REMEMBER_KEY);
    }
}

function onNavigate(page: Page, type: 'visit' | 'history' | 'initial'): void {
    if (type === 'history') {
        // Retour sur une page d'un autre onglet : cet onglet redevient actif.
        const owner = router.restore(REMEMBER_KEY);

        if (typeof owner === 'string' && find(owner)) {
            state.activeId = owner;
        }
    }

    const tab = activeTab();

    if (tab) {
        tab.url = page.url;
        shownId = tab.id;
        applyTitle();
        tagHistoryEntry();
    }
}

function track(page: Page, type: 'visit' | 'history' | 'initial'): void {
    if (connected && userId !== null && !UNTRACKED_COMPONENTS.test(page.component)) {
        onNavigate(page, type);
    }

    if (shownComponent !== page.component) {
        previousComponent = shownComponent;
        shownComponent = page.component;
    }
}

router.on('navigate', (event) => track(event.detail.page, event.detail.type));
// Visites « replace » (filtres, formulaires) : seul l'événement success est émis.
router.on('success', (event) => track(event.detail.page, 'visit'));

/* ---------- Défilement ---------- */

function saveScroll(): void {
    const tab = find(shownId);

    if (tab) {
        tab.scroll = Math.round(window.scrollY);
    }
}

function restoreScroll(top: number): void {
    if (top > 0) {
        void nextTick(() => window.requestAnimationFrame(() => window.scrollTo({ top })));
    }
}

/* ---------- Actions ---------- */

function visitTab(tab: AppTab, url = tab.url, replace = false): void {
    router.visit(url, {
        replace,
        onSuccess: () => restoreScroll(tab.scroll),
        onFinish: () => {
            // Page non chargée (erreur, session expirée) : l'onglet affiché redevient actif.
            if (state.activeId === tab.id && shownId !== tab.id && find(shownId)) {
                state.activeId = shownId;
            }
        },
    });
}

function switchTo(tab: AppTab): void {
    saveScroll();
    state.activeId = tab.id;
    visitTab(tab);
}

export function activateTab(id: string): void {
    const tab = find(id);

    if (tab && id !== state.activeId) {
        switchTo(tab);
    }
}

export interface OpenTabOptions {
    title?: string;
    /** Ouverture sans quitter l'onglet actif (clic molette, Ctrl+clic). */
    background?: boolean;
    /** Position : juste après l'onglet actif (par défaut) ou à la fin. */
    position?: 'next' | 'end';
}

export function openTab(href: string, options: OpenTabOptions = {}): boolean {
    const url = toAppUrl(href);

    if (url === null || state.tabs.length >= MAX_TABS) {
        return false;
    }

    const tab = createTab(url, options.title || 'Nouvel onglet');
    const chained = backgroundChain?.from === state.activeId ? indexOf(backgroundChain.lastId) : -1;
    const index = chained !== -1 ? chained : indexOf(state.activeId);
    state.tabs.splice(options.position === 'end' || index === -1 ? state.tabs.length : index + 1, 0, tab);

    if (options.background) {
        backgroundChain = { from: state.activeId, lastId: tab.id };
    } else {
        switchTo(tab);
    }

    return true;
}

export function newTab(): boolean {
    return openTab(homeUrl(), { title: 'Tableau de bord', position: 'end' });
}

export function duplicateTab(id: string): boolean {
    const index = indexOf(id);
    const source = state.tabs[index];

    if (!source || state.tabs.length >= MAX_TABS) {
        return false;
    }

    const copy = createTab(source.url, source.title);
    copy.scroll = source.id === shownId ? Math.round(window.scrollY) : source.scroll;
    state.tabs.splice(index + 1, 0, copy);
    switchTo(copy);

    return true;
}

export function closeTab(id: string): void {
    const index = indexOf(id);

    if (index === -1) {
        return;
    }

    // Dernier onglet : remplacé par un onglet neuf sur le tableau de bord.
    if (state.tabs.length === 1) {
        const fresh = createTab(homeUrl(), 'Tableau de bord');
        state.tabs.splice(0, 1, fresh);
        state.activeId = fresh.id;
        visitTab(fresh);

        return;
    }

    const wasActive = id === state.activeId;
    state.tabs.splice(index, 1);

    if (wasActive) {
        const next = state.tabs[index] ?? state.tabs[index - 1]!;
        state.activeId = next.id;
        visitTab(next);
    }
}

export function closeOtherTabs(id: string): void {
    const tab = find(id);

    if (!tab) {
        return;
    }

    state.tabs.splice(0, state.tabs.length, tab);

    if (id !== state.activeId) {
        state.activeId = id;
        visitTab(tab);
    }
}

export function closeTabsToRight(id: string): void {
    const index = indexOf(id);

    if (index === -1) {
        return;
    }

    const activeWasRemoved = indexOf(state.activeId) > index;
    state.tabs.splice(index + 1);

    if (activeWasRemoved) {
        state.activeId = id;
        visitTab(state.tabs[index]!);
    }
}

export function reloadTab(id: string): void {
    if (id === state.activeId) {
        router.reload();
    } else {
        activateTab(id);
    }
}

export function moveTab(id: string, toIndex: number): void {
    const from = indexOf(id);
    const target = Math.max(0, Math.min(toIndex, state.tabs.length - 1));

    if (from !== -1 && target !== from) {
        const [tab] = state.tabs.splice(from, 1);
        state.tabs.splice(target, 0, tab!);
    }
}

/* ---------- Raccordement à l'interface ---------- */

/**
 * Rattache les onglets de l'utilisateur à la page affichée quand l'interface
 * principale apparaît (chargement, retour depuis la connexion ou une page
 * d'erreur). Renvoie la fonction à appeler quand elle disparaît.
 */
export function connectTabs(user: number, page: Page): () => void {
    let source: Source = 'memory';

    if (userId !== user) {
        userId = user;
        const fromSession = readStorage(window.sessionStorage);
        const stored = fromSession ?? readStorage(window.localStorage);
        source = fromSession ? 'session' : stored ? 'local' : 'none';
        state.tabs.splice(0, state.tabs.length, ...(stored?.tabs ?? []));
        state.activeId = stored?.activeId ?? '';
    }

    connected = true;
    reconcile(page, source);

    return () => {
        connected = false;
    };
}

function reconcile(page: Page, source: Source): void {
    const url = page.url;
    const active = activeTab();
    // L'événement de navigation de cette page a pu être traité avant l'affichage de l'interface.
    const before = shownComponent === page.component ? previousComponent : shownComponent;

    if (!active) {
        const tab = createTab(url);
        state.tabs.splice(0, state.tabs.length, tab);
        state.activeId = tab.id;
    } else if (!samePage(active.url, url)) {
        const landedHome = samePage(url, homeUrl());
        const afterLogin = before !== null && LOGIN_COMPONENTS.includes(before);

        if (landedHome && (afterLogin || source === 'local')) {
            // Retour après connexion ou nouvelle fenêtre : la page de l'onglet actif est rouverte.
            const target = active.url;
            window.setTimeout(() => visitTab(active, target, true));
        } else if (source === 'local') {
            // Lien ouvert depuis l'extérieur : onglet existant, sinon nouvel onglet.
            const existing = state.tabs.find((tab) => samePage(tab.url, url));

            if (existing) {
                existing.url = url;
                state.activeId = existing.id;
            } else if (state.tabs.length < MAX_TABS) {
                const tab = createTab(url);
                state.tabs.splice(indexOf(active.id) + 1, 0, tab);
                state.activeId = tab.id;
            } else {
                active.url = url;
            }
        } else {
            // Même fenêtre (actualisation, page rechargée) : navigation dans l'onglet actif.
            active.url = url;
        }
    }

    shownId = state.activeId;
    applyTitle();
    tagHistoryEntry();
}

export function useTabs(): {
    tabs: ComputedRef<AppTab[]>;
    activeId: ComputedRef<string>;
    canOpen: ComputedRef<boolean>;
} {
    return {
        tabs: computed(() => state.tabs),
        activeId: computed(() => state.activeId),
        canOpen: computed(() => state.tabs.length < MAX_TABS),
    };
}
