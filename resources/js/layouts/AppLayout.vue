<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { AppWindow, ChevronDown, ExternalLink, Link2, Lock, LockKeyholeOpen, LogOut, Menu, PanelTopOpen, UserRound } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import AppSidebar from '@/components/AppSidebar.vue';
import AppTabs from '@/components/AppTabs.vue';
import ContextMenu from '@/components/ContextMenu.vue';
import FlashMessages from '@/components/FlashMessages.vue';
import GlobalSearchBox from '@/components/GlobalSearchBox.vue';
import PasswordConfirmModal from '@/components/PasswordConfirmModal.vue';
import VaultUnlockModal from '@/components/VaultUnlockModal.vue';
import { openContextMenu } from '@/composables/useContextMenu';
import { connectTabs, openTab, toAppUrl, useTabs } from '@/composables/useTabs';
import { lockVault, syncVaultSession, useVaultSession } from '@/composables/useVaultSession';

const page = usePage();
const auth = computed(() => page.props.auth);
const sidebarOpen = ref(false);
const userMenuOpen = ref(false);
const userMenu = ref<HTMLElement | null>(null);

/* ---------- Barre latérale réduite (grand écran), mémorisée ---------- */

const COLLAPSED_KEY = 'techindex.sidebar.collapsed';

function readCollapsed(): boolean {
    try {
        return window.localStorage.getItem(COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

const sidebarCollapsed = ref(readCollapsed());

function toggleCollapsed(): void {
    sidebarCollapsed.value = !sidebarCollapsed.value;

    try {
        window.localStorage.setItem(COLLAPSED_KEY, sidebarCollapsed.value ? '1' : '0');
    } catch {
        // Préférence non mémorisée.
    }
}

/* ---------- Onglets ---------- */

const { canOpen } = useTabs();
const disconnectTabs = auth.value ? connectTabs(auth.value.user.id, page) : undefined;

onBeforeUnmount(() => disconnectTabs?.());

/*
 * Liens vers des pages de l'application (menu, listes, fil d'Ariane...) :
 * clic droit pour le menu de TechIndex, clic molette ou Ctrl+clic pour un
 * onglet en arrière-plan. Téléchargements, liens externes et ancres gardent
 * le comportement du navigateur, de même que Maj+clic droit.
 */
const NON_PAGE_PATHS = /\/(attachments|exports?|templates|report)\/|\/logo$/;

function pageLink(target: EventTarget | null): { url: string; title: string } | null {
    const anchor = target instanceof Element ? target.closest('a[href]') : null;

    if (
        !(anchor instanceof HTMLAnchorElement)
        || anchor.hasAttribute('download')
        || (anchor.target !== '' && anchor.target !== '_self')
        || anchor.getAttribute('href')?.startsWith('#')
        || anchor.closest('[data-native-menu]') !== null
    ) {
        return null;
    }

    const url = toAppUrl(anchor.href);

    if (url === null || NON_PAGE_PATHS.test(url.split(/[?#]/)[0] ?? '')) {
        return null;
    }

    const title = (anchor.getAttribute('aria-label') || anchor.textContent || anchor.title || '').replace(/\s+/g, ' ').trim();

    return { url, title };
}

async function copyLink(url: string): Promise<void> {
    const absolute = new URL(url, window.location.origin).href;

    try {
        await navigator.clipboard.writeText(absolute);
    } catch {
        // Contexte non sécurisé (HTTP) : ancienne méthode de copie.
        const field = document.createElement('textarea');
        field.value = absolute;
        field.setAttribute('readonly', '');
        field.className = 'fixed -top-full opacity-0';
        document.body.append(field);
        field.select();
        document.execCommand('copy');
        field.remove();
    }
}

function onContextMenu(event: MouseEvent): void {
    const link = event.shiftKey ? null : pageLink(event.target);

    if (link === null) {
        return;
    }

    openContextMenu(
        event,
        [
            { label: 'Ouvrir dans un nouvel onglet', icon: PanelTopOpen, disabled: !canOpen.value, action: () => openTab(link.url, { title: link.title }) },
            { label: 'Ouvrir en arrière-plan', icon: AppWindow, hint: 'Ctrl+clic', disabled: !canOpen.value, action: () => openTab(link.url, { title: link.title, background: true }) },
            'separator',
            { label: 'Ouvrir dans une nouvelle fenêtre', icon: ExternalLink, action: () => window.open(link.url, '_blank', 'noopener') },
            { label: 'Copier le lien', icon: Link2, action: () => void copyLink(link.url) },
        ],
        link.title || link.url,
    );
}

function openInBackground(event: MouseEvent): void {
    const link = pageLink(event.target);

    if (link !== null && canOpen.value) {
        event.preventDefault();
        openTab(link.url, { title: link.title, background: true });
    }
}

function onClick(event: MouseEvent): void {
    if (event.button === 0 && (event.ctrlKey || event.metaKey) && !event.shiftKey && !event.altKey) {
        openInBackground(event);
    }
}

function onAuxClick(event: MouseEvent): void {
    if (event.button === 1) {
        openInBackground(event);
    }
}

function onPointerDown(event: PointerEvent): void {
    if (userMenuOpen.value && !userMenu.value?.contains(event.target as Node)) {
        userMenuOpen.value = false;
    }
}

onMounted(() => {
    document.addEventListener('contextmenu', onContextMenu);
    document.addEventListener('click', onClick, true);
    document.addEventListener('auxclick', onAuxClick, true);
    document.addEventListener('pointerdown', onPointerDown);
});

onBeforeUnmount(() => {
    document.removeEventListener('contextmenu', onContextMenu);
    document.removeEventListener('click', onClick, true);
    document.removeEventListener('auxclick', onAuxClick, true);
    document.removeEventListener('pointerdown', onPointerDown);
});

/* Mots de passe déverrouillés : temps restant, affiché hors du gestionnaire (qui a son propre compteur). */
const { remaining: vaultRemaining, remainingLabel: vaultRemainingLabel } = useVaultSession();
const showVaultTimer = computed(() => {
    void page.url;

    return vaultRemaining.value > 0 && !route().current('vault.*');
});

watch(
    () => page.props.vault,
    (vault) => syncVaultSession(vault?.expires_in ?? 0),
    { immediate: true },
);

function logout(): void {
    router.post(route('logout'));
}

watch(
    () => page.url,
    () => {
        sidebarOpen.value = false;
        userMenuOpen.value = false;
    },
);
</script>

<template>
    <div class="min-h-full">
        <a href="#contenu" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[70] focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:shadow">
            Aller au contenu
        </a>

        <!-- Navigation latérale -->
        <div v-if="sidebarOpen" class="fixed inset-0 z-40 bg-navy-950/60 lg:hidden" aria-hidden="true" @click="sidebarOpen = false" />
        <AppSidebar :open="sidebarOpen" :collapsed="sidebarCollapsed" @close="sidebarOpen = false" @toggle-collapsed="toggleCollapsed" />

        <div class="transition-[padding] duration-200" :class="sidebarCollapsed ? 'lg:pl-16' : 'lg:pl-64'">
            <!-- Barre supérieure : onglets, recherche globale et compte -->
            <header class="sticky top-0 z-30 flex h-14 items-stretch gap-1 border-b border-slate-200 bg-white pr-2 pl-2 sm:gap-2 sm:pr-4">
                <button type="button" class="btn-ghost self-center px-2 lg:hidden" @click="sidebarOpen = true">
                    <Menu class="h-5 w-5" aria-hidden="true" />
                    <span class="sr-only">Ouvrir le menu</span>
                </button>

                <AppTabs />

                <div class="flex shrink-0 items-center gap-1 sm:gap-2">
                    <GlobalSearchBox />

                    <div v-if="showVaultTimer" class="flex items-center gap-1">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium tabular-nums"
                            :class="vaultRemaining <= 60 ? 'bg-amber-50 text-amber-800' : 'bg-turquoise-50 text-turquoise-800'"
                            role="timer"
                            :aria-label="`Mots de passe déverrouillés, reverrouillage dans ${vaultRemainingLabel}`"
                        >
                            <LockKeyholeOpen class="h-3.5 w-3.5" aria-hidden="true" />
                            <span class="hidden xl:inline">Mots de passe ouverts</span>
                            {{ vaultRemainingLabel }}
                        </span>
                        <button type="button" class="btn-ghost btn-sm" title="Verrouiller les mots de passe" @click="lockVault">
                            <Lock class="h-3.5 w-3.5" aria-hidden="true" />
                            <span class="sr-only">Verrouiller les mots de passe</span>
                        </button>
                    </div>

                    <div ref="userMenu" class="relative">
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-md px-1.5 py-1 text-sm hover:bg-slate-100"
                            :aria-expanded="userMenuOpen"
                            aria-haspopup="menu"
                            aria-controls="user-menu"
                            :title="auth ? `${auth.user.name} · ${auth.user.role_label}` : undefined"
                            @click="userMenuOpen = !userMenuOpen"
                        >
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-turquoise-100 font-semibold text-turquoise-800" aria-hidden="true">
                                {{ auth?.user.name.charAt(0).toUpperCase() }}
                            </span>
                            <span class="hidden max-w-36 text-left 2xl:block">
                                <span class="block truncate font-medium text-slate-800">{{ auth?.user.name }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ auth?.user.role_label }}</span>
                            </span>
                            <ChevronDown class="hidden h-4 w-4 text-slate-400 sm:block" aria-hidden="true" />
                            <span class="sr-only">Menu du compte</span>
                        </button>
                        <div
                            v-if="userMenuOpen"
                            id="user-menu"
                            role="menu"
                            class="absolute right-0 mt-1 w-56 rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg"
                            @keydown.escape="userMenuOpen = false"
                        >
                            <div class="border-b border-slate-100 px-3 pt-1 pb-2">
                                <p class="truncate font-medium text-slate-800">{{ auth?.user.name }}</p>
                                <p class="truncate text-xs text-slate-500">{{ auth?.user.role_label }}</p>
                            </div>
                            <Link :href="route('profile.show')" role="menuitem" class="flex items-center gap-2 px-3 py-2 text-slate-700 hover:bg-slate-50">
                                <UserRound class="h-4 w-4" aria-hidden="true" /> Mon profil et sécurité
                            </Link>
                            <button type="button" role="menuitem" class="flex w-full items-center gap-2 px-3 py-2 text-left text-slate-700 hover:bg-slate-50" @click="logout">
                                <LogOut class="h-4 w-4" aria-hidden="true" /> Se déconnecter
                            </button>
                        </div>
                    </div>
                </div>
            </header>

            <main id="contenu" class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
                <slot />
            </main>
        </div>

        <FlashMessages />
        <PasswordConfirmModal />
        <VaultUnlockModal />
        <ContextMenu />
    </div>
</template>
