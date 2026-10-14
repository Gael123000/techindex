<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { Download, Eye, FileText, FolderOpen, Paperclip, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { formatBytes, formatDateTime } from '@/lib/format';
import type { AttachmentItem, AttachmentsPayload } from '@/types';
import ConfirmDialog from './ConfirmDialog.vue';

/**
 * Pièces jointes d'un élément. Les liens pointent vers le contrôleur de
 * téléchargement (contrôle des droits côté serveur), jamais vers le fichier.
 */
const props = defineProps<{ attachments: AttachmentsPayload }>();

const fileInput = ref<HTMLInputElement | null>(null);
const toDelete = ref<AttachmentItem | null>(null);

const form = useForm<{ file: File | null; description: string; attachable_type: string; attachable_id: number }>({
    file: null,
    description: '',
    attachable_type: props.attachments.upload?.attachable_type ?? '',
    attachable_id: props.attachments.upload?.attachable_id ?? 0,
});

function onFile(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function upload(): void {
    if (!props.attachments.upload || !form.file) {
        return;
    }

    form.post(props.attachments.upload.url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('file', 'description');

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

function destroy(): void {
    if (!toDelete.value) {
        return;
    }

    router.delete(toDelete.value.download_url, {
        preserveScroll: true,
        onFinish: () => {
            toDelete.value = null;
        },
    });
}
</script>

<template>
    <section class="card" aria-labelledby="attachments-title">
        <header class="flex items-center gap-2 border-b border-slate-200 px-4 py-3">
            <Paperclip class="h-4 w-4 text-turquoise-700" aria-hidden="true" />
            <h2 id="attachments-title" class="text-sm font-semibold text-navy-900">Pièces jointes</h2>
            <span class="text-xs text-slate-500">({{ attachments.items.length }})</span>
            <Link v-if="attachments.explorer_url" :href="attachments.explorer_url" class="link ml-auto inline-flex items-center gap-1 text-xs">
                <FolderOpen class="h-3.5 w-3.5" aria-hidden="true" /> Explorateur
            </Link>
        </header>

        <ul v-if="attachments.items.length" class="divide-y divide-slate-100">
            <li v-for="item in attachments.items" :key="item.id" class="flex items-start gap-3 px-4 py-2.5 text-sm">
                <FileText class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <a :href="item.download_url" class="link block truncate">{{ item.original_name }}</a>
                    <p class="text-xs text-slate-500">
                        {{ formatBytes(item.size) }} · {{ formatDateTime(item.created_at) }}<template v-if="item.uploader"> · {{ item.uploader.name }}</template>
                    </p>
                    <p v-if="item.description" class="text-xs text-slate-600">{{ item.description }}</p>
                </div>
                <div class="flex shrink-0 gap-1">
                    <a v-if="item.preview_url" :href="item.preview_url" target="_blank" rel="noopener" class="btn-ghost btn-sm">
                        <Eye class="h-4 w-4" aria-hidden="true" /><span class="sr-only">Aperçu de {{ item.original_name }}</span>
                    </a>
                    <a :href="item.download_url" class="btn-ghost btn-sm">
                        <Download class="h-4 w-4" aria-hidden="true" /><span class="sr-only">Télécharger {{ item.original_name }}</span>
                    </a>
                    <button v-if="item.can_delete" type="button" class="btn-ghost btn-sm text-red-700" @click="toDelete = item">
                        <Trash2 class="h-4 w-4" aria-hidden="true" /><span class="sr-only">Supprimer {{ item.original_name }}</span>
                    </button>
                </div>
            </li>
        </ul>
        <p v-else class="px-4 py-4 text-sm text-slate-500">Aucune pièce jointe.</p>

        <form v-if="attachments.upload" class="space-y-2 border-t border-slate-200 bg-slate-50 px-4 py-3" @submit.prevent="upload">
            <label :for="`attachment-file-${attachments.upload.attachable_type}`" class="block text-xs font-medium text-slate-700">
                Ajouter un fichier (PDF, images, documents bureautiques, schémas — {{ Math.round(attachments.upload.max_size_kb / 1024) }} Mo max.)
            </label>
            <input
                :id="`attachment-file-${attachments.upload.attachable_type}`"
                ref="fileInput"
                type="file"
                :accept="attachments.upload.accept"
                class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-navy-900 file:px-3 file:py-1.5 file:text-sm file:text-white"
                :aria-describedby="form.errors.file ? 'attachment-error' : undefined"
                @change="onFile"
            />
            <input v-model="form.description" type="text" class="form-input" placeholder="Description (facultatif)" maxlength="255" aria-label="Description du fichier" />
            <p v-if="form.errors.file" id="attachment-error" class="text-xs font-medium text-red-700" role="alert">{{ form.errors.file }}</p>
            <button type="submit" class="btn-secondary btn-sm" :disabled="!form.file || form.processing">
                {{ form.processing ? `Envoi… ${form.progress?.percentage ?? 0} %` : 'Téléverser' }}
            </button>
        </form>
    </section>

    <ConfirmDialog
        :show="toDelete !== null"
        title="Supprimer la pièce jointe"
        :message="`Le fichier « ${toDelete?.original_name} » sera supprimé définitivement.`"
        confirm-label="Supprimer"
        danger
        @close="toDelete = null"
        @confirm="destroy"
    />
</template>
