<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Building2,
    CheckCircle2,
    CircleAlert,
    Download,
    Eye,
    File as FileIcon,
    FileImage,
    FileSpreadsheet,
    FileText,
    Folder,
    FolderInput,
    FolderPlus,
    FolderRoot,
    FolderUp,
    Link2,
    Loader2,
    Pencil,
    Search,
    Trash2,
    Upload,
    X,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, reactive, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import FolderPickerModal from '@/components/FolderPickerModal.vue';
import FolderTree from '@/components/FolderTree.vue';
import FormField from '@/components/FormField.vue';
import Modal from '@/components/Modal.vue';
import NameModal from '@/components/NameModal.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import TextInput from '@/components/TextInput.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatBytes, formatDateTime } from '@/lib/format';
import { HttpError, postForm, requestJson } from '@/lib/http';
import type { Breadcrumb, ClientFolder, ExplorerFile, FolderNode, Paginated } from '@/types';

defineOptions({ layout: AppLayout });

interface Subfolder {
    id: number;
    name: string;
    files: number;
    folders: number;
}

interface UploadItem {
    key: number;
    file: File;
    path: string | null;
    status: 'pending' | 'uploading' | 'done' | 'error';
    error?: string;
}

const props = defineProps<{
    clients: ClientFolder[];
    client: { id: number; name: string; reference: string; status: 'active' | 'archived'; root_count: number } | null;
    search?: { q: string; results: ExplorerFile[] };
    folders?: FolderNode[];
    current?: { id: number; name: string; parent_id: number | null; path: Array<{ id: number; name: string }> } | null;
    subfolders?: Subfolder[];
    files?: Paginated<ExplorerFile>;
    filters?: { q: string };
    upload?: { url: string; accept: string; max_size_kb: number } | null;
    can?: { contribute: boolean; manage: boolean };
}>();

/* -------------------------------------------------------------------------
 * Navigation
 * ---------------------------------------------------------------------- */
function clientUrl(folderId: number | null = null): string {
    return route('files.clients.show', folderId === null ? { client: props.client!.id } : { client: props.client!.id, folder: folderId });
}

const breadcrumbs = computed<Breadcrumb[]>(() => {
    const items: Breadcrumb[] = [{ label: 'Fichiers', href: route('files.index') }];

    if (props.client) {
        items.push({ label: props.client.name, href: clientUrl() });
        props.current?.path.slice(0, -1).forEach((folder) => items.push({ label: folder.name, href: clientUrl(folder.id) }));
    }

    return items;
});

const globalQuery = ref(props.search?.q ?? '');

function globalSearch(): void {
    router.get(route('files.index'), globalQuery.value.trim() ? { q: globalQuery.value.trim() } : {});
}

const { filters } = useFilters(() => (props.client ? clientUrl(props.current?.id ?? null) : route('files.index')), { q: props.filters?.q ?? '' });

function fileIcon(file: ExplorerFile): Component {
    if (file.mime_type.startsWith('image/')) {
        return FileImage;
    }

    if (['xls', 'xlsx', 'csv', 'ods'].includes(file.extension)) {
        return FileSpreadsheet;
    }

    return ['pdf', 'doc', 'docx', 'odt', 'txt'].includes(file.extension) ? FileText : FileIcon;
}

/* -------------------------------------------------------------------------
 * Sélection multiple et déplacement
 * ---------------------------------------------------------------------- */
const selectedFiles = ref<number[]>([]);
const selectedFolders = ref<number[]>([]);
const selectionCount = computed(() => selectedFiles.value.length + selectedFolders.value.length);

watch(
    () => [props.files, props.subfolders],
    () => {
        selectedFiles.value = [];
        selectedFolders.value = [];
    },
);

const picker = reactive({ show: false, files: [] as number[], folders: [] as number[], processing: false });

function descendantsOf(ids: number[]): number[] {
    const result = [...ids];

    for (let i = 0; i < result.length; i++) {
        props.folders?.filter((folder) => folder.parent_id === result[i]).forEach((folder) => result.push(folder.id));
    }

    return result;
}

