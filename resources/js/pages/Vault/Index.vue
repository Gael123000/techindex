<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Building2,
    ChevronRight,
    Copy,
    ExternalLink,
    FolderInput,
    FolderOpen,
    FolderPlus,
    Inbox,
    KeyRound,
    Layers,
    Lock,
    Pencil,
    Plus,
    Search,
    Server,
    ShieldCheck,
    Timer,
    Trash2,
    Globe,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CredentialFormModal from '@/components/CredentialFormModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import FolderPickerModal from '@/components/FolderPickerModal.vue';
import FolderTree from '@/components/FolderTree.vue';
import NameModal from '@/components/NameModal.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import SecretValue from '@/components/SecretValue.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import { HttpError, requestJson } from '@/lib/http';
import type { Breadcrumb, ClientFolder, CredentialRow, FolderNode, Option, Paginated, Ref } from '@/types';

defineOptions({ layout: AppLayout });

interface TreeEquipment {
    id: number;
    name: string;
    hostname: string | null;
    site: string | null;
    count: number;
}

interface TreeService {
    id: number;
    name: string;
    category_label: string;
    count: number;
}

interface Selection {
    type: 'all' | 'folder' | 'equipment' | 'service' | 'general' | 'unfiled';
    id: number | null;
    label: string;
    path?: Array<{ id: number; name: string }>;
    parent_id?: number | null;
    url?: string;
}

interface SearchResult {
    id: number;
    label: string;
    username: string | null;
    access_type_label: string;
    client: Ref;
    equipment: Ref | null;
    service: Ref | null;
    folder: Ref | null;
    url: string;
}

const props = defineProps<{
    clients: ClientFolder[];
    client: { id: number; name: string; reference: string; status: 'active' | 'archived' } | null;
    search?: { q: string; results: SearchResult[] };
    tree?: {
        folders: FolderNode[];
        equipment: TreeEquipment[];
        services: TreeService[];
        counts: { all: number; general: number; unfiled: number };
    };
    selection?: Selection;
    subfolders?: Array<{ id: number; name: string; count: number }>;
    credentials?: Paginated<CredentialRow>;
    filters?: { q: string };
    highlight?: number | null;
    options?: { types: Option[]; equipment: Option<number>[]; services: Option<number>[]; folders: Option<number>[] };
    can?: { create: boolean; manage_folders: boolean; write_secrets: boolean; reveal: boolean };
    lock: { expires_in: number; idle_timeout: number };
}>();

/* -------------------------------------------------------------------------
 * Verrouillage automatique : après inactivité, et à l'échéance fixée par le
 * serveur. Une activité sur la page prolonge la session du coffre.
 * ---------------------------------------------------------------------- */
const now = ref(Date.now());
const expiresAt = ref(Date.now() + props.lock.expires_in * 1000);
let lastActivity = Date.now();
let lastRefresh = 0;
let timer: ReturnType<typeof setInterval> | undefined;
let locking = false;

const remaining = computed(() => Math.max(0, Math.ceil((expiresAt.value - now.value) / 1000)));
const remainingLabel = computed(() => `${Math.floor(remaining.value / 60)}:${String(remaining.value % 60).padStart(2, '0')}`);

watch(
    () => props.lock,
    (lock) => {
        expiresAt.value = Date.now() + lock.expires_in * 1000;
    },
);

function markActivity(): void {
    lastActivity = Date.now();
}

async function refreshLock(): Promise<void> {
    lastRefresh = Date.now();

    try {
        const status = await requestJson<{ expires_in: number }>(route('vault.status'));
        expiresAt.value = Date.now() + status.expires_in * 1000;
    } catch (exception) {
        if (exception instanceof HttpError && exception.status === 423) {
            router.reload();
        }
    }
}

function lockNow(): void {
    if (locking) {
        return;
    }

    locking = true;
    clearInterval(timer);
    router.post(route('vault.lock'));
}

function tick(): void {
    now.value = Date.now();

    if (remaining.value <= 0 || now.value - lastActivity >= props.lock.idle_timeout * 1000) {
        lockNow();

        return;
    }

    if (remaining.value < 60 && now.value - lastActivity < 60_000 && now.value - lastRefresh > 20_000) {
        void refreshLock();
    }
}

