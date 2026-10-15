<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { Option } from '@/types';
import FormField from './FormField.vue';
import Modal from './Modal.vue';
import SelectInput from './SelectInput.vue';
import TextInput from './TextInput.vue';

/**
 * Ajout ou modification d'une clé d'accès : type choisi dans une liste,
 * précision facultative pour distinguer plusieurs clés du même type
 * (obligatoire pour « Autre »).
 */
const props = withDefaults(
    defineProps<{
        show: boolean;
        mode: 'create' | 'edit';
        types: Option[];
        initialType: string;
        initialDetail?: string | null;
        errors?: { type?: string; name?: string; general?: string };
        processing?: boolean;
    }>(),
    { initialDetail: null, errors: () => ({}), processing: false },
);

const emit = defineEmits<{ close: []; submit: [value: { type: string; name: string | null }] }>();

const OTHER = 'other';

const type = ref(props.initialType);
const detail = ref('');

const detailRequired = computed(() => type.value === OTHER);
const canSubmit = computed(() => type.value !== '' && (!detailRequired.value || detail.value.trim() !== ''));

watch(
    () => props.show,
    (show) => {
        if (show) {
            type.value = props.initialType;
            detail.value = props.initialDetail ?? '';
        }
    },
);

function submit(): void {
    if (canSubmit.value) {
        emit('submit', { type: type.value, name: detail.value.trim() || null });
    }
}
</script>

<template>
    <Modal :show="show" :title="mode === 'create' ? 'Nouvelle clé d\'accès' : 'Modifier la clé d\'accès'" size="sm" @close="emit('close')">
        <form id="passkey-modal-form" class="space-y-4" @submit.prevent="submit">
            <FormField id="passkey-type" label="Type de clé" :error="errors.type" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="passkey-type" v-model="type" :options="types" :described-by="describedBy" :invalid="invalid" required />
                </template>
            </FormField>
            <FormField
                id="passkey-detail"
                :label="detailRequired ? 'Précision' : 'Précision (facultatif)'"
                :error="errors.name"
                hint="Pour distinguer plusieurs clés : « PC du bureau », « YubiKey de secours »…"
                :required="detailRequired"
            >
                <template #default="{ describedBy, invalid }">
                    <TextInput
                        id="passkey-detail"
                        v-model="detail"
                        :maxlength="100"
                        :described-by="describedBy"
                        :invalid="invalid"
                        autocomplete="off"
                        :required="detailRequired"
                    />
                </template>
            </FormField>
            <p v-if="mode === 'create'" class="text-xs text-slate-500">
                En continuant, votre navigateur vous demande de créer la clé : Windows Hello, empreinte, visage, téléphone ou clé de sécurité.
            </p>
            <p v-if="errors.general" class="text-sm text-red-700" role="alert">{{ errors.general }}</p>
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="emit('close')">Annuler</button>
            <button type="submit" form="passkey-modal-form" class="btn-primary" :disabled="processing || !canSubmit">
                {{ mode === 'create' ? 'Continuer' : 'Enregistrer' }}
            </button>
        </template>
    </Modal>
</template>
