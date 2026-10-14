<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Fingerprint, Monitor, Pencil, Plus, ShieldCheck, ShieldOff, Trash2 } from '@lucide/vue';
import { onMounted, reactive, ref } from 'vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import FormSection from '@/components/FormSection.vue';
import Modal from '@/components/Modal.vue';
import NameModal from '@/components/NameModal.vue';
import PageHeader from '@/components/PageHeader.vue';
import TextInput from '@/components/TextInput.vue';
import { ensurePasswordConfirmed } from '@/composables/usePasswordConfirmation';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import { passkeysSupported, registerPasskey } from '@/lib/webauthn';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    profile: {
        name: string;
        email: string;
        role_label: string;
        password_changed_at: string | null;
        last_login_at: string | null;
        must_change_password: boolean;
    };
    twoFactor: {
        enabled: boolean;
        pending: boolean;
        required: boolean;
        setup: { qr_svg: string; secret_key: string } | null;
        recovery_codes: string[] | null;
    };
    passkeys: Array<{ id: number; name: string; authenticator: string | null; created_at: string; last_used_at: string | null }>;
    sessions: Array<{ id: string; ip_address: string | null; user_agent: string; last_active_at: string; is_current: boolean }>;
    status: string | null;
}>();

/* Clés d'accès : ajout (après confirmation de l'identité), renommage, suppression. */
const passkeysAvailable = ref(true);
const passkeyModal = reactive({
    show: false,
    mode: 'create' as 'create' | 'rename',
    passkey: null as { id: number; name: string } | null,
    initial: '',
    error: undefined as string | undefined,
    processing: false,
});
const passkeyToDelete = ref<{ id: number; name: string } | null>(null);

onMounted(() => {
    passkeysAvailable.value = passkeysSupported();
});

function suggestedPasskeyName(): string {
    const agent = navigator.userAgent;

    if (/iPhone|iPad/.test(agent)) {
        return 'iPhone / iPad';
    }

    if (/Android/.test(agent)) {
        return 'Téléphone Android';
    }

    if (/Windows/.test(agent)) {
        return 'Windows Hello';
    }

    return /Mac OS/.test(agent) ? 'Mac' : 'Clé d\'accès';
}

async function startAddPasskey(): Promise<void> {
    working.value = true;

    try {
        if (await ensurePasswordConfirmed('default')) {
            Object.assign(passkeyModal, { show: true, mode: 'create', passkey: null, initial: suggestedPasskeyName(), error: undefined, processing: false });
        }
    } finally {
        working.value = false;
    }
}

function startRenamePasskey(passkey: { id: number; name: string }): void {
    Object.assign(passkeyModal, { show: true, mode: 'rename', passkey, initial: passkey.name, error: undefined, processing: false });
}

async function submitPasskey(name: string): Promise<void> {
    passkeyModal.processing = true;
    passkeyModal.error = undefined;

    if (passkeyModal.mode === 'rename' && passkeyModal.passkey) {
        router.put(
            route('profile.passkeys.update', passkeyModal.passkey.id),
            { name },
            {
                preserveScroll: true,
                onSuccess: () => {
                    passkeyModal.show = false;
                },
                onError: (errors) => {
                    passkeyModal.error = errors.name;
                },
                onFinish: () => {
                    passkeyModal.processing = false;
                },
            },
        );

        return;
    }

    try {
        await registerPasskey(route('profile.passkeys.options'), route('profile.passkeys.store'), name);
        passkeyModal.show = false;
        router.reload();
    } catch (exception) {
        passkeyModal.error = exception instanceof Error ? exception.message : 'L\'ajout de la clé d\'accès a échoué.';
    } finally {
        passkeyModal.processing = false;
    }
}

async function deletePasskey(): Promise<void> {
    const passkey = passkeyToDelete.value;
    passkeyToDelete.value = null;

    if (passkey) {
        await withConfirmation(() => router.delete(route('profile.passkeys.destroy', passkey.id), { preserveScroll: true }));
    }
}