const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'mousemove', 'wheel', 'touchstart'] as const;

onMounted(() => {
    timer = setInterval(tick, 1000);
    ACTIVITY_EVENTS.forEach((event) => window.addEventListener(event, markActivity, { passive: true }));

    if (props.highlight) {
        flash(props.highlight);
    }
});

onBeforeUnmount(() => {
    clearInterval(timer);
    ACTIVITY_EVENTS.forEach((event) => window.removeEventListener(event, markActivity));
});

/* -------------------------------------------------------------------------
 * Navigation et recherche
 * ---------------------------------------------------------------------- */
const globalQuery = ref(props.search?.q ?? '');

function globalSearch(): void {
    router.get(route('vault.index'), globalQuery.value.trim() ? { q: globalQuery.value.trim() } : {});
}

function selectionParams(selection: Selection | undefined = props.selection): Record<string, string | number> {
    switch (selection?.type) {
        case 'folder':
            return { folder: selection.id! };
        case 'equipment':
            return { equipment: selection.id! };
        case 'service':
            return { service: selection.id! };
        case 'general':
        case 'unfiled':
            return { view: selection.type };
        default:
            return {};
    }
}

function clientUrl(params: Record<string, string | number> = {}): string {
    return route('vault.clients.show', { client: props.client!.id, ...params });
}

const { filters } = useFilters(() => (props.client ? clientUrl(selectionParams()) : route('vault.index')), { q: props.filters?.q ?? '' });

const breadcrumbs = computed<Breadcrumb[]>(() => {
    const items: Breadcrumb[] = [{ label: 'Mots de passe', href: route('vault.index') }];

    if (props.client) {
        items.push({ label: props.client.name, href: clientUrl() });
        props.selection?.path?.slice(0, -1).forEach((folder) => items.push({ label: folder.name, href: clientUrl({ folder: folder.id }) }));
    }

    return items;
});

const isActive = (type: Selection['type'], id: number | null = null): boolean => props.selection?.type === type && (id === null || props.selection.id === id);

/* Équipements regroupés par site lorsqu'il y en a plusieurs. */
const equipmentGroups = computed(() => {
    const groups = new Map<string, TreeEquipment[]>();

    for (const item of props.tree?.equipment ?? []) {
        const key = item.site ?? 'Sans site';
        groups.set(key, [...(groups.get(key) ?? []), item]);
    }

    return [...groups.entries()].map(([site, items]) => ({ site, items }));
});

const sections = reactive({
    equipment: props.selection?.type === 'equipment' || (props.tree?.equipment.length ?? 0) <= 15,
    services: props.selection?.type === 'service' || (props.tree?.services.length ?? 0) <= 15,
});

/* -------------------------------------------------------------------------
 * Accès : création, modification, suppression, copie, sélection
 * ---------------------------------------------------------------------- */
const showForm = ref(false);
const editing = ref<CredentialRow | null>(null);
const toDelete = ref<CredentialRow | null>(null);
const notice = ref<string | null>(null);
const highlighted = ref<number | null>(null);
const selected = ref<number[]>([]);

const formDefaults = computed(() => ({
    equipment_id: props.selection?.type === 'equipment' ? props.selection.id : null,
    service_id: props.selection?.type === 'service' ? props.selection.id : null,
    folder_id: props.selection?.type === 'folder' ? props.selection.id : null,
}));

watch(
    () => props.credentials,
    () => {
        selected.value = [];
    },
);

function createCredential(): void {
    editing.value = null;
    showForm.value = true;
}

function editCredential(credential: CredentialRow): void {
    editing.value = credential;
    showForm.value = true;
}

function destroyCredential(): void {
    if (!toDelete.value || !props.client) {
        return;
    }

    router.delete(route('clients.credentials.destroy', [props.client.id, toDelete.value.id]), {
        preserveScroll: true,
        onFinish: () => {
            toDelete.value = null;
        },
    });
}

async function copyText(value: string, label: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        notice.value = `${label} copié.`;
    } catch {
        notice.value = 'Copie impossible dans ce navigateur.';
    }

    setTimeout(() => {
        notice.value = null;
    }, 3000);
}

