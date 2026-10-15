<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Building2,
    ChevronDown,
    FileUp,
    FolderCog,
    FolderOpen,
    History,
    KeyRound,
    LayoutDashboard,
    LogOut,
    Menu,
    RefreshCw,
    Search,
    Server,
    Shapes,
    SlidersHorizontal,
    Tags,
    UserRound,
    Users,
    Wifi,
    X,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import AppLogo from '@/components/AppLogo.vue';
import FlashMessages from '@/components/FlashMessages.vue';
import GlobalSearchBox from '@/components/GlobalSearchBox.vue';
import PasswordConfirmModal from '@/components/PasswordConfirmModal.vue';

interface NavItem {
    label: string;
    href: string;
    icon: Component;
    active: boolean;
    badge?: string;
}

const page = usePage();
const auth = computed(() => page.props.auth);
const sidebarOpen = ref(false);
const userMenuOpen = ref(false);

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

const adminNav = computed<NavItem[]>(() => {
    void page.url;

    const items: NavItem[] = [];

    if (auth.value?.can.manage_users) {
        items.push({ label: 'Utilisateurs', href: route('admin.users.index'), icon: Users, active: route().current('admin.users.*') });
    }

    if (auth.value?.can.manage_settings) {
        items.push(
            { label: 'Types d\'équipements', href: route('admin.equipment-types.index'), icon: Shapes, active: route().current('admin.equipment-types.*') },
            { label: 'Fiches et champs', href: route('admin.record-types.index'), icon: SlidersHorizontal, active: route().current('admin.record-types.*') },
            { label: 'Catégories de documents', href: route('admin.document-categories.index'), icon: FolderCog, active: route().current('admin.document-categories.*') },
            { label: 'Fournisseurs d\'accès', href: route('admin.isps.index'), icon: Wifi, active: route().current('admin.isps.*') },
            { label: 'Étiquettes', href: route('admin.tags.index'), icon: Tags, active: route().current('admin.tags.*') },
        );
    }

    if (auth.value?.can.manage_updates) {
        items.push({
            label: 'Mises à jour',
            href: route('admin.updates.index'),
            icon: RefreshCw,
            active: route().current('admin.updates.*'),
            badge: page.props.update_available ? 'Nouvelle' : undefined,
        });
    }

    return items;
});

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
        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-navy-900 text-navy-100 transition-transform lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            aria-label="Navigation principale"
        >
            <div class="flex h-16 items-center justify-between px-4">
                <Link :href="route('dashboard')" class="rounded focus-visible:outline-turquoise-400">
                    <AppLogo inverted />
                </Link>
                <button type="button" class="rounded p-1 text-navy-200 hover:text-white lg:hidden" @click="sidebarOpen = false">
                    <X class="h-5 w-5" aria-hidden="true" />
                    <span class="sr-only">Fermer le menu</span>
                </button>
            </div>

            <nav class="flex-1 space-y-6 overflow-y-auto px-3 pb-6">
                <ul class="space-y-0.5">
                    <li v-for="item in mainNav" :key="item.href">
                        <Link
                            :href="item.href"
                            class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium"
                            :class="item.active ? 'bg-navy-800 text-white' : 'text-navy-100 hover:bg-navy-800/60 hover:text-white'"
                            :aria-current="item.active ? 'page' : undefined"
                        >
                            <component :is="item.icon" class="h-4 w-4 shrink-0" :class="item.active ? 'text-turquoise-400' : ''" aria-hidden="true" />
                            {{ item.label }}
                        </Link>
                    </li>
                </ul>

                <div v-if="page.props.nav?.clients.length">
                    <h2 class="px-3 text-xs font-semibold tracking-wider text-navy-300 uppercase">Mes clients</h2>
                    <ul class="mt-2 space-y-0.5">
                        <li v-for="client in page.props.nav.clients" :key="client.id">
                            <Link
                                :href="route('clients.show', client.id)"
                                class="block truncate rounded-md px-3 py-1.5 text-sm text-navy-100 hover:bg-navy-800/60 hover:text-white"
                                :title="`${client.name} (${client.reference})`"
                            >
                                {{ client.name }}
                            </Link>
                        </li>
                    </ul>
                </div>

                <div v-if="adminNav.length">
                    <h2 class="px-3 text-xs font-semibold tracking-wider text-navy-300 uppercase">Administration</h2>
                    <ul class="mt-2 space-y-0.5">
                        <li v-for="item in adminNav" :key="item.href">
                            <Link
                                :href="item.href"
                                class="flex items-center gap-3 rounded-md px-3 py-2 text-sm"
                                :class="item.active ? 'bg-navy-800 text-white' : 'text-navy-100 hover:bg-navy-800/60 hover:text-white'"
                                :aria-current="item.active ? 'page' : undefined"
                            >
                                <component :is="item.icon" class="h-4 w-4 shrink-0" :class="item.active ? 'text-turquoise-400' : ''" aria-hidden="true" />
                                {{ item.label }}
                                <span v-if="item.badge" class="ml-auto rounded-full bg-turquoise-400 px-2 py-0.5 text-[10px] font-semibold text-navy-950">{{ item.badge }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <p class="border-t border-navy-800 px-4 py-3 text-xs text-navy-300">{{ page.props.app.tagline }}</p>
        </aside>

        <div class="lg:pl-64">
            <!-- Barre supérieure : recherche globale et compte -->
            <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/95 px-4 backdrop-blur sm:px-6">
                <button type="button" class="btn-ghost lg:hidden" @click="sidebarOpen = true">
                    <Menu class="h-5 w-5" aria-hidden="true" />
                    <span class="sr-only">Ouvrir le menu</span>
                </button>

                <div class="flex-1">
                    <GlobalSearchBox />
                </div>

                <div class="relative">
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-slate-100"
                        :aria-expanded="userMenuOpen"
                        aria-haspopup="menu"
                        aria-controls="user-menu"
                        @click="userMenuOpen = !userMenuOpen"
                    >
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-turquoise-100 font-semibold text-turquoise-800" aria-hidden="true">
                            {{ auth?.user.name.charAt(0).toUpperCase() }}
                        </span>
                        <span class="hidden text-left sm:block">
                            <span class="block font-medium text-slate-800">{{ auth?.user.name }}</span>
                            <span class="block text-xs text-slate-500">{{ auth?.user.role_label }}</span>
                        </span>
                        <ChevronDown class="h-4 w-4 text-slate-400" aria-hidden="true" />
                    </button>
                    <div
                        v-if="userMenuOpen"
                        id="user-menu"
                        role="menu"
                        class="absolute right-0 mt-1 w-56 rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg"
                        @keydown.escape="userMenuOpen = false"
                    >
                        <Link :href="route('profile.show')" role="menuitem" class="flex items-center gap-2 px-3 py-2 text-slate-700 hover:bg-slate-50">
                            <UserRound class="h-4 w-4" aria-hidden="true" /> Mon profil et sécurité
                        </Link>
                        <button type="button" role="menuitem" class="flex w-full items-center gap-2 px-3 py-2 text-left text-slate-700 hover:bg-slate-50" @click="logout">
                            <LogOut class="h-4 w-4" aria-hidden="true" /> Se déconnecter
                        </button>
                    </div>
                </div>
            </header>

            <main id="contenu" class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
                <slot />
            </main>
        </div>

        <FlashMessages />
        <PasswordConfirmModal />
    </div>
</template>
