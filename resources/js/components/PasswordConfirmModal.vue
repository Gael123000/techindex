<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Fingerprint } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import { settlePasswordConfirmation, usePasswordConfirmationState } from '@/composables/usePasswordConfirmation';
import { HttpError, requestJson } from '@/lib/http';
import { passkeysSupported, verifyPasskey } from '@/lib/webauthn';
import FormField from './FormField.vue';
import Modal from './Modal.vue';
import TextInput from './TextInput.vue';

/**
 * Confirmation de l'identité avant une action sensible : mot de passe, ou
 * clé d'accès si l'utilisateur en a enregistré une.
 */
const page = usePage();
const state = usePasswordConfirmationState();
const password = ref('');
const error = ref<string | undefined>();
const processing = ref(false);

const canUsePasskey = computed(() => page.props.auth?.user.has_passkeys === true && passkeysSupported());

watch(
    () => state.open,
    () => {
        password.value = '';
        error.value = undefined;
    },
);

async function submit(): Promise<void> {
    processing.value = true;
    error.value = undefined;

    try {
        await requestJson(route('password.confirm.store'), { method: 'POST', body: { password: password.value } });
        password.value = '';
        settlePasswordConfirmation(true);
    } catch (exception) {
        if (exception instanceof HttpError && exception.status === 429) {
            error.value = 'Trop de tentatives. Patientez une minute avant de réessayer.';
        } else if (exception instanceof HttpError && exception.status === 422) {
            error.value = 'Mot de passe incorrect.';
        } else {
            error.value = exception instanceof Error ? exception.message : 'Une erreur est survenue.';
        }
    } finally {
        processing.value = false;
    }
}

async function confirmWithPasskey(): Promise<void> {
    processing.value = true;
    error.value = undefined;

    try {
        await verifyPasskey(route('security.passkey-confirmation.options'), route('security.passkey-confirmation.store'));
        settlePasswordConfirmation(true);
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'La vérification a échoué.';
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Modal :show="state.open" title="Confirmez votre identité" size="sm" @close="settlePasswordConfirmation(false)">
        <div v-if="canUsePasskey" class="mb-5">
            <p class="mb-3 text-sm text-slate-600">Cette action est sensible. Confirmez votre identité avec votre clé d'accès ou votre mot de passe TechIndex.</p>
            <button type="button" class="btn-accent w-full" :disabled="processing" @click="confirmWithPasskey">
                <Fingerprint class="h-4 w-4" aria-hidden="true" /> Utiliser ma clé d'accès
            </button>
            <div class="mt-4 flex items-center gap-3 text-xs text-slate-400" aria-hidden="true"><span class="h-px flex-1 bg-slate-200" />ou<span class="h-px flex-1 bg-slate-200" /></div>
        </div>
        <form id="password-confirm-form" @submit.prevent="submit">
            <p v-if="!canUsePasskey" class="mb-4 text-sm text-slate-600">Cette action est sensible. Saisissez votre mot de passe TechIndex pour continuer.</p>
            <FormField id="confirm-password" label="Mot de passe" :error="error" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput
                        id="confirm-password"
                        v-model="password"
                        type="password"
                        autocomplete="current-password"
                        :described-by="describedBy"
                        :invalid="invalid"
                        required
                        :autofocus="!canUsePasskey"
                    />
                </template>
            </FormField>
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="settlePasswordConfirmation(false)">Annuler</button>
            <button type="submit" form="password-confirm-form" class="btn-primary" :disabled="processing || password === ''">Confirmer</button>
        </template>
    </Modal>
</template>