function openPicker(files: number[], folders: number[]): void {
    Object.assign(picker, { show: true, files, folders, processing: false });
}

function moveTo(folderId: number | null): void {
    if (!props.client) {
        return;
    }

    picker.processing = true;
    router.post(
        route('files.clients.move', props.client.id),
        { attachment_ids: picker.files, folder_ids: picker.folders, target_folder_id: folderId },
        {
            preserveScroll: true,
            onSuccess: () => {
                picker.show = false;
            },
            onFinish: () => {
                picker.processing = false;
            },
        },
    );
}

/* -------------------------------------------------------------------------
 * Dossiers : création, renommage, suppression
 * ---------------------------------------------------------------------- */
const nameModal = reactive({
    show: false,
    mode: 'create' as 'create' | 'rename',
    folder: null as Subfolder | { id: number; name: string } | null,
    parentId: null as number | null,
    error: undefined as string | undefined,
    processing: false,
});

function openCreateFolder(): void {
    Object.assign(nameModal, { show: true, mode: 'create', folder: null, parentId: props.current?.id ?? null, error: undefined, processing: false });
}

function openRenameFolder(folder: { id: number; name: string }, parentId: number | null): void {
    Object.assign(nameModal, { show: true, mode: 'rename', folder, parentId, error: undefined, processing: false });
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
        router.post(route('files.clients.folders.store', props.client.id), { name, parent_id: nameModal.parentId }, options);
    } else if (nameModal.folder) {
        router.put(route('files.clients.folders.update', [props.client.id, nameModal.folder.id]), { name, parent_id: nameModal.parentId }, options);
    }
}

const folderDelete = reactive({ show: false, folder: null as { id: number; name: string } | null, message: '', processing: false });

async function askDeleteFolder(folder: { id: number; name: string }): Promise<void> {
    if (!props.client) {
        return;
    }

    try {
        const summary = await requestJson<{ folders: number; own: number; linked: number }>(route('files.clients.folders.summary', [props.client.id, folder.id]));
        const parts = [`Le dossier « ${folder.name} »`];

        if (summary.folders > 0) {
            parts.push(`et ses ${summary.folders} sous-dossier(s)`);
        }

        let message = `${parts.join(' ')} seront supprimés.`;

        if (summary.own > 0) {
            message += ` ${summary.own} fichier(s) seront supprimés définitivement.`;
        }

        if (summary.linked > 0) {
            message += ` ${summary.linked} fichier(s) liés à une fiche ne seront pas supprimés : ils reviendront à la racine du client.`;
        }

        Object.assign(folderDelete, { show: true, folder, message, processing: false });
    } catch (exception) {
        notice.value = exception instanceof Error ? exception.message : 'Erreur inattendue.';
    }
}

function destroyFolder(): void {
    if (!props.client || !folderDelete.folder) {
        return;
    }

    folderDelete.processing = true;
    router.delete(route('files.clients.folders.destroy', [props.client.id, folderDelete.folder.id]), {
        preserveScroll: true,
        onFinish: () => {
            Object.assign(folderDelete, { show: false, processing: false });
        },
    });
}

/* -------------------------------------------------------------------------
 * Fichiers : renommage, suppression
 * ---------------------------------------------------------------------- */
const fileEdit = reactive({ show: false, file: null as ExplorerFile | null, name: '', description: '', errors: {} as Record<string, string>, processing: false });
const fileToDelete = ref<ExplorerFile | null>(null);
const notice = ref<string | null>(null);

function openFileEdit(file: ExplorerFile): void {
    Object.assign(fileEdit, { show: true, file, name: file.base_name, description: file.description ?? '', errors: {}, processing: false });
}

function submitFileEdit(): void {
    if (!props.client || !fileEdit.file) {
        return;
    }

    fileEdit.processing = true;
    router.put(
        route('files.clients.attachments.update', [props.client.id, fileEdit.file.id]),
        { name: fileEdit.name, description: fileEdit.description || null },
        {
            preserveScroll: true,
            onSuccess: () => {
                fileEdit.show = false;
            },
            onError: (errors) => {
                fileEdit.errors = errors;
            },
            onFinish: () => {
                fileEdit.processing = false;
            },
        },
    );
}

