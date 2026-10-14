<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Modal from './Modal.vue';

/**
 * Confirmation adaptée à la gravité de l'action. Pour une suppression
 * définitive, l'utilisateur doit recopier un texte (nom de la ressource).
 */
const props = withDefaults(
    defineProps<{
        show: boolean;
        title: string;
        message: string;
        confirmLabel?: string;
        danger?: boolean;
        requireText?: string | null;
        processing?: boolean;
    }>(),
    { confirmLabel: 'Confirmer', danger: false, requireText: null, processing: false },
);

const emit = defineEmits<{ confirm: []; close: [] }>();
const typed = ref('');

watch(
    () => props.show,
    () => {
        typed.value = '';
    },
);

const canConfirm = computed(() => !props.processing && (props.requireText === null || typed.value.trim() === props.requireText));
</script>

<template>
    <Modal :show="show" :title="title" size="sm" @close="emit('close')">
        <p class="text-sm text-slate-700">{{ message }}</p>
        <div v-if="requireText" class="mt-4">
            <label for="confirm-text" class="block text-sm font-medium text-slate-700">
                Pour confirmer, saisissez <strong class="font-semibold">{{ requireText }}</strong>
            </label>
            <input id="confirm-text" v-model="typed" type="text" class="form-input mt-1" autocomplete="off" autofocus />
        </div>
        <template #footer>
            <button type="button" class="btn-secondary" @click="emit('close')">Annuler</button>
            <button type="button" :class="danger ? 'btn-danger' : 'btn-primary'" :disabled="!canConfirm" @click="emit('confirm')">
                {{ confirmLabel }}
            </button>
        </template>
    </Modal>
</template>
