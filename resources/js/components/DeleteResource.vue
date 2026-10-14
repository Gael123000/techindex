<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from './ConfirmDialog.vue';

/**
 * Suppression définitive encadrée : l'utilisateur doit recopier le nom de
 * la ressource ; le serveur revérifie ce texte et les droits.
 */
const props = defineProps<{ url: string; name: string; label?: string; message?: string }>();

const open = ref(false);
const processing = ref(false);

function confirm(): void {
    processing.value = true;
    router.delete(props.url, {
        data: { confirmation: props.name },
        onFinish: () => {
            processing.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <button type="button" class="btn-ghost text-red-700 hover:bg-red-50 hover:text-red-800" @click="open = true">
        <Trash2 class="h-4 w-4" aria-hidden="true" /> {{ label ?? 'Supprimer définitivement' }}
    </button>
    <ConfirmDialog
        :show="open"
        title="Suppression définitive"
        :message="message ?? 'Cette action est irréversible. Préférez l\'archivage pour conserver l\'historique.'"
        confirm-label="Supprimer définitivement"
        danger
        :require-text="name"
        :processing="processing"
        @close="open = false"
        @confirm="confirm"
    />
</template>