function destroyFile(): void {
    if (!fileToDelete.value) {
        return;
    }

    router.delete(fileToDelete.value.download_url, {
        preserveScroll: true,
        onFinish: () => {
            fileToDelete.value = null;
        },
    });
}

/* -------------------------------------------------------------------------
 * Envoi de fichiers et de dossiers (sélection ou glisser-déposer)
 * ---------------------------------------------------------------------- */
const fileInput = ref<HTMLInputElement | null>(null);
const folderInput = ref<HTMLInputElement | null>(null);
const queue = ref<UploadItem[]>([]);
const uploading = ref(false);
const dragging = ref(false);
let uploadKey = 0;

const MAX_ITEMS = 500;
// Fichiers système ignorés lors de l'import d'un dossier.
const IGNORED = ['thumbs.db', 'desktop.ini', '.ds_store'];

const allowedExtensions = computed(() => (props.upload?.accept ?? '').split(',').map((ext) => ext.trim().replace(/^\./, '').toLowerCase()));
const queueDone = computed(() => queue.value.filter((item) => item.status === 'done').length);
const queueErrors = computed(() => queue.value.filter((item) => item.status === 'error').length);

function precheck(file: File): string | undefined {
    const extension = file.name.includes('.') ? file.name.split('.').pop()!.toLowerCase() : '';

    if (!allowedExtensions.value.includes(extension)) {
        return 'Type de fichier non autorisé.';
    }

    if (props.upload && file.size > props.upload.max_size_kb * 1024) {
        return `Fichier trop volumineux (${Math.round(props.upload.max_size_kb / 1024)} Mo max.).`;
    }

    return undefined;
}

function enqueue(entries: Array<{ file: File; path: string | null }>): void {
    const accepted = entries.filter(({ file }) => !file.name.startsWith('.') && !IGNORED.includes(file.name.toLowerCase())).slice(0, MAX_ITEMS);

    if (entries.length > MAX_ITEMS) {
        notice.value = `Seuls les ${MAX_ITEMS} premiers fichiers sont envoyés ; importez le reste en plusieurs fois.`;
    }

    for (const { file, path } of accepted) {
        const error = precheck(file);
        queue.value.push({ key: ++uploadKey, file, path, status: error ? 'error' : 'pending', error });
    }

    void processQueue();
}

async function processQueue(): Promise<void> {
    if (uploading.value || !props.upload) {
        return;
    }

    uploading.value = true;
    let uploaded = 0;

    for (const item of queue.value) {
        if (item.status !== 'pending') {
            continue;
        }

        item.status = 'uploading';
        const data = new FormData();
        data.append('file', item.file);

        if (props.current) {
            data.append('folder_id', String(props.current.id));
        }

        if (item.path) {
            data.append('relative_path', item.path);
        }

        try {
            await postForm(props.upload.url, data);
            item.status = 'done';
            uploaded++;
        } catch (exception) {
            item.status = 'error';
            item.error = exception instanceof HttpError || exception instanceof Error ? exception.message : 'Échec de l\'envoi.';
        }
    }

    uploading.value = false;

    if (uploaded > 0) {
        router.reload();
    }
}

function clearQueue(): void {
    queue.value = queue.value.filter((item) => item.status === 'pending' || item.status === 'uploading');
}

function onPickFiles(event: Event): void {
    const input = event.target as HTMLInputElement;
    enqueue([...(input.files ?? [])].map((file) => ({ file, path: null })));
    input.value = '';
}

function onPickFolder(event: Event): void {
    const input = event.target as HTMLInputElement;
    enqueue([...(input.files ?? [])].map((file) => ({ file, path: file.webkitRelativePath || null })));
    input.value = '';
}

