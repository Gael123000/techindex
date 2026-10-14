<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Fingerprint } from '@lucide/vue';
import { computed, nextTick, onMounted, ref } from 'vue';
import { route } from 'ziggy-js';
import FormField from '@/components/FormField.vue';
import TextInput from '@/components/TextInput.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { passkeysSupported, verifyPasskey } from '@/lib/webauthn';

/**
 * Second facteur après le mot de passe : clé d'accès (Windows Hello,
 * téléphone, clé de sécurité), code TOTP ou code de récupération, selon ce
 * que l'utilisateur a configuré.
 */
const props = withDefaults(defineProps<{ methods?: { totp: boolean; passkey: boolean } }>(), {
    methods: () => ({ totp: true, passkey: false }),
});

const useRecovery = ref(false);
const codeInput = ref<InstanceType<typeof TextInput> | null>(null);
const recoveryInput = ref<InstanceType<typeof TextInput> | null>(null);
const supported = ref(true);
const passkeyBusy = ref(false);
const passkeyError = ref<string | null>(null);

const form = useForm({
    code: '',
    recovery_code: '',
});

onMounted(() => {
    supported.value = passkeysSupported();
});

const description = computed(() => {
    if (useRecovery.value) {
        return 'Saisissez l\'un de vos codes de récupération.';
    }

    if (props.methods.passkey && props.methods.totp) {
        return 'Confirmez votre identité avec votre clé d\'accès ou le code de votre application d\'authentification.';
    }

    return props.methods.passkey
        ? 'Confirmez votre identité avec votre clé d\'accès : Windows Hello, empreinte, téléphone ou clé de sécurité.'
        : 'Saisissez le code à 6 chiffres affiché par votre application d\'authentification.';
});

async function toggle(): Promise<void> {
    useRecovery.value = !useRecovery.value;
    form.reset();
    form.clearErrors();
    await nextTick();
    (useRecovery.value ? recoveryInput.value : codeInput.value)?.focus();
}

function submit(): void {
    form.post(route('two-factor.login.store'), {
        onFinish: () => form.reset(),
    });
}

async function usePasskey(): Promise<void> {
    passkeyBusy.value = true;
    passkeyError.value = null;

    try {
        const result = await verifyPasskey<{ redirect: string }>(route('two-factor.passkey.options'), route('two-factor.passkey.store'));
        // Session régénérée : chargement complet de la page suivante.
        window.location.assign(result.redirect);
    } catch (exception) {
        passkeyError.value = exception instanceof Error ? exception.message : 'La vérification a échoué.';
        passkeyBusy.value = false;
    }
}
</script>

<template>
    <AuthLayout title="Double authentification" :description="description">
        <div v-if="methods.passkey && !useRecovery" class="space-y-3">
            <button type="button" class="btn-accent w-full" :disabled="passkeyBusy || !supported" @click="usePasskey">
                <Fingerprint class="h-4 w-4" aria-hidden="true" />
                {{ passkeyBusy ? 'Vérification en cours…' : 'Utiliser ma clé d\'accès' }}
            </button>
            <p v-if="!supported" class="text-sm text-red-700" role="alert">Ce navigateur ne prend pas en charge les clés d'accès.</p>
            <p v-if="passkeyError" class="text-sm text-red-700" role="alert">{{ passkeyError }}</p>
        </div>

        <div v-if="methods.passkey && methods.totp && !useRecovery" class="my-5 flex items-center gap-3 text-xs text-slate-400" aria-hidden="true">
            <span class="h-px flex-1 bg-slate-200" />ou<span class="h-px flex-1 bg-slate-200" />
        </div>

        <form v-if="methods.totp || useRecovery" class="space-y-4" @submit.prevent="submit">
            <FormField v-if="!useRecovery" id="code" label="Code de vérification" :error="form.errors.code" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput
                        id="code"
                        ref="codeInput"
                        v-model="form.code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        :maxlength="6"
                        :described-by="describedBy"
                        :invalid="invalid"
                        required
                        :autofocus="!methods.passkey"
                    />
                </template>
            </FormField>

            <FormField v-else id="recovery_code" label="Code de récupération" :error="form.errors.recovery_code" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput
                        id="recovery_code"
                        ref="recoveryInput"
                        v-model="form.recovery_code"
                        autocomplete="one-time-code"
                        :described-by="describedBy"
                        :invalid="invalid"
                        required
                    />
                </template>
            </FormField>

            <button type="submit" :class="methods.passkey ? 'btn-secondary' : 'btn-primary'" class="w-full" :disabled="form.processing">Valider</button>
        </form>

        <p v-if="methods.totp" class="mt-5 text-center text-sm">
            <button type="button" class="link" @click="toggle">
                {{ useRecovery ? (methods.passkey ? 'Utiliser une clé d\'accès ou un code' : 'Utiliser un code d\'authentification') : 'Utiliser un code de récupération' }}
            </button>
        </p>
        <p v-else-if="methods.passkey" class="mt-5 text-center text-xs text-slate-500">
            Clé d'accès perdue ? Un administrateur peut réinitialiser votre double authentification.
        </p>
    </AuthLayout>
</template>