function flash(id: number): void {
    highlighted.value = id;
    requestAnimationFrame(() => document.getElementById(`credential-${id}`)?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
    setTimeout(() => {
        highlighted.value = null;
    }, 3000);
}

const pageIds = computed(() => props.credentials?.data.map((credential) => credential.id) ?? []);
const allSelected = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)));

function toggleAll(): void {
    selected.value = allSelected.value ? [] : [...pageIds.value];
}

/* -------------------------------------------------------------------------
 * Dossiers : création, renommage, déplacement, suppression
 * ---------------------------------------------------------------------- */
const nameModal = reactive({ show: false, mode: 'create' as 'create' | 'rename', error: undefined as string | undefined, processing: false });
const picker = reactive({ show: false, mode: 'credentials' as 'credentials' | 'folder', ids: [] as number[], processing: false });
const confirmFolderDelete = ref(false);

const currentFolder = computed(() => (props.selection?.type === 'folder' ? (props.tree?.folders.find((folder) => folder.id === props.selection!.id) ?? null) : null));

function descendantsOf(id: number): number[] {
    const result = [id];

    for (let i = 0; i < result.length; i++) {
        props.tree?.folders.filter((folder) => folder.parent_id === result[i]).forEach((folder) => result.push(folder.id));
    }

    return result;
}

function openFolderModal(mode: 'create' | 'rename'): void {
    Object.assign(nameModal, { show: true, mode, error: undefined, processing: false });
}

function submitFolderName(name: string): void {
    if (!props.client) {
        return;
    }

    nameModal.processing = true;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            nameModal.show = false;
        },
        onError: (errors: Record<string, string>) => {
            nameModal.error = errors.name ?? errors.parent_id;
        },
        onFinish: () => {
            nameModal.processing = false;
        },
    };

    if (nameModal.mode === 'create') {
        router.post(route('vault.clients.folders.store', props.client.id), { name, parent_id: currentFolder.value?.id ?? null }, options);
    } else if (currentFolder.value) {
        router.put(route('vault.clients.folders.update', [props.client.id, currentFolder.value.id]), { name, parent_id: currentFolder.value.parent_id }, options);
    }
}

function openPicker(mode: 'credentials' | 'folder', ids: number[] = []): void {
    Object.assign(picker, { show: true, mode, ids, processing: false });
}

function moveTo(folderId: number | null): void {
    if (!props.client) {
        return;
    }

    picker.processing = true;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            picker.show = false;
            selected.value = [];
        },
        onFinish: () => {
            picker.processing = false;
        },
    };

    if (picker.mode === 'credentials') {
        router.post(route('vault.clients.credentials.move', props.client.id), { credential_ids: picker.ids, folder_id: folderId }, options);
    } else if (currentFolder.value) {
        router.put(route('vault.clients.folders.update', [props.client.id, currentFolder.value.id]), { name: currentFolder.value.name, parent_id: folderId }, options);
    }
}

function destroyFolder(): void {
    if (!props.client || !currentFolder.value) {
        return;
    }

    router.delete(route('vault.clients.folders.destroy', [props.client.id, currentFolder.value.id]), {
        onFinish: () => {
            confirmFolderDelete.value = false;
        },
    });
}
</script>