async function readEntry(entry: FileSystemEntry, prefix: string, out: Array<{ file: File; path: string | null }>): Promise<void> {
    if (out.length > MAX_ITEMS) {
        return;
    }

    if (entry.isFile) {
        const file = await new Promise<File>((resolve, reject) => (entry as FileSystemFileEntry).file(resolve, reject));
        out.push({ file, path: prefix ? `${prefix}${file.name}` : null });

        return;
    }

    if (entry.isDirectory) {
        const reader = (entry as FileSystemDirectoryEntry).createReader();
        let batch: FileSystemEntry[];

        do {
            batch = await new Promise<FileSystemEntry[]>((resolve, reject) => reader.readEntries(resolve, reject));

            for (const child of batch) {
                await readEntry(child, `${prefix}${entry.name}/`, out);
            }
        } while (batch.length > 0);
    }
}

async function onDrop(event: DragEvent): Promise<void> {
    dragging.value = false;

    if (!props.upload || !event.dataTransfer) {
        return;
    }

    const entries = [...event.dataTransfer.items]
        .filter((item) => item.kind === 'file')
        .map((item) => item.webkitGetAsEntry())
        .filter((entry): entry is FileSystemEntry => entry !== null);

    const files: Array<{ file: File; path: string | null }> = [];

    if (entries.length > 0) {
        for (const entry of entries) {
            await readEntry(entry, '', files);
        }
    } else {
        [...event.dataTransfer.files].forEach((file) => files.push({ file, path: null }));
    }

    enqueue(files);
}

function onDragOver(event: DragEvent): void {
    if (props.upload && event.dataTransfer?.types.includes('Files')) {
        dragging.value = true;
    }
}
</script>

