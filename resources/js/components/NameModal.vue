<script setup lang="ts">
import { ref, watch } from 'vue';
import FormField from './FormField.vue';
import Modal from './Modal.vue';
import TextInput from './TextInput.vue';

/**
 * Saisie d'un nom (création ou renommage d'un dossier, d'un fichier).
 */
const props = withDefaults(
    defineProps<{
        show: boolean;
        title: string;
        label: string;
        initial?: string;
        submitLabel?: string;
        hint?: string;
        error?: string;
        processing?: boolean;
        maxlength?: number;
    }>(),
    { initial: '', submitLabel: 'Enregistrer', hint: undefined, error: undefined, processing: false, maxlength: 100 },
);

const emit = defineEmits<{ close: []; submit: [name: string] }>();

const name = ref('');

watch(
    () => props.show,
    (show) => {
        if (show) {
            name.value = props.initial;
        }
    },
);

function submit(): void {
    if (name.value.trim() !== '') {
        emit('submit', name.value.trim());
    }
}
</script>

<template>
    <Modal :show="show" :title="title" size="sm" @close="emit('close')">
        <form id="name-modal-form" @submit.prevent="submit">
            <FormField id="name-modal-input" :label="label" :error="error" :hint="hint" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput
                        id="name-modal-input"
                        v-model="name"
                        :maxlength="maxlength"
                        :described-by="describedBy"
                        :invalid="invalid"
                        autocomplete="off"
                        required
                        autofocus
                    />
                </template>
            </FormField>
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="emit('close')">Annuler</button>
            <button type="submit" form="name-modal-form" class="btn-primary" :disabled="processing || name.trim() === ''">{{ submitLabel }}</button>
        </template>
    </Modal>
</template>