const profileForm = useForm({ name: props.profile.name });
const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });
const confirmForm = useForm({ code: '' });
const sessionsForm = useForm({ password: '' });
const showSessionsModal = ref(false);
const showDisable2fa = ref(false);
const working = ref(false);

function updateProfile(): void {
    profileForm.put(route('profile.update'), { preserveScroll: true });
}

function updatePassword(): void {
    passwordForm.put(route('user-password.update'), {
        errorBag: 'updatePassword',
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
        onError: () => passwordForm.reset('current_password', 'password', 'password_confirmation'),
    });
}

/**
 * Les opérations 2FA exigent une confirmation récente du mot de passe :
 * elle est demandée dans une fenêtre avant d'appeler la route.
 */
async function withConfirmation(action: () => void): Promise<void> {
    working.value = true;

    try {
        if (await ensurePasswordConfirmed('default')) {
            action();
        }
    } finally {
        working.value = false;
    }
}

function enableTwoFactor(): void {
    void withConfirmation(() => router.post(route('two-factor.enable'), {}, { preserveScroll: true }));
}

function confirmTwoFactor(): void {
    confirmForm.post(route('two-factor.confirm'), {
        errorBag: 'confirmTwoFactorAuthentication',
        preserveScroll: true,
        onFinish: () => confirmForm.reset(),
    });
}

function regenerateCodes(): void {
    void withConfirmation(() => router.post(route('two-factor.regenerate-recovery-codes'), {}, { preserveScroll: true }));
}

function disableTwoFactor(): void {
    showDisable2fa.value = false;
    void withConfirmation(() => router.delete(route('two-factor.disable'), { preserveScroll: true }));
}

function logoutOtherSessions(): void {
    sessionsForm.delete(route('profile.sessions.destroy'), {
        preserveScroll: true,
        onSuccess: () => {
            showSessionsModal.value = false;
            sessionsForm.reset();
        },
        onError: () => sessionsForm.reset(),
    });
}
</script>