<template>
    <PageHeader
        :title="current ? current.name : client ? client.name : 'Explorateur de fichiers'"
        :breadcrumbs="breadcrumbs"
        :description="client ? undefined : 'Documents, PDF et images de vos clients. Un dossier est créé automatiquement pour chaque client.'"
    />

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[17rem_minmax(0,1fr)]">
        <aside class="card self-start p-3 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto" aria-label="Arborescence des fichiers">
            <form role="search" class="mb-3" @submit.prevent="globalSearch">
                <label for="files-search" class="sr-only">Rechercher un fichier dans tous les clients</label>
                <div class="relative">
                    <Search class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                    <input id="files-search" v-model="globalQuery" type="search" class="form-input pl-8 text-sm" placeholder="Tous les clients…" autocomplete="off" />
                </div>
            </form>

            <h2 class="px-2 text-xs font-semibold tracking-wider text-slate-500 uppercase">Clients</h2>
            <ul class="mt-1 space-y-0.5">
                <li v-for="item in clients" :key="item.id">
                    <Link
                        :href="route('files.clients.show', item.id)"
                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                        :class="client?.id === item.id ? (current ? 'bg-slate-100 font-medium text-navy-900' : 'bg-navy-900 text-white') : 'text-slate-700 hover:bg-slate-100'"
                        :aria-current="client?.id === item.id && !current ? 'page' : undefined"
                    >
                        <Building2 class="h-4 w-4 shrink-0" :class="client?.id === item.id && !current ? 'text-turquoise-300' : 'text-slate-400'" aria-hidden="true" />
                        <span class="truncate">{{ item.name }}</span>
                        <span class="ml-auto text-xs opacity-70">{{ item.count }}</span>
                    </Link>
                    <FolderTree
                        v-if="client?.id === item.id && folders?.length"
                        class="mt-1 mb-2 ml-3 border-l border-slate-200 pl-1"
                        :folders="folders"
                        :selected-id="current?.id ?? null"
                        :href-for="(id: number) => clientUrl(id)"
                    />
                </li>
            </ul>
            <p v-if="!clients.length" class="px-2 py-2 text-sm text-slate-500">Aucun client ne vous est attribué.</p>
        </aside>

        <section class="min-w-0 space-y-4">
            <p v-if="notice" class="flex items-start justify-between gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status">
                {{ notice }}
                <button type="button" class="shrink-0" @click="notice = null"><X class="h-4 w-4" aria-hidden="true" /><span class="sr-only">Fermer</span></button>
            </p>

            <!-- Vue globale : un dossier par client, ou résultats de recherche -->
            <template v-if="!client">
                <div v-if="search?.q" class="card">
                    <header class="border-b border-slate-200 px-4 py-3">
                        <h2 class="text-sm font-semibold text-navy-900">Fichiers dont le nom contient « {{ search.q }} »</h2>
                    </header>
                    <div v-if="search.results.length" class="overflow-x-auto">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th scope="col">Nom</th>
                                    <th scope="col">Client</th>
                                    <th scope="col">Dossier</th>
                                    <th scope="col">Lié à</th>
                                    <th scope="col">Taille</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="file in search.results" :key="file.id">
                                    <td>
                                        <a :href="file.preview_url ?? file.download_url" :target="file.preview_url ? '_blank' : undefined" rel="noopener" class="link inline-flex items-center gap-1.5">
                                            <component :is="fileIcon(file)" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />{{ file.name }}
                                        </a>
                                    </td>
                                    <td>
                                        <Link v-if="file.client" :href="route('files.clients.show', file.client.id)" class="link">{{ file.client.name }}</Link>
                                    </td>
                                    <td class="text-xs">
                                        <Link
                                            v-if="file.client && file.folder"
                                            :href="route('files.clients.show', { client: file.client.id, folder: file.folder.id })"
                                            class="link"
                                        >
                                            {{ file.folder.path }}
                                        </Link>
                                        <span v-else class="text-slate-500">Racine</span>
                                    </td>
                                    <td class="text-xs">
                                        <Link v-if="file.linked" :href="file.linked.url" class="link">{{ file.linked.type_label }} · {{ file.linked.label }}</Link>
                                        <span v-else class="text-slate-400">—</span>
                                    </td>
                                    <td class="text-xs whitespace-nowrap">{{ formatBytes(file.size) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <EmptyState v-else :icon="Search" title="Aucun fichier trouvé" />
                </div>

                <template v-else>
                    <ul v-if="clients.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                        <li v-for="item in clients" :key="item.id">
                            <Link :href="route('files.clients.show', item.id)" class="card flex flex-col items-center gap-2 p-4 text-center hover:border-turquoise-300 hover:shadow-sm">
                                <Folder class="h-10 w-10 fill-amber-100 text-amber-500" aria-hidden="true" />
                                <span class="w-full truncate text-sm font-medium text-navy-900">{{ item.name }}</span>
                                <span class="text-xs text-slate-500">
                                    {{ item.count }} fichier{{ item.count > 1 ? 's' : '' }}
                                    <Badge v-if="item.archived" color="amber" class="ml-1">Archivé</Badge>
                                </span>
                            </Link>
                        </li>
                    </ul>
                    <EmptyState v-else :icon="Folder" title="Aucun client" description="Aucun client ne vous est attribué." />
                </template>
            </template>

            <!-- Contenu d'un dossier client -->
            <div
                v-else-if="files && can"
                class="card relative"
                @dragover.prevent="onDragOver"
                @dragleave.self="dragging = false"
                @drop.prevent="onDrop"
            >
                <div
                    v-if="dragging"
                    class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-lg border-2 border-dashed border-turquoise-500 bg-turquoise-50/90 text-sm font-medium text-turquoise-900"
                >
                    <Upload class="mr-2 h-5 w-5" aria-hidden="true" /> Déposez vos fichiers ou dossiers dans « {{ current?.name ?? client.name }} »
                </div>

                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <div class="relative min-w-48 flex-1">
                        <label for="files-local-search" class="sr-only">Rechercher dans les fichiers du client</label>
                        <Search class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                        <input id="files-local-search" v-model="filters.q" type="search" class="form-input pl-8" :placeholder="`Rechercher dans ${client.name}…`" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <template v-if="current && can.contribute">
                            <button type="button" class="btn-ghost btn-sm" @click="openRenameFolder(current, current.parent_id)"><Pencil class="h-4 w-4" aria-hidden="true" /> Renommer</button>
                            <button type="button" class="btn-ghost btn-sm text-red-700" @click="askDeleteFolder(current)"><Trash2 class="h-4 w-4" aria-hidden="true" /> Supprimer</button>
                        </template>
                        <button v-if="can.contribute" type="button" class="btn-secondary btn-sm" @click="openCreateFolder"><FolderPlus class="h-4 w-4" aria-hidden="true" /> Nouveau dossier</button>
                        <template v-if="upload">
                            <button type="button" class="btn-secondary btn-sm" @click="folderInput?.click()"><FolderUp class="h-4 w-4" aria-hidden="true" /> Importer un dossier</button>
                            <button type="button" class="btn-primary btn-sm" @click="fileInput?.click()"><Upload class="h-4 w-4" aria-hidden="true" /> Importer des fichiers</button>
                            <input ref="fileInput" type="file" multiple :accept="upload.accept" class="sr-only" tabindex="-1" aria-hidden="true" @change="onPickFiles" />
                            <input ref="folderInput" type="file" webkitdirectory multiple class="sr-only" tabindex="-1" aria-hidden="true" @change="onPickFolder" />
                        </template>
                    </div>
                </header>

                <div v-if="client.status === 'archived'" class="border-b border-slate-200 bg-amber-50 px-4 py-2 text-xs text-amber-900">Client archivé : dossier en lecture seule.</div>

                <div v-if="selectionCount" class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-2 text-sm">
                    <span class="text-slate-600">{{ selectionCount }} élément{{ selectionCount > 1 ? 's' : '' }} sélectionné{{ selectionCount > 1 ? 's' : '' }}</span>
                    <button type="button" class="btn-secondary btn-sm" @click="openPicker(selectedFiles, selectedFolders)"><FolderInput class="h-4 w-4" aria-hidden="true" /> Déplacer vers…</button>
                    <button
                        type="button"
                        class="btn-ghost btn-sm"
                        @click="
                            selectedFiles = [];
                            selectedFolders = [];
                        "
                    >
                        Annuler
                    </button>
                </div>

                <!-- File d'envoi -->
                <div v-if="queue.length" class="border-b border-slate-200 px-4 py-3">
                    <div class="mb-2 flex items-center justify-between text-sm">
                        <p class="font-medium text-navy-900">
                            Envoi : {{ queueDone }} / {{ queue.length }}
                            <span v-if="queueErrors" class="ml-1 text-red-700">· {{ queueErrors }} erreur{{ queueErrors > 1 ? 's' : '' }}</span>
                        </p>
                        <button v-if="!uploading" type="button" class="btn-ghost btn-sm" @click="clearQueue">Effacer la liste</button>
                    </div>
                    <ul class="max-h-48 space-y-1 overflow-y-auto text-xs">
                        <li v-for="item in queue" :key="item.key" class="flex items-start gap-2">
                            <Loader2 v-if="item.status === 'uploading'" class="h-3.5 w-3.5 shrink-0 animate-spin text-turquoise-700" aria-hidden="true" />
                            <CheckCircle2 v-else-if="item.status === 'done'" class="h-3.5 w-3.5 shrink-0 text-green-700" aria-hidden="true" />
                            <CircleAlert v-else-if="item.status === 'error'" class="h-3.5 w-3.5 shrink-0 text-red-700" aria-hidden="true" />
                            <span v-else class="h-3.5 w-3.5 shrink-0 rounded-full border border-slate-300" aria-hidden="true" />
                            <span class="min-w-0 truncate">{{ item.path ?? item.file.name }}</span>
                            <span v-if="item.error" class="ml-auto shrink-0 text-red-700">{{ item.error }}</span>
                        </li>
                    </ul>
                </div>

                <div v-if="subfolders?.length || files.data.length" class="overflow-x-auto">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th v-if="can.contribute" scope="col" class="w-8"><span class="sr-only">Sélection</span></th>
                                <th scope="col">Nom</th>
                                <th scope="col">Lié à</th>
                                <th scope="col">Taille</th>
                                <th scope="col">Ajouté</th>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-if="current">
                                <td v-if="can.contribute" />
                                <td colspan="5">
                                    <Link :href="clientUrl(current.parent_id)" class="inline-flex items-center gap-2 text-sm text-slate-600 hover:text-navy-900">
                                        <FolderRoot class="h-4 w-4 text-slate-400" aria-hidden="true" /> Dossier parent
                                    </Link>
                                </td>
                            </tr>
                            <tr v-for="folder in subfolders" :key="`d${folder.id}`">
                                <td v-if="can.contribute">
                                    <input v-model="selectedFolders" type="checkbox" :value="folder.id" class="h-4 w-4 rounded border-slate-300" :aria-label="`Sélectionner le dossier ${folder.name}`" />
                                </td>
                                <td>
                                    <Link :href="clientUrl(folder.id)" class="inline-flex items-center gap-2 font-medium text-navy-900 hover:underline">
                                        <Folder class="h-4 w-4 shrink-0 fill-amber-100 text-amber-500" aria-hidden="true" />{{ folder.name }}
                                    </Link>
                                </td>
                                <td class="text-xs text-slate-400">—</td>
                                <td class="text-xs text-slate-500">
                                    {{ folder.files }} fichier{{ folder.files > 1 ? 's' : '' }}<template v-if="folder.folders">, {{ folder.folders }} dossier{{ folder.folders > 1 ? 's' : '' }}</template>
                                </td>
                                <td />
                                <td class="text-right whitespace-nowrap">
                                    <template v-if="can.contribute">
                                        <button type="button" class="btn-ghost btn-sm" @click="openRenameFolder(folder, current?.id ?? null)">
                                            <Pencil class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Renommer {{ folder.name }}</span>
                                        </button>
                                        <button type="button" class="btn-ghost btn-sm" @click="openPicker([], [folder.id])">
                                            <FolderInput class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Déplacer {{ folder.name }}</span>
                                        </button>
                                        <button type="button" class="btn-ghost btn-sm text-red-700" @click="askDeleteFolder(folder)">
                                            <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer {{ folder.name }}</span>
                                        </button>
                                    </template>
                                </td>
                            </tr>
                            <tr v-for="file in files.data" :key="`f${file.id}`">
                                <td v-if="can.contribute">
                                    <input v-model="selectedFiles" type="checkbox" :value="file.id" class="h-4 w-4 rounded border-slate-300" :aria-label="`Sélectionner ${file.name}`" />
                                </td>
                                <td class="min-w-56">
                                    <a
                                        :href="file.preview_url ?? file.download_url"
                                        :target="file.preview_url ? '_blank' : undefined"
                                        rel="noopener"
                                        class="inline-flex items-center gap-2 text-slate-900 hover:underline"
                                    >
                                        <component :is="fileIcon(file)" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />{{ file.name }}
                                    </a>
                                    <p v-if="file.description" class="mt-0.5 pl-6 text-xs text-slate-500">{{ file.description }}</p>
                                    <p v-if="filters.q" class="mt-0.5 pl-6 text-xs text-slate-400">{{ file.folder ? file.folder.path : 'Racine du client' }}</p>
                                </td>
                                <td class="text-xs">
                                    <Link v-if="file.linked" :href="file.linked.url" class="link inline-flex items-center gap-1" :title="`Fichier joint à la fiche ${file.linked.type_label.toLowerCase()}`">
                                        <Link2 class="h-3 w-3 shrink-0" aria-hidden="true" />{{ file.linked.type_label }} · {{ file.linked.label }}
                                    </Link>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="text-xs whitespace-nowrap">{{ formatBytes(file.size) }}</td>
                                <td class="text-xs whitespace-nowrap text-slate-500">
                                    {{ formatDateTime(file.created_at) }}<template v-if="file.uploader"><br />{{ file.uploader.name }}</template>
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <a v-if="file.preview_url" :href="file.preview_url" target="_blank" rel="noopener" class="btn-ghost btn-sm">
                                        <Eye class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Aperçu de {{ file.name }}</span>
                                    </a>
                                    <a :href="file.download_url" class="btn-ghost btn-sm">
                                        <Download class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Télécharger {{ file.name }}</span>
                                    </a>
                                    <template v-if="file.can_rename">
                                        <button type="button" class="btn-ghost btn-sm" @click="openFileEdit(file)">
                                            <Pencil class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Renommer {{ file.name }}</span>
                                        </button>
                                        <button type="button" class="btn-ghost btn-sm" @click="openPicker([file.id], [])">
                                            <FolderInput class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Déplacer {{ file.name }}</span>
                                        </button>
                                    </template>
                                    <button v-if="file.can_delete" type="button" class="btn-ghost btn-sm text-red-700" @click="fileToDelete = file">
                                        <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer {{ file.name }}</span>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState
                    v-else
                    :icon="Folder"
                    :title="filters.q ? 'Aucun fichier ne correspond' : 'Dossier vide'"
                    :description="upload && !filters.q ? 'Glissez-déposez des fichiers ou des dossiers ici, ou utilisez les boutons d\'import.' : undefined"
                >
                    <Link v-if="current && !filters.q" :href="clientUrl(current.parent_id)" class="btn-ghost btn-sm"><FolderRoot class="h-4 w-4" aria-hidden="true" /> Dossier parent</Link>
                </EmptyState>
                <Pagination :paginator="files" />
            </div>
        </section>
    </div>

    <template v-if="client">
        <NameModal
            :show="nameModal.show"
            :title="nameModal.mode === 'create' ? 'Nouveau dossier' : `Renommer « ${nameModal.folder?.name} »`"
            label="Nom du dossier"
            :initial="nameModal.folder?.name ?? ''"
            :submit-label="nameModal.mode === 'create' ? 'Créer' : 'Renommer'"
            :error="nameModal.error"
            :processing="nameModal.processing"
            @close="nameModal.show = false"
            @submit="submitFolderName"
        />

        <FolderPickerModal
            :show="picker.show"
            :title="`Déplacer ${picker.files.length + picker.folders.length > 1 ? `${picker.files.length + picker.folders.length} éléments` : 'l\'élément'}`"
            :folders="folders ?? []"
            :root-label="`${client.name} (racine)`"
            :excluded="descendantsOf(picker.folders)"
            :processing="picker.processing"
            @close="picker.show = false"
            @select="moveTo"
        />

        <Modal :show="fileEdit.show" title="Renommer le fichier" size="sm" @close="fileEdit.show = false">
            <form id="file-edit-form" class="space-y-4" @submit.prevent="submitFileEdit">
                <FormField id="file_name" label="Nom" :error="fileEdit.errors.name" :hint="`L'extension .${fileEdit.file?.extension} est conservée.`" required>
                    <template #default="{ describedBy, invalid }">
                        <TextInput id="file_name" v-model="fileEdit.name" :maxlength="150" :described-by="describedBy" :invalid="invalid" required autofocus />
                    </template>
                </FormField>
                <FormField id="file_description" label="Description" :error="fileEdit.errors.description">
                    <template #default="{ describedBy, invalid }">
                        <TextInput id="file_description" v-model="fileEdit.description" :maxlength="255" :described-by="describedBy" :invalid="invalid" />
                    </template>
                </FormField>
            </form>
            <template #footer>
                <button type="button" class="btn-secondary" @click="fileEdit.show = false">Annuler</button>
                <button type="submit" form="file-edit-form" class="btn-primary" :disabled="fileEdit.processing">Enregistrer</button>
            </template>
        </Modal>

        <ConfirmDialog
            :show="fileToDelete !== null"
            title="Supprimer le fichier"
            :message="
                fileToDelete?.linked
                    ? `Le fichier « ${fileToDelete?.name} » sera supprimé définitivement, y compris de la fiche ${fileToDelete.linked.label}.`
                    : `Le fichier « ${fileToDelete?.name} » sera supprimé définitivement.`
            "
            confirm-label="Supprimer"
            danger
            @close="fileToDelete = null"
            @confirm="destroyFile"
        />

        <ConfirmDialog
            :show="folderDelete.show"
            title="Supprimer le dossier"
            :message="folderDelete.message"
            confirm-label="Supprimer"
            danger
            :processing="folderDelete.processing"
            @close="folderDelete.show = false"
            @confirm="destroyFolder"
        />
    </template>
</template>