<template>
    <PageHeader
        :title="client ? client.name : 'Gestionnaire de mots de passe'"
        :breadcrumbs="breadcrumbs"
        :description="client ? undefined : 'Les accès de vos clients, rangés automatiquement par client, équipement et service.'"
    >
        <template #actions>
            <span
                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                :class="remaining <= 60 ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-600'"
                role="timer"
                :aria-label="`Verrouillage automatique dans ${remainingLabel}`"
            >
                <Timer class="h-3.5 w-3.5" aria-hidden="true" /> {{ remainingLabel }}
            </span>
            <button type="button" class="btn-secondary" @click="lockNow"><Lock class="h-4 w-4" aria-hidden="true" /> Verrouiller</button>
        </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[17rem_minmax(0,1fr)]">
        <!-- Arborescence : clients, puis dossiers automatiques et libres du client ouvert -->
        <aside class="card self-start p-3 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto" aria-label="Arborescence des mots de passe">
            <form role="search" class="mb-3" @submit.prevent="globalSearch">
                <label for="vault-search" class="sr-only">Rechercher dans tous les clients</label>
                <div class="relative">
                    <Search class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                    <input id="vault-search" v-model="globalQuery" type="search" class="form-input pl-8 text-sm" placeholder="Tous les clients…" autocomplete="off" />
                </div>
            </form>

            <h2 class="px-2 text-xs font-semibold tracking-wider text-slate-500 uppercase">Clients</h2>
            <ul class="mt-1 space-y-0.5">
                <li v-for="item in clients" :key="item.id">
                    <Link
                        :href="route('vault.clients.show', item.id)"
                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                        :class="client?.id === item.id ? 'bg-navy-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
                        :aria-current="client?.id === item.id && selection?.type === 'all' ? 'page' : undefined"
                    >
                        <Building2 class="h-4 w-4 shrink-0" :class="client?.id === item.id ? 'text-turquoise-300' : 'text-slate-400'" aria-hidden="true" />
                        <span class="truncate">{{ item.name }}</span>
                        <span class="ml-auto text-xs" :class="client?.id === item.id ? 'text-navy-200' : 'text-slate-400'">{{ item.count }}</span>
                    </Link>

                    <div v-if="client?.id === item.id && tree" class="mt-1 mb-2 ml-3 space-y-3 border-l border-slate-200 pl-2">
                        <ul class="space-y-0.5 text-sm">
                            <li>
                                <Link :href="clientUrl()" class="flex items-center gap-1.5 rounded-md px-2 py-1" :class="isActive('all') ? 'bg-turquoise-50 font-medium text-navy-900' : 'text-slate-700 hover:bg-slate-100'">
                                    <Layers class="h-4 w-4 text-slate-400" aria-hidden="true" /> Tous
                                    <span class="ml-auto text-xs text-slate-400">{{ tree.counts.all }}</span>
                                </Link>
                            </li>
                            <li>
                                <Link
                                    :href="clientUrl({ view: 'general' })"
                                    class="flex items-center gap-1.5 rounded-md px-2 py-1"
                                    :class="isActive('general') ? 'bg-turquoise-50 font-medium text-navy-900' : 'text-slate-700 hover:bg-slate-100'"
                                >
                                    <Globe class="h-4 w-4 text-slate-400" aria-hidden="true" /> Accès généraux
                                    <span class="ml-auto text-xs text-slate-400">{{ tree.counts.general }}</span>
                                </Link>
                            </li>
                            <li>
                                <Link
                                    :href="clientUrl({ view: 'unfiled' })"
                                    class="flex items-center gap-1.5 rounded-md px-2 py-1"
                                    :class="isActive('unfiled') ? 'bg-turquoise-50 font-medium text-navy-900' : 'text-slate-700 hover:bg-slate-100'"
                                >
                                    <Inbox class="h-4 w-4 text-slate-400" aria-hidden="true" /> Non classés
                                    <span class="ml-auto text-xs text-slate-400">{{ tree.counts.unfiled }}</span>
                                </Link>
                            </li>
                        </ul>

                        <div>
                            <div class="flex items-center justify-between px-2">
                                <h3 class="text-xs font-semibold tracking-wider text-slate-500 uppercase">Dossiers</h3>
                                <button v-if="can?.manage_folders" type="button" class="rounded p-0.5 text-slate-500 hover:bg-slate-100 hover:text-navy-900" @click="openFolderModal('create')">
                                    <FolderPlus class="h-4 w-4" aria-hidden="true" />
                                    <span class="sr-only">Nouveau dossier{{ currentFolder ? ` dans ${currentFolder.name}` : '' }}</span>
                                </button>
                            </div>
                            <FolderTree
                                v-if="tree.folders.length"
                                class="mt-1"
                                :folders="tree.folders"
                                :selected-id="selection?.type === 'folder' ? selection.id : null"
                                :href-for="(id: number) => clientUrl({ folder: id })"
                            />
                            <p v-else class="px-2 py-1 text-xs text-slate-500">Aucun dossier.</p>
                        </div>

                        <div v-if="tree.equipment.length">
                            <button type="button" class="flex w-full items-center gap-1 px-2 text-left" :aria-expanded="sections.equipment" @click="sections.equipment = !sections.equipment">
                                <ChevronRight class="h-3.5 w-3.5 text-slate-400 transition-transform" :class="sections.equipment ? 'rotate-90' : ''" aria-hidden="true" />
                                <h3 class="text-xs font-semibold tracking-wider text-slate-500 uppercase">Équipements</h3>
                            </button>
                            <div v-if="sections.equipment" class="mt-1 space-y-1.5">
                                <div v-for="group in equipmentGroups" :key="group.site">
                                    <p v-if="equipmentGroups.length > 1" class="truncate px-2 text-xs text-slate-400">{{ group.site }}</p>
                                    <ul class="space-y-0.5">
                                        <li v-for="equipment in group.items" :key="equipment.id">
                                            <Link
                                                :href="clientUrl({ equipment: equipment.id })"
                                                class="flex items-center gap-1.5 rounded-md px-2 py-1 text-sm"
                                                :class="[
                                                    isActive('equipment', equipment.id) ? 'bg-turquoise-50 font-medium text-navy-900' : 'hover:bg-slate-100',
                                                    equipment.count ? 'text-slate-700' : 'text-slate-400',
                                                ]"
                                                :title="equipment.hostname ?? undefined"
                                            >
                                                <Server class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                                                <span class="truncate">{{ equipment.name }}</span>
                                                <span v-if="equipment.count" class="ml-auto text-xs text-slate-400">{{ equipment.count }}</span>
                                            </Link>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div v-if="tree.services.length">
                            <button type="button" class="flex w-full items-center gap-1 px-2 text-left" :aria-expanded="sections.services" @click="sections.services = !sections.services">
                                <ChevronRight class="h-3.5 w-3.5 text-slate-400 transition-transform" :class="sections.services ? 'rotate-90' : ''" aria-hidden="true" />
                                <h3 class="text-xs font-semibold tracking-wider text-slate-500 uppercase">Services</h3>
                            </button>
                            <ul v-if="sections.services" class="mt-1 space-y-0.5">
                                <li v-for="service in tree.services" :key="service.id">
                                    <Link
                                        :href="clientUrl({ service: service.id })"
                                        class="flex items-center gap-1.5 rounded-md px-2 py-1 text-sm"
                                        :class="[
                                            isActive('service', service.id) ? 'bg-turquoise-50 font-medium text-navy-900' : 'hover:bg-slate-100',
                                            service.count ? 'text-slate-700' : 'text-slate-400',
                                        ]"
                                        :title="service.category_label"
                                    >
                                        <Globe class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                                        <span class="truncate">{{ service.name }}</span>
                                        <span v-if="service.count" class="ml-auto text-xs text-slate-400">{{ service.count }}</span>
                                    </Link>
                                </li>
                            </ul>
                        </div>
                    </div>
                </li>
            </ul>
            <p v-if="!clients.length" class="px-2 py-2 text-sm text-slate-500">Aucun client ne vous est attribué.</p>
        </aside>

        <section class="min-w-0 space-y-4" aria-live="polite">
            <p v-if="notice" class="rounded-md bg-turquoise-50 px-3 py-2 text-sm text-turquoise-900" role="status">{{ notice }}</p>

            <!-- Vue globale : résultats de recherche ou dossiers des clients -->
            <template v-if="!client">
                <div v-if="search?.q" class="card">
                    <header class="border-b border-slate-200 px-4 py-3">
                        <h2 class="text-sm font-semibold text-navy-900">Résultats pour « {{ search.q }} »</h2>
                        <p class="text-xs text-slate-500">Recherche sur le libellé, l'utilisateur, l'hôte, l'URL et les notes ; jamais sur les secrets.</p>
                    </header>
                    <div v-if="search.results.length" class="overflow-x-auto">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th scope="col">Libellé</th>
                                    <th scope="col">Utilisateur</th>
                                    <th scope="col">Client</th>
                                    <th scope="col">Rattaché à</th>
                                    <th scope="col">Dossier</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="result in search.results" :key="result.id">
                                    <td>
                                        <Link :href="result.url" class="link font-medium">{{ result.label }}</Link>
                                        <p class="text-xs text-slate-500">{{ result.access_type_label }}</p>
                                    </td>
                                    <td class="font-mono text-xs">{{ result.username ?? '—' }}</td>
                                    <td>{{ result.client.name }}</td>
                                    <td class="text-xs">{{ result.equipment?.name ?? result.service?.name ?? 'Client' }}</td>
                                    <td class="text-xs">{{ result.folder?.name ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <EmptyState v-else :icon="Search" title="Aucun accès trouvé" />
                </div>

                <template v-else>
                    <div class="flex items-start gap-2.5 rounded-md border border-turquoise-200 bg-turquoise-50 px-4 py-3 text-sm text-turquoise-900" role="note">
                        <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        <p>
                            Coffre déverrouillé par clé d'accès. Les accès créés depuis une fiche équipement ou service apparaissent ici automatiquement, et
                            inversement : il s'agit des mêmes données.
                        </p>
                    </div>
                    <ul v-if="clients.length" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <li v-for="item in clients" :key="item.id">
                            <Link :href="route('vault.clients.show', item.id)" class="card flex items-center gap-3 p-4 hover:border-turquoise-300 hover:shadow-sm">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-navy-900 text-white">
                                    <KeyRound class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-navy-900">{{ item.name }}</span>
                                    <span class="block text-xs text-slate-500">
                                        {{ item.reference }} · {{ item.count }} accès
                                        <Badge v-if="item.archived" color="amber" class="ml-1">Archivé</Badge>
                                    </span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                    <EmptyState v-else :icon="KeyRound" title="Aucun client" description="Aucun client ne vous est attribué." />
                </template>
            </template>

            <!-- Vue d'un client -->
            <div v-else-if="selection && credentials && can && options" class="card">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <div class="min-w-0">
                        <h2 class="flex items-center gap-2 text-base font-semibold text-navy-900">
                            <FolderOpen v-if="selection.type === 'folder'" class="h-5 w-5 text-amber-500" aria-hidden="true" />
                            <Server v-else-if="selection.type === 'equipment'" class="h-5 w-5 text-slate-400" aria-hidden="true" />
                            <Globe v-else-if="selection.type === 'service'" class="h-5 w-5 text-slate-400" aria-hidden="true" />
                            <KeyRound v-else class="h-5 w-5 text-turquoise-700" aria-hidden="true" />
                            <span class="truncate">{{ selection.label }}</span>
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ credentials.total }} accès
                            <template v-if="selection.url">
                                · <Link :href="selection.url" class="link inline-flex items-center gap-0.5">Ouvrir la fiche <ExternalLink class="h-3 w-3" aria-hidden="true" /></Link>
                            </template>
                            <template v-if="client.status === 'archived'"> · <Badge color="amber">Client archivé : lecture seule</Badge></template>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <template v-if="currentFolder && can.manage_folders">
                            <button type="button" class="btn-ghost btn-sm" @click="openFolderModal('rename')"><Pencil class="h-4 w-4" aria-hidden="true" /> Renommer</button>
                            <button type="button" class="btn-ghost btn-sm" @click="openPicker('folder')"><FolderInput class="h-4 w-4" aria-hidden="true" /> Déplacer</button>
                            <button type="button" class="btn-ghost btn-sm text-red-700" @click="confirmFolderDelete = true"><Trash2 class="h-4 w-4" aria-hidden="true" /> Supprimer</button>
                        </template>
                        <button v-if="can.manage_folders && (selection.type === 'all' || selection.type === 'folder')" type="button" class="btn-secondary btn-sm" @click="openFolderModal('create')">
                            <FolderPlus class="h-4 w-4" aria-hidden="true" /> Nouveau dossier
                        </button>
                        <button v-if="can.create" type="button" class="btn-primary btn-sm" @click="createCredential"><Plus class="h-4 w-4" aria-hidden="true" /> Nouvel accès</button>
                    </div>
                </header>

                <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 px-4 py-2.5">
                    <div class="relative min-w-48 flex-1">
                        <label for="vault-local-search" class="sr-only">Filtrer les accès</label>
                        <Search class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                        <input id="vault-local-search" v-model="filters.q" type="search" class="form-input pl-8" placeholder="Filtrer : libellé, utilisateur, hôte, URL, notes…" />
                    </div>
                    <div v-if="selected.length" class="flex items-center gap-2 text-sm">
                        <span class="text-slate-600">{{ selected.length }} sélectionné{{ selected.length > 1 ? 's' : '' }}</span>
                        <button v-if="can.manage_folders" type="button" class="btn-secondary btn-sm" @click="openPicker('credentials', selected)">
                            <FolderInput class="h-4 w-4" aria-hidden="true" /> Déplacer vers…
                        </button>
                        <button type="button" class="btn-ghost btn-sm" @click="selected = []">Annuler</button>
                    </div>
                </div>

                <ul v-if="subfolders?.length" class="grid grid-cols-2 gap-2 border-b border-slate-200 p-4 sm:grid-cols-3 xl:grid-cols-4">
                    <li v-for="folder in subfolders" :key="folder.id">
                        <Link :href="clientUrl({ folder: folder.id })" class="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm hover:border-turquoise-300 hover:bg-slate-50">
                            <FolderOpen class="h-4 w-4 shrink-0 text-amber-500" aria-hidden="true" />
                            <span class="truncate">{{ folder.name }}</span>
                            <span class="ml-auto text-xs text-slate-400">{{ folder.count }}</span>
                        </Link>
                    </li>
                </ul>

                <div v-if="credentials.data.length" class="overflow-x-auto">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th v-if="can.manage_folders" scope="col" class="w-8">
                                    <input type="checkbox" class="h-4 w-4 rounded border-slate-300" :checked="allSelected" aria-label="Tout sélectionner" @change="toggleAll" />
                                </th>
                                <th scope="col">Libellé</th>
                                <th scope="col">Utilisateur</th>
                                <th scope="col">Mot de passe</th>
                                <th scope="col">Rattaché à</th>
                                <th v-if="selection.type !== 'folder'" scope="col">Dossier</th>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="credential in credentials.data"
                                :id="`credential-${credential.id}`"
                                :key="credential.id"
                                class="transition-colors"
                                :class="highlighted === credential.id ? 'bg-amber-50' : ''"
                            >
                                <td v-if="can.manage_folders">
                                    <input
                                        v-model="selected"
                                        type="checkbox"
                                        :value="credential.id"
                                        class="h-4 w-4 rounded border-slate-300"
                                        :aria-label="`Sélectionner ${credential.label}`"
                                    />
                                </td>
                                <td class="min-w-48">
                                    <p class="font-medium text-slate-900">{{ credential.label }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ credential.access_type_label }}
                                        <template v-if="credential.host"> · <span class="font-mono">{{ credential.host }}<template v-if="credential.port">:{{ credential.port }}</template></span></template>
                                    </p>
                                    <a
                                        v-if="credential.url && credential.url.startsWith('http')"
                                        :href="credential.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="link inline-flex items-center gap-0.5 text-xs break-all"
                                    >
                                        {{ credential.url }} <ExternalLink class="h-3 w-3 shrink-0" aria-hidden="true" />
                                    </a>
                                    <span v-else-if="credential.url" class="block font-mono text-xs break-all text-slate-600">{{ credential.url }}</span>
                                    <p v-if="credential.notes" class="mt-0.5 text-xs whitespace-pre-line text-slate-500">{{ credential.notes }}</p>
                                </td>
                                <td>
                                    <span v-if="credential.username" class="inline-flex items-center gap-1">
                                        <span class="font-mono text-xs">{{ credential.username }}</span>
                                        <button type="button" class="btn-ghost btn-sm px-1" @click="copyText(credential.username ?? '', 'Identifiant')">
                                            <Copy class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Copier l'identifiant de {{ credential.label }}</span>
                                        </button>
                                    </span>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td>
                                    <SecretValue v-if="credential.has_secret" :reveal-url="credential.reveal_url" :label="credential.label" />
                                    <span v-else class="text-xs text-slate-400">Aucun</span>
                                    <p v-if="credential.secret_updated_at" class="mt-0.5 text-xs text-slate-400">modifié le {{ formatDateTime(credential.secret_updated_at) }}</p>
                                </td>
                                <td class="text-xs">
                                    <Link v-if="credential.equipment" :href="clientUrl({ equipment: credential.equipment.id })" class="link">{{ credential.equipment.name }}</Link>
                                    <Link v-else-if="credential.service" :href="clientUrl({ service: credential.service.id })" class="link">{{ credential.service.name }}</Link>
                                    <span v-else class="text-slate-500">Client</span>
                                </td>
                                <td v-if="selection.type !== 'folder'" class="text-xs">
                                    <Link v-if="credential.folder" :href="clientUrl({ folder: credential.folder.id })" class="link">{{ credential.folder.name }}</Link>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <template v-if="can.create">
                                        <button type="button" class="btn-ghost btn-sm" @click="editCredential(credential)">
                                            <Pencil class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Modifier {{ credential.label }}</span>
                                        </button>
                                        <button type="button" class="btn-ghost btn-sm" @click="openPicker('credentials', [credential.id])">
                                            <FolderInput class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Déplacer {{ credential.label }}</span>
                                        </button>
                                        <button type="button" class="btn-ghost btn-sm text-red-700" @click="toDelete = credential">
                                            <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer {{ credential.label }}</span>
                                        </button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState
                    v-else
                    :icon="KeyRound"
                    :title="filters.q ? 'Aucun accès ne correspond' : 'Aucun accès ici'"
                    :description="
                        selection.type === 'equipment'
                            ? 'Les accès créés pour cet équipement, ici ou depuis sa fiche, apparaîtront à cet endroit.'
                            : 'Les secrets sont chiffrés et ne sont révélés qu\'à la demande ; chaque révélation est enregistrée.'
                    "
                >
                    <button v-if="can.create && !filters.q" type="button" class="btn-primary btn-sm" @click="createCredential"><Plus class="h-4 w-4" aria-hidden="true" /> Nouvel accès</button>
                </EmptyState>
                <Pagination :paginator="credentials" />
            </div>
        </section>
    </div>

    <template v-if="client && options && can">
        <CredentialFormModal
            :show="showForm"
            :client-id="client.id"
            :credential="editing"
            :types="options.types"
            :equipment="options.equipment"
            :services="options.services"
            :folders="options.folders"
            :can-write-secrets="can.write_secrets"
            :defaults="formDefaults"
            @close="showForm = false"
        />

        <NameModal
            :show="nameModal.show"
            :title="nameModal.mode === 'create' ? (currentFolder ? `Nouveau dossier dans « ${currentFolder.name} »` : 'Nouveau dossier') : 'Renommer le dossier'"
            label="Nom du dossier"
            :initial="nameModal.mode === 'rename' ? (currentFolder?.name ?? '') : ''"
            :submit-label="nameModal.mode === 'create' ? 'Créer' : 'Renommer'"
            :error="nameModal.error"
            :processing="nameModal.processing"
            @close="nameModal.show = false"
            @submit="submitFolderName"
        />

        <FolderPickerModal
            :show="picker.show"
            :title="picker.mode === 'folder' ? `Déplacer le dossier « ${currentFolder?.name} »` : picker.ids.length > 1 ? `Déplacer ${picker.ids.length} accès` : 'Déplacer l\'accès'"
            :folders="tree?.folders ?? []"
            :root-label="`${client.name} (racine)`"
            :excluded="picker.mode === 'folder' && currentFolder ? descendantsOf(currentFolder.id) : []"
            :processing="picker.processing"
            @close="picker.show = false"
            @select="moveTo"
        />

        <ConfirmDialog
            :show="toDelete !== null"
            title="Supprimer l'accès"
            :message="`L'accès « ${toDelete?.label} » et son secret seront supprimés définitivement, y compris de la fiche à laquelle il est rattaché.`"
            confirm-label="Supprimer"
            danger
            @close="toDelete = null"
            @confirm="destroyCredential"
        />

        <ConfirmDialog
            :show="confirmFolderDelete"
            title="Supprimer le dossier"
            :message="`Le dossier « ${currentFolder?.name} » sera supprimé. Aucun accès n'est supprimé : son contenu (accès et sous-dossiers) remonte dans le dossier parent.`"
            confirm-label="Supprimer le dossier"
            danger
            @close="confirmFolderDelete = false"
            @confirm="destroyFolder"
        />
    </template>
</template>