<template>
    <PageHeader title="Mon profil et sécurité" :breadcrumbs="[{ label: 'Profil' }]" />

    <div
        v-if="profile.must_change_password"
        class="mb-5 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        role="alert"
    >
        Votre mot de passe est temporaire : définissez un mot de passe personnel pour accéder à l'application.
    </div>

    <div class="space-y-6">
        <form @submit.prevent="updateProfile">
            <FormSection title="Informations du compte" description="L'adresse e-mail et le rôle sont gérés par un administrateur.">
                <FormField id="name" label="Nom affiché" :error="profileForm.errors.name" required>
                    <template #default="{ describedBy, invalid }">
                        <TextInput id="name" v-model="profileForm.name" autocomplete="name" :described-by="describedBy" :invalid="invalid" required />
                    </template>
                </FormField>
                <dl class="dl-grid">
                    <div>
                        <dt>Adresse e-mail</dt>
                        <dd>{{ profile.email }}</dd>
                    </div>
                    <div>
                        <dt>Rôle</dt>
                        <dd>{{ profile.role_label }}</dd>
                    </div>
                    <div>
                        <dt>Dernière connexion</dt>
                        <dd>{{ formatDateTime(profile.last_login_at) }}</dd>
                    </div>
                    <div>
                        <dt>Mot de passe modifié le</dt>
                        <dd>{{ formatDateTime(profile.password_changed_at) }}</dd>
                    </div>
                </dl>
                <div class="sm:col-span-2">
                    <button type="submit" class="btn-primary" :disabled="profileForm.processing || !profileForm.isDirty">Enregistrer</button>
                </div>
            </FormSection>
        </form>

        <form @submit.prevent="updatePassword">
            <FormSection
                title="Mot de passe de connexion"
                description="Au moins 12 caractères avec majuscules, minuscules et chiffres. Vos autres sessions seront fermées."
            >
                <FormField id="current_password" label="Mot de passe actuel" :error="passwordForm.errors.current_password" required class="sm:col-span-2">
                    <template #default="{ describedBy, invalid }">
                        <TextInput
                            id="current_password"
                            v-model="passwordForm.current_password"
                            type="password"
                            autocomplete="current-password"
                            :described-by="describedBy"
                            :invalid="invalid"
                            required
                        />
                    </template>
                </FormField>
                <FormField id="new_password" label="Nouveau mot de passe" :error="passwordForm.errors.password" required>
                    <template #default="{ describedBy, invalid }">
                        <TextInput
                            id="new_password"
                            v-model="passwordForm.password"
                            type="password"
                            autocomplete="new-password"
                            :described-by="describedBy"
                            :invalid="invalid"
                            required
                        />
                    </template>
                </FormField>
                <FormField id="new_password_confirmation" label="Confirmation" :error="passwordForm.errors.password_confirmation" required>
                    <template #default="{ describedBy, invalid }">
                        <TextInput
                            id="new_password_confirmation"
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            :described-by="describedBy"
                            :invalid="invalid"
                            required
                        />
                    </template>
                </FormField>
                <div class="sm:col-span-2">
                    <button type="submit" class="btn-primary" :disabled="passwordForm.processing">Changer le mot de passe</button>
                </div>
            </FormSection>
        </form>

        <section class="card" aria-labelledby="two-factor-title">
            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-3.5">
                <div>
                    <h2 id="two-factor-title" class="text-sm font-semibold text-navy-900">Double authentification (TOTP)</h2>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Un code temporaire généré par une application (Microsoft Authenticator, FreeOTP, Aegis…) est demandé à chaque connexion.
                    </p>
                </div>
                <Badge v-if="twoFactor.enabled" color="green"><ShieldCheck class="h-3 w-3" aria-hidden="true" /> Activée</Badge>
                <Badge v-else :color="twoFactor.required && !passkeys.length ? 'red' : 'slate'">
                    <ShieldOff class="h-3 w-3" aria-hidden="true" />
                    {{ twoFactor.required && !passkeys.length ? 'Obligatoire, non activée' : passkeys.length ? 'Non utilisée (clé d\'accès active)' : 'Désactivée' }}
                </Badge>
            </header>

            <div class="space-y-4 p-5 text-sm">
                <template v-if="!twoFactor.enabled && !twoFactor.pending">
                    <p class="text-slate-600">Protégez votre compte contre l'utilisation d'un mot de passe volé.</p>
                    <button type="button" class="btn-accent" :disabled="working" @click="enableTwoFactor">Activer la double authentification</button>
                </template>

                <template v-else-if="twoFactor.pending">
                    <template v-if="twoFactor.setup">
                        <p class="text-slate-700">
                            1. Scannez ce QR code avec votre application d'authentification, ou saisissez la clé manuellement.
                        </p>
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                            <!-- SVG généré par le serveur (bacon/bacon-qr-code), sans contenu utilisateur. -->
                            <div class="w-fit rounded-md border border-slate-200 bg-white p-2" aria-label="QR code de configuration" role="img" v-html="twoFactor.setup.qr_svg" />
                            <div>
                                <p class="text-xs text-slate-500">Clé de configuration</p>
                                <code class="mt-1 block rounded bg-slate-100 px-2 py-1 font-mono text-sm tracking-wider break-all select-all">{{ twoFactor.setup.secret_key }}</code>
                            </div>
                        </div>
                        <form class="max-w-xs space-y-3" @submit.prevent="confirmTwoFactor">
                            <FormField id="totp_code" label="2. Saisissez le code affiché" :error="confirmForm.errors.code" required>
                                <template #default="{ describedBy, invalid }">
                                    <TextInput
                                        id="totp_code"
                                        v-model="confirmForm.code"
                                        inputmode="numeric"
                                        autocomplete="one-time-code"
                                        :maxlength="6"
                                        :described-by="describedBy"
                                        :invalid="invalid"
                                        required
                                    />
                                </template>
                            </FormField>
                            <button type="submit" class="btn-primary" :disabled="confirmForm.processing">Confirmer l'activation</button>
                        </form>
                    </template>
                    <template v-else>
                        <p class="text-slate-700">L'activation est en cours : confirmez votre mot de passe pour afficher le QR code.</p>
                        <button type="button" class="btn-accent" :disabled="working" @click="enableTwoFactor">Afficher le QR code</button>
                    </template>
                </template>

                <template v-else>
                    <div v-if="twoFactor.recovery_codes" class="rounded-md border border-amber-300 bg-amber-50 p-4">
                        <p class="font-medium text-amber-900">Codes de récupération</p>
                        <p class="mt-1 text-xs text-amber-900">
                            Conservez-les dans un gestionnaire de mots de passe. Chaque code n'est utilisable qu'une fois et ne sera plus affiché.
                        </p>
                        <ul class="mt-3 grid grid-cols-1 gap-1 font-mono text-sm sm:grid-cols-2">
                            <li v-for="code in twoFactor.recovery_codes" :key="code" class="rounded bg-white px-2 py-1 select-all">{{ code }}</li>
                        </ul>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="btn-secondary" :disabled="working" @click="regenerateCodes">Générer de nouveaux codes de récupération</button>
                        <button v-if="!twoFactor.required" type="button" class="btn-danger" :disabled="working" @click="showDisable2fa = true">
                            Désactiver
                        </button>
                    </div>
                </template>
            </div>
        </section>

        <section class="card" aria-labelledby="passkeys-title">
            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-3.5">
                <div>
                    <h2 id="passkeys-title" class="text-sm font-semibold text-navy-900">Clés d'accès</h2>
                    <p class="mt-0.5 max-w-2xl text-xs text-slate-500">
                        Windows Hello, Touch ID / Face ID, téléphone ou clé de sécurité USB / NFC. Une clé d'accès sert de second facteur à la connexion,
                        confirme les actions sensibles et est indispensable pour ouvrir le gestionnaire de mots de passe.
                    </p>
                </div>
                <Badge v-if="passkeys.length" color="green"><Fingerprint class="h-3 w-3" aria-hidden="true" /> {{ passkeys.length }} active{{ passkeys.length > 1 ? 's' : '' }}</Badge>
                <Badge v-else color="slate">Aucune</Badge>
            </header>
            <ul v-if="passkeys.length" class="divide-y divide-slate-100">
                <li v-for="passkey in passkeys" :key="passkey.id" class="flex items-start gap-3 px-5 py-3 text-sm">
                    <Fingerprint class="mt-0.5 h-4 w-4 text-turquoise-700" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-slate-800">
                            {{ passkey.name }}
                            <span v-if="passkey.authenticator" class="ml-1 text-xs font-normal text-slate-500">({{ passkey.authenticator }})</span>
                        </p>
                        <p class="text-xs text-slate-500">
                            Ajoutée le {{ formatDateTime(passkey.created_at) }} ·
                            {{ passkey.last_used_at ? `dernière utilisation le ${formatDateTime(passkey.last_used_at)}` : 'jamais utilisée' }}
                        </p>
                    </div>
                    <button type="button" class="btn-ghost btn-sm" @click="startRenamePasskey(passkey)">
                        <Pencil class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Renommer {{ passkey.name }}</span>
                    </button>
                    <button type="button" class="btn-ghost btn-sm text-red-700" @click="passkeyToDelete = passkey">
                        <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer {{ passkey.name }}</span>
                    </button>
                </li>
            </ul>
            <div class="space-y-2 p-5 text-sm" :class="passkeys.length ? 'border-t border-slate-200' : ''">
                <p v-if="!passkeys.length" class="text-slate-600">Ajoutez une clé d'accès sur chaque appareil que vous utilisez, ou une clé de sécurité de secours.</p>
                <button type="button" class="btn-accent" :disabled="working || !passkeysAvailable" @click="startAddPasskey">
                    <Plus class="h-4 w-4" aria-hidden="true" /> Ajouter une clé d'accès
                </button>
                <p v-if="!passkeysAvailable" class="text-xs text-red-700">Ce navigateur ne prend pas en charge les clés d'accès, ou la page n'est pas servie en HTTPS.</p>
            </div>
        </section>

        <section class="card" aria-labelledby="sessions-title">
            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-3.5">
                <div>
                    <h2 id="sessions-title" class="text-sm font-semibold text-navy-900">Sessions ouvertes</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Appareils actuellement connectés à votre compte.</p>
                </div>
                <button v-if="sessions.length > 1" type="button" class="btn-secondary btn-sm" @click="showSessionsModal = true">
                    Fermer les autres sessions
                </button>
            </header>
            <ul class="divide-y divide-slate-100">
                <li v-for="session in sessions" :key="session.id" class="flex items-start gap-3 px-5 py-3 text-sm">
                    <Monitor class="mt-0.5 h-4 w-4 text-slate-400" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-slate-700">{{ session.user_agent || 'Navigateur inconnu' }}</p>
                        <p class="text-xs text-slate-500">
                            {{ session.ip_address ?? 'IP inconnue' }} · dernière activité {{ formatDateTime(session.last_active_at) }}
                        </p>
                    </div>
                    <Badge v-if="session.is_current" color="teal">Cette session</Badge>
                </li>
            </ul>
        </section>
    </div>

    <Modal :show="showSessionsModal" title="Fermer les autres sessions" size="sm" @close="showSessionsModal = false">
        <form id="sessions-form" @submit.prevent="logoutOtherSessions">
            <p class="mb-4 text-sm text-slate-600">Confirmez votre mot de passe pour déconnecter tous vos autres appareils.</p>
            <FormField id="sessions_password" label="Mot de passe" :error="sessionsForm.errors.password" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput
                        id="sessions_password"
                        v-model="sessionsForm.password"
                        type="password"
                        autocomplete="current-password"
                        :described-by="describedBy"
                        :invalid="invalid"
                        required
                        autofocus
                    />
                </template>
            </FormField>
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="showSessionsModal = false">Annuler</button>
            <button type="submit" form="sessions-form" class="btn-primary" :disabled="sessionsForm.processing">Fermer les sessions</button>
        </template>
    </Modal>

    <NameModal
        :show="passkeyModal.show"
        :title="passkeyModal.mode === 'create' ? 'Nouvelle clé d\'accès' : 'Renommer la clé d\'accès'"
        label="Nom de la clé"
        hint="Pour la reconnaître : « PC du bureau », « iPhone », « YubiKey de secours »…"
        :initial="passkeyModal.initial"
        :submit-label="passkeyModal.mode === 'create' ? 'Continuer' : 'Renommer'"
        :error="passkeyModal.error"
        :processing="passkeyModal.processing"
        @close="passkeyModal.show = false"
        @submit="submitPasskey"
    />

    <ConfirmDialog
        :show="passkeyToDelete !== null"
        title="Supprimer la clé d'accès"
        :message="`La clé « ${passkeyToDelete?.name} » ne permettra plus de vous connecter ni d'ouvrir le gestionnaire de mots de passe. Pensez à la retirer aussi de l'appareil.`"
        confirm-label="Supprimer"
        danger
        @close="passkeyToDelete = null"
        @confirm="deletePasskey"
    />

    <ConfirmDialog
        :show="showDisable2fa"
        title="Désactiver la double authentification"
        message="Votre compte ne sera plus protégé que par votre mot de passe. Continuer ?"
        confirm-label="Désactiver"
        danger
        @close="showDisable2fa = false"
        @confirm="disableTwoFactor"
    />
</template>
