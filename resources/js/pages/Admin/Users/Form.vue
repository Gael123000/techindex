<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import CheckboxInput from '@/components/CheckboxInput.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import FormSection from '@/components/FormSection.vue';
import PageHeader from '@/components/PageHeader.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';

defineOptions({ layout: AppLayout });

interface Role {
    value: string;
    label: string;
    description: string;
    defaults: { can_edit: boolean; can_export: boolean; can_view_secrets: boolean; can_manage: boolean };
}

interface AccessRow {
    client_id: number;
    name: string;
    reference: string;
    archived: boolean;
    enabled: boolean;
    can_edit: boolean;
    can_export: boolean;
    can_view_secrets: boolean;
    can_manage: boolean;
}

const props = defineProps<{
    user: {
        id: number;
        name: string;
        email: string;
        role: string;
        is_active: boolean;
        is_self: boolean;
        two_factor_enabled: boolean;
        passkeys_count: number;
        last_login_at: string | null;
        last_login_ip: string | null;
        must_change_password: boolean;
        sessions: number;
    } | null;
    roles: Role[];
    mailConfigured: boolean;
    access?: AccessRow[];
}>();

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    role: props.user?.role ?? 'technician',
    send_invitation: props.mailConfigured,
    password: '',
    password_confirmation: '',
});

const accessForm = useForm({ access: (props.access ?? []).map((row) => ({ ...row })) });
const accessFilter = ref('');
const confirmAction = ref<null | { title: string; message: string; label: string; url: string; danger: boolean }>(null);

const selectedRole = computed(() => props.roles.find((role) => role.value === form.role));
const visibleAccess = computed(() => {
    const term = accessFilter.value.trim().toLowerCase();

    return accessForm.access.filter((row) => term === '' || `${row.name} ${row.reference}`.toLowerCase().includes(term));
});

const flags = [
    { key: 'can_edit', label: 'Modification' },
    { key: 'can_export', label: 'Export' },
    { key: 'can_view_secrets', label: 'Secrets' },
    { key: 'can_manage', label: 'Gestion' },
] as const;

function submit(): void {
    if (props.user) {
        form.put(route('admin.users.update', props.user.id), { preserveScroll: true });
    } else {
        form.post(route('admin.users.store'));
    }
}

function toggleClient(row: AccessRow, enabled: boolean): void {
    row.enabled = enabled;

    if (enabled) {
        const defaults = props.roles.find((role) => role.value === (props.user?.role ?? form.role))?.defaults;
        Object.assign(row, defaults ?? {});
    }
}

function saveAccess(): void {
    if (props.user) {
        accessForm.put(route('admin.users.access.update', props.user.id), { preserveScroll: true });
    }
}

function ask(action: 'deactivate' | 'reactivate' | 'revoke' | 'reset2fa' | 'reset-link'): void {
    if (!props.user) {
        return;
    }

    const id = props.user.id;
    confirmAction.value = {
        deactivate: { title: 'Désactiver le compte', message: 'L\'utilisateur ne pourra plus se connecter et toutes ses sessions seront fermées immédiatement.', label: 'Désactiver', url: route('admin.users.deactivate', id), danger: true },
        reactivate: { title: 'Réactiver le compte', message: 'L\'utilisateur pourra de nouveau se connecter.', label: 'Réactiver', url: route('admin.users.reactivate', id), danger: false },
        revoke: { title: 'Fermer les sessions', message: 'Toutes les sessions ouvertes de cet utilisateur seront fermées.', label: 'Fermer les sessions', url: route('admin.users.revoke-sessions', id), danger: true },
        reset2fa: { title: 'Réinitialiser la double authentification', message: 'À utiliser en cas de perte d\'un appareil : le code TOTP et toutes les clés d\'accès sont supprimés, l\'utilisateur devra les reconfigurer.', label: 'Réinitialiser', url: route('admin.users.reset-two-factor', id), danger: true },
        'reset-link': { title: 'Envoyer un lien de réinitialisation', message: 'Un e-mail de réinitialisation du mot de passe sera envoyé à l\'utilisateur.', label: 'Envoyer', url: route('admin.users.send-reset-link', id), danger: false },
    }[action];
}

function runAction(): void {
    if (confirmAction.value) {
        router.post(confirmAction.value.url, {}, { preserveScroll: true, onFinish: () => (confirmAction.value = null) });
    }
}
</script>

<template>
    <PageHeader
        :title="user ? user.name : 'Nouvel utilisateur'"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Utilisateurs', href: route('admin.users.index') }, { label: user ? user.name : 'Nouveau' }]"
    >
        <template v-if="user" #actions>
            <Badge v-if="!user.is_active" color="red">Compte désactivé</Badge>
            <button v-if="user.is_active && !user.is_self" type="button" class="btn-danger" @click="ask('deactivate')">Désactiver</button>
            <button v-if="!user.is_active" type="button" class="btn-secondary" @click="ask('reactivate')">Réactiver</button>
        </template>
    </PageHeader>

    <div class="space-y-5">
        <form novalidate @submit.prevent="submit">
            <FormSection title="Compte">
                <FormField id="name" label="Nom" :error="form.errors.name" required>
                    <template #default="{ describedBy, invalid }">
                        <TextInput id="name" v-model="form.name" :described-by="describedBy" :invalid="invalid" required autofocus />
                    </template>
                </FormField>
                <FormField id="email" label="Adresse e-mail (identifiant)" :error="form.errors.email" required>
                    <template #default="{ describedBy, invalid }">
                        <TextInput id="email" v-model="form.email" type="email" autocomplete="off" :described-by="describedBy" :invalid="invalid" required />
                    </template>
                </FormField>
                <fieldset class="sm:col-span-2">
                    <legend class="mb-1 text-sm font-medium text-slate-700">Rôle <span class="text-red-700" aria-hidden="true">*</span></legend>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <label
                            v-for="role in roles"
                            :key="role.value"
                            class="flex cursor-pointer items-start gap-2 rounded-md border p-3 text-sm"
                            :class="form.role === role.value ? 'border-turquoise-600 bg-turquoise-50' : 'border-slate-200'"
                        >
                            <input v-model="form.role" type="radio" name="role" :value="role.value" class="mt-0.5 h-4 w-4" :disabled="user?.is_self" />
                            <span>
                                <span class="font-medium text-slate-900">{{ role.label }}</span>
                                <span class="block text-xs text-slate-600">{{ role.description }}</span>
                            </span>
                        </label>
                    </div>
                    <p v-if="form.errors.role" class="mt-1 text-xs text-red-700" role="alert">{{ form.errors.role }}</p>
                </fieldset>

                <template v-if="!user">
                    <div class="sm:col-span-2">
                        <CheckboxInput
                            id="send_invitation"
                            v-model="form.send_invitation"
                            label="Envoyer une invitation par e-mail pour que l'utilisateur définisse lui-même son mot de passe"
                            :description="mailConfigured ? 'Recommandé : aucun mot de passe ne transite entre administrateur et utilisateur.' : 'L\'envoi d\'e-mails n\'est pas configuré (MAIL_MAILER) : le message ne serait pas distribué.'"
                        />
                    </div>
                    <template v-if="!form.send_invitation">
                        <FormField id="password" label="Mot de passe temporaire" :error="form.errors.password" hint="À changer obligatoirement à la première connexion." required>
                            <template #default="{ describedBy, invalid }">
                                <TextInput id="password" v-model="form.password" type="password" autocomplete="new-password" :described-by="describedBy" :invalid="invalid" />
                            </template>
                        </FormField>
                        <FormField id="password_confirmation" label="Confirmation" :error="form.errors.password_confirmation" required>
                            <template #default="{ describedBy, invalid }">
                                <TextInput id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" :described-by="describedBy" :invalid="invalid" />
                            </template>
                        </FormField>
                    </template>
                </template>

                <div class="flex justify-end gap-2 sm:col-span-2">
                    <Link :href="route('admin.users.index')" class="btn-secondary">Retour</Link>
                    <button type="submit" class="btn-primary" :disabled="form.processing">{{ user ? 'Enregistrer' : 'Créer le compte' }}</button>
                </div>
            </FormSection>
        </form>

        <section v-if="user" class="card" aria-labelledby="security-title">
            <header class="border-b border-slate-200 px-5 py-3.5"><h2 id="security-title" class="text-sm font-semibold text-navy-900">Sécurité du compte</h2></header>
            <div class="grid grid-cols-1 gap-4 p-5 text-sm sm:grid-cols-2">
                <dl class="space-y-2">
                    <div><dt class="text-xs text-slate-500">Double authentification (TOTP)</dt><dd>{{ user.two_factor_enabled ? 'Activée' : 'Non activée' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Clés d'accès</dt><dd>{{ user.passkeys_count || 'Aucune' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Dernière connexion</dt><dd>{{ user.last_login_at ? `${formatDateTime(user.last_login_at)} (${user.last_login_ip})` : 'Jamais' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Sessions ouvertes</dt><dd>{{ user.sessions }}</dd></div>
                    <div v-if="user.must_change_password"><dd><Badge color="amber">Mot de passe temporaire à changer</Badge></dd></div>
                </dl>
                <div class="flex flex-col items-start gap-2">
                    <button v-if="user.sessions > 0 && !user.is_self" type="button" class="btn-secondary btn-sm" @click="ask('revoke')">Fermer toutes ses sessions</button>
                    <button v-if="user.two_factor_enabled || user.passkeys_count > 0" type="button" class="btn-secondary btn-sm" @click="ask('reset2fa')">
                        Réinitialiser la double authentification et les clés d'accès
                    </button>
                    <button v-if="user.is_active" type="button" class="btn-secondary btn-sm" @click="ask('reset-link')">Envoyer un lien de réinitialisation du mot de passe</button>
                </div>
            </div>
        </section>

        <section v-if="user" class="card" aria-labelledby="access-title">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-3.5">
                <div>
                    <h2 id="access-title" class="text-sm font-semibold text-navy-900">Clients attribués et droits</h2>
                    <p class="text-xs text-slate-500">
                        <template v-if="user.role === 'admin'">Un administrateur accède à tous les clients : ces affectations ne s'appliquent pas.</template>
                        <template v-else>La lecture découle de l'affectation ; les autres droits se règlent client par client.</template>
                    </p>
                </div>
                <input v-model="accessFilter" type="search" class="form-input max-w-xs" placeholder="Filtrer les clients…" aria-label="Filtrer les clients" />
            </header>
            <form @submit.prevent="saveAccess">
                <div class="max-h-[28rem] overflow-auto">
                    <table class="table-base">
                        <thead class="sticky top-0">
                            <tr>
                                <th scope="col">Client</th>
                                <th scope="col">Accès</th>
                                <th v-for="flag in flags" :key="flag.key" scope="col">{{ flag.label }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in visibleAccess" :key="row.client_id">
                                <th scope="row" class="text-left font-normal">
                                    {{ row.name }} <span class="text-xs text-slate-500">{{ row.reference }}</span>
                                    <Badge v-if="row.archived" color="slate" class="ml-1">archivé</Badge>
                                </th>
                                <td>
                                    <input
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-slate-300"
                                        :checked="row.enabled"
                                        :aria-label="`Accès à ${row.name}`"
                                        @change="toggleClient(row, ($event.target as HTMLInputElement).checked)"
                                    />
                                </td>
                                <td v-for="flag in flags" :key="flag.key">
                                    <input v-model="row[flag.key]" type="checkbox" class="h-4 w-4 rounded border-slate-300" :disabled="!row.enabled" :aria-label="`${flag.label} – ${row.name}`" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-3">
                    <button type="submit" class="btn-primary" :disabled="accessForm.processing || !accessForm.isDirty">Enregistrer les droits</button>
                </div>
            </form>
        </section>
    </div>

    <ConfirmDialog
        :show="confirmAction !== null"
        :title="confirmAction?.title ?? ''"
        :message="confirmAction?.message ?? ''"
        :confirm-label="confirmAction?.label"
        :danger="confirmAction?.danger"
        @close="confirmAction = null"
        @confirm="runAction"
    />
</template>
