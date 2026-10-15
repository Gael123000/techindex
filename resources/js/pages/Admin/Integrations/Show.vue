<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    Copy,
    ExternalLink,
    EyeOff,
    FlaskConical,
    Link2,
    Loader2,
    Plug,
    RefreshCw,
    ShieldCheck,
    Trash2,
    Undo2,
    WandSparkles,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import CheckboxInput from '@/components/CheckboxInput.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import SelectInput from '@/components/SelectInput.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import type { Option } from '@/types';

defineOptions({ layout: AppLayout });

interface Field {
    key: string;
    label: string;
    type: 'text' | 'secret' | 'secret_textarea' | 'select';
    required: boolean;
    help?: string | null;
    placeholder?: string | null;
    default?: string | null;
    options?: Option[];
}

interface Account {
    id: number;
    external_id: string;
    name: string;
    domain: string | null;
    metadata: Record<string, string | number | null>;
    client: { id: number; name: string } | null;
    is_ignored: boolean;
    is_manual: boolean;
    last_error: string | null;
    licenses_count: number;
    last_synced_at: string | null;
    suggested_client_id: number | null;
}

interface Run {
    id: number;
    trigger: 'manual' | 'scheduled';
    status: string;
    status_label: string;
    stats: Record<string, number>;
    message: string | null;
    user: { id: number; name: string } | null;
    created_at: string | null;
    finished_at: string | null;
}

const props = defineProps<{
    definition: {
        key: string;
        label: string;
        vendor: string;
        category_label: string;
        description: string;
        account_label: string;
        credentials: Field[];
        settings: Field[];
        setup: string[];
        docs_url: string | null;
        initials: string;
        color: string;
        beta: boolean;
    };
    integration: {
        exists: boolean;
        is_enabled: boolean;
        frequency: string;
        settings: Record<string, string>;
        configured_credentials: string[];
        credentials_updated_at: string | null;
        last_synced_at: string | null;
        last_status: string | null;
        last_status_label: string | null;
        last_message: string | null;
    };
    accounts: Account[];
    clients: Option<number>[];
    runs: Run[];
    running: boolean;
    frequencies: Option[];
    manualAccounts: { label: string; placeholder: string; help: string } | null;
    consent: { redirect_uri: string; available: boolean } | null;
}>();

const key = computed(() => props.definition.key);
const isConfigured = computed(() => props.integration.configured_credentials.length > 0);

/* ---------- Configuration ---------- */

function initialSettings(): Record<string, string> {
    return Object.fromEntries(props.definition.settings.map((field) => [field.key, props.integration.settings[field.key] ?? field.default ?? '']));
}

const form = useForm({
    is_enabled: props.integration.exists ? props.integration.is_enabled : true,
    frequency: props.integration.frequency,
    settings: initialSettings(),
    credentials: Object.fromEntries(props.definition.credentials.map((field) => [field.key, ''])) as Record<string, string>,
    clear_credentials: [] as string[],
});

function isSet(field: Field): boolean {
    return props.integration.configured_credentials.includes(field.key);
}

function save(): void {
    form.put(route('admin.integrations.update', key.value), {
        preserveScroll: true,
        onSuccess: () => {
            form.credentials = Object.fromEntries(props.definition.credentials.map((field) => [field.key, '']));
            form.clear_credentials = [];
        },
    });
}

const busy = ref<string | null>(null);

function post(action: 'test' | 'sync' | 'match'): void {
    busy.value = action;
    const name = action === 'match' ? 'admin.integrations.accounts.match' : `admin.integrations.${action}`;
    router.post(route(name, key.value), {}, { preserveScroll: true, onFinish: () => (busy.value = null) });
}

const confirmDelete = ref(false);

function destroy(): void {
    router.delete(route('admin.integrations.destroy', key.value), { onFinish: () => (confirmDelete.value = false) });
}

/* ---------- Comptes ---------- */

const accountFilter = ref<'all' | 'unlinked' | 'linked' | 'ignored'>('all');
const accountSearch = ref('');

const filteredAccounts = computed(() => {
    const term = accountSearch.value.trim().toLowerCase();

    return props.accounts.filter((account) => {
        const matchesFilter = accountFilter.value === 'all'
            || (accountFilter.value === 'unlinked' && !account.client && !account.is_ignored)
            || (accountFilter.value === 'linked' && account.client !== null)
            || (accountFilter.value === 'ignored' && account.is_ignored);
        const matchesTerm = term === '' || [account.name, account.domain, account.external_id, account.client?.name].some((value) => value?.toLowerCase().includes(term));

        return matchesFilter && matchesTerm;
    });
});

const counts = computed(() => ({
    linked: props.accounts.filter((account) => account.client !== null).length,
    unlinked: props.accounts.filter((account) => !account.client && !account.is_ignored).length,
    ignored: props.accounts.filter((account) => account.is_ignored).length,
    suggested: props.accounts.filter((account) => account.suggested_client_id !== null).length,
}));

const filterOptions = computed<Option[]>(() => [
    { value: 'all', label: `Tous (${props.accounts.length})` },
    { value: 'unlinked', label: `À rattacher (${counts.value.unlinked})` },
    { value: 'linked', label: `Rattachés (${counts.value.linked})` },
    { value: 'ignored', label: `Ignorés (${counts.value.ignored})` },
]);

function clientLabel(id: number | null): string | null {
    return props.clients.find((client) => client.value === id)?.label ?? null;
}

function updateAccount(account: Account, data: { client_id?: number | null; is_ignored?: boolean }): void {
    router.put(route('admin.integrations.accounts.update', [key.value, account.id]), data, { preserveScroll: true });
}

const removing = ref<Account | null>(null);

function removeAccount(): void {
    if (removing.value) {
        router.delete(route('admin.integrations.accounts.destroy', [key.value, removing.value.id]), {
            preserveScroll: true,
            onFinish: () => (removing.value = null),
        });
    }
}

const accountForm = useForm({ identifier: '', client_id: null as number | null });

function addAccount(): void {
    accountForm.post(route('admin.integrations.accounts.store', key.value), { preserveScroll: true, onSuccess: () => accountForm.reset() });
}

function metadataText(account: Account): string {
    return Object.entries(account.metadata)
        .filter(([, value]) => value !== null && value !== '')
        .map(([label, value]) => `${label} : ${value}`)
        .join(' · ');
}

/* ---------- Consentement Microsoft 365 ---------- */

const consentClient = ref<number | null>(null);
const consentUrl = computed(() => route('admin.integrations.microsoft.consent', consentClient.value ? { client: consentClient.value } : {}));
const copied = ref(false);

async function copyRedirect(): Promise<void> {
    if (!props.consent) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.consent.redirect_uri);
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}

/* ---------- Suivi de la synchronisation en cours ---------- */

let poll: number | undefined;

watch(
    () => props.running,
    (running) => {
        window.clearInterval(poll);

        if (running) {
            poll = window.setInterval(() => router.reload({ only: ['integration', 'accounts', 'runs', 'running'] }), 4000);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => window.clearInterval(poll));

function runSummary(run: Run): string {
    const stats = run.stats;

    if (stats.synced_accounts === undefined) {
        return '—';
    }

    return [
        `${stats.synced_accounts} compte(s)`,
        stats.created ? `${stats.created} ajoutée(s)` : null,
        stats.updated ? `${stats.updated} mise(s) à jour` : null,
        stats.ended ? `${stats.ended} résiliée(s)` : null,
        stats.new_accounts ? `${stats.new_accounts} nouveau(x) compte(s)` : null,
        stats.errors ? `${stats.errors} erreur(s)` : null,
    ].filter(Boolean).join(' · ');
}

function statusClasses(status: string | null): string {
    return {
        success: 'bg-green-50 text-green-800 ring-green-200',
        partial: 'bg-amber-50 text-amber-800 ring-amber-200',
        failed: 'bg-red-50 text-red-800 ring-red-200',
        running: 'bg-turquoise-50 text-turquoise-800 ring-turquoise-200',
        queued: 'bg-slate-100 text-slate-700 ring-slate-200',
    }[status ?? ''] ?? 'bg-slate-100 text-slate-700 ring-slate-200';
}
</script>

<template>
    <PageHeader
        :title="definition.label"
        :description="definition.description"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Intégrations', href: route('admin.integrations.index') }, { label: definition.label }]"
    >
        <template #actions>
            <button v-if="integration.exists" type="button" class="btn-secondary" :disabled="busy !== null || !isConfigured" @click="post('test')">
                <Loader2 v-if="busy === 'test'" class="h-4 w-4 animate-spin" aria-hidden="true" />
                <ShieldCheck v-else class="h-4 w-4" aria-hidden="true" />
                Tester la connexion
            </button>
            <button
                v-if="integration.exists"
                type="button"
                class="btn-primary"
                :disabled="busy !== null || running || !integration.is_enabled"
                :title="integration.is_enabled ? undefined : 'Activez l\'intégration pour la synchroniser'"
                @click="post('sync')"
            >
                <RefreshCw class="h-4 w-4" :class="running || busy === 'sync' ? 'animate-spin' : ''" aria-hidden="true" />
                {{ running ? 'Synchronisation…' : 'Synchroniser' }}
            </button>
        </template>
    </PageHeader>

    <!-- État de la dernière synchronisation -->
    <div
        v-if="integration.last_status || running"
        class="mb-5 flex items-start gap-3 rounded-lg border px-4 py-3 text-sm"
        :class="running ? 'border-turquoise-200 bg-turquoise-50 text-turquoise-900' : integration.last_status === 'failed' ? 'border-red-200 bg-red-50 text-red-900' : integration.last_status === 'partial' ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-green-200 bg-green-50 text-green-900'"
        role="status"
    >
        <Loader2 v-if="running" class="mt-0.5 h-4 w-4 shrink-0 animate-spin" aria-hidden="true" />
        <CircleAlert v-else-if="integration.last_status !== 'success'" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        <CircleCheck v-else class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        <div class="min-w-0">
            <p class="font-medium">
                {{ running ? 'Synchronisation en cours…' : `Dernière synchronisation : ${integration.last_status_label}` }}
                <span v-if="!running && integration.last_synced_at" class="font-normal opacity-80">— {{ formatDateTime(integration.last_synced_at) }}</span>
            </p>
            <p v-if="!running && integration.last_message" class="mt-0.5 whitespace-pre-line opacity-90">{{ integration.last_message }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <!-- Configuration -->
        <form class="card xl:col-span-2" novalidate @submit.prevent="save">
            <header class="flex items-center gap-3 border-b border-slate-200 px-5 py-3.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-[11px] font-bold text-white" :style="{ backgroundColor: definition.color }" aria-hidden="true">
                    {{ definition.initials }}
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-sm font-semibold text-navy-900">Configuration</h2>
                    <p class="text-xs text-slate-500">{{ definition.vendor }} · {{ definition.category_label }}</p>
                </div>
                <span
                    v-if="definition.beta"
                    class="inline-flex items-center gap-1 rounded-full bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-800 ring-1 ring-violet-200 ring-inset"
                >
                    <FlaskConical class="h-3 w-3" aria-hidden="true" /> Bêta
                </span>
            </header>

            <div class="space-y-5 p-5">
                <p v-if="definition.beta" class="rounded-md bg-violet-50 px-3 py-2 text-xs text-violet-900">
                    Connecteur réalisé d'après la documentation publique du fournisseur, sans compte réel de test : testez la connexion et vérifiez la première synchronisation.
                </p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <CheckboxInput
                            id="is_enabled"
                            v-model="form.is_enabled"
                            label="Intégration active"
                            description="Synchronisations manuelles et automatiques autorisées."
                        />
                    </div>
                    <FormField id="frequency" label="Synchronisation automatique" :error="form.errors.frequency">
                        <template #default="{ describedBy, invalid }">
                            <SelectInput id="frequency" v-model="form.frequency" :options="frequencies" :described-by="describedBy" :invalid="invalid" />
                        </template>
                    </FormField>
                </div>

                <fieldset v-if="definition.settings.length" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <legend class="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">Réglages</legend>
                    <FormField
                        v-for="field in definition.settings"
                        :id="`setting-${field.key}`"
                        :key="field.key"
                        :label="field.label"
                        :hint="field.help ?? undefined"
                        :error="form.errors[`settings.${field.key}` as keyof typeof form.errors]"
                        :required="field.required"
                    >
                        <template #default="{ describedBy, invalid }">
                            <SelectInput
                                v-if="field.type === 'select'"
                                :id="`setting-${field.key}`"
                                v-model="form.settings[field.key]"
                                :options="field.options ?? []"
                                :described-by="describedBy"
                                :invalid="invalid"
                            />
                            <TextInput
                                v-else
                                :id="`setting-${field.key}`"
                                v-model="form.settings[field.key]"
                                :placeholder="field.placeholder ?? undefined"
                                autocomplete="off"
                                :described-by="describedBy"
                                :invalid="invalid"
                            />
                        </template>
                    </FormField>
                </fieldset>

                <fieldset class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <legend class="mb-1 text-xs font-semibold tracking-wide text-slate-500 uppercase">Identifiants d'API</legend>
                    <p class="text-xs text-slate-500 sm:col-span-2">
                        Chiffrés à l'enregistrement et jamais réaffichés. Laissez un champ vide pour conserver la valeur enregistrée.
                        <template v-if="integration.credentials_updated_at"> Dernière modification : {{ formatDateTime(integration.credentials_updated_at) }}.</template>
                    </p>
                    <div v-for="field in definition.credentials" :key="field.key" :class="field.type === 'secret_textarea' ? 'sm:col-span-2' : ''">
                        <FormField
                            :id="`credential-${field.key}`"
                            :label="field.label"
                            :hint="field.help ?? undefined"
                            :error="form.errors[`credentials.${field.key}` as keyof typeof form.errors]"
                            :required="field.required && !isSet(field)"
                        >
                            <template #default="{ describedBy, invalid }">
                                <TextArea
                                    v-if="field.type === 'secret_textarea'"
                                    :id="`credential-${field.key}`"
                                    v-model="form.credentials[field.key]"
                                    :rows="4"
                                    :placeholder="isSet(field) ? 'Valeur enregistrée — collez une nouvelle valeur pour la remplacer' : undefined"
                                    :described-by="describedBy"
                                    :invalid="invalid"
                                    class="font-mono text-xs"
                                    spellcheck="false"
                                />
                                <TextInput
                                    v-else
                                    :id="`credential-${field.key}`"
                                    v-model="form.credentials[field.key]"
                                    :type="field.type === 'secret' ? 'password' : 'text'"
                                    :placeholder="isSet(field) ? '•••••••• (enregistré)' : undefined"
                                    autocomplete="new-password"
                                    :described-by="describedBy"
                                    :invalid="invalid"
                                />
                            </template>
                        </FormField>
                        <p v-if="isSet(field)" class="mt-1 inline-flex items-center gap-1 text-xs text-green-700">
                            <CircleCheck class="h-3 w-3" aria-hidden="true" /> Enregistré
                        </p>
                    </div>
                </fieldset>
            </div>

            <footer class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-5 py-3">
                <button v-if="integration.exists" type="button" class="btn-ghost text-red-700 hover:bg-red-50 hover:text-red-800" @click="confirmDelete = true">
                    <Trash2 class="h-4 w-4" aria-hidden="true" /> Supprimer l'intégration
                </button>
                <span v-else />
                <button type="submit" class="btn-primary" :disabled="form.processing">Enregistrer la configuration</button>
            </footer>
        </form>

        <!-- Mise en place -->
        <aside class="space-y-5">
            <section class="card" aria-labelledby="setup-title">
                <header class="border-b border-slate-200 px-4 py-3">
                    <h2 id="setup-title" class="flex items-center gap-2 text-sm font-semibold text-navy-900"><Plug class="h-4 w-4 text-slate-400" aria-hidden="true" /> Mise en place</h2>
                </header>
                <ol class="list-decimal space-y-2 py-3 pr-4 pl-9 text-sm text-slate-700">
                    <li v-for="(step, index) in definition.setup" :key="index">{{ step }}</li>
                </ol>
                <div v-if="consent" class="border-t border-slate-100 px-4 py-3 text-sm">
                    <p class="mb-1 text-xs font-medium text-slate-500">Adresse de retour (URI de redirection)</p>
                    <div class="flex items-center gap-2">
                        <code class="min-w-0 flex-1 truncate rounded bg-slate-100 px-2 py-1 text-xs" :title="consent.redirect_uri">{{ consent.redirect_uri }}</code>
                        <button type="button" class="btn-ghost btn-sm" @click="copyRedirect">
                            <Copy class="h-3.5 w-3.5" aria-hidden="true" /> {{ copied ? 'Copiée' : 'Copier' }}
                        </button>
                    </div>
                </div>
                <p v-if="definition.docs_url" class="border-t border-slate-100 px-4 py-3 text-sm">
                    <a :href="definition.docs_url" target="_blank" rel="noopener noreferrer" class="link inline-flex items-center gap-1">
                        Documentation du fournisseur <ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </p>
            </section>
        </aside>
    </div>

    <!-- Comptes externes et rattachement -->
    <section v-if="integration.exists" class="card mt-5" aria-labelledby="accounts-title">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
            <div>
                <h2 id="accounts-title" class="text-sm font-semibold text-navy-900">Rattachement aux clients</h2>
                <p class="text-xs text-slate-500">
                    Chaque {{ definition.account_label }} rattaché à un client TechIndex voit ses licences importées dans la fiche du client.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button v-if="counts.suggested" type="button" class="btn-secondary btn-sm" :disabled="busy !== null" @click="post('match')">
                    <WandSparkles class="h-3.5 w-3.5" aria-hidden="true" /> Rattacher les {{ counts.suggested }} suggestion(s)
                </button>
            </div>
        </header>

        <!-- Microsoft 365 : consentement et ajout d'un locataire -->
        <div v-if="manualAccounts" class="grid grid-cols-1 gap-4 border-b border-slate-200 bg-slate-50/60 p-4 lg:grid-cols-2">
            <div v-if="consent" class="space-y-2">
                <p class="text-sm font-medium text-navy-900">Autoriser un nouveau locataire</p>
                <p class="text-xs text-slate-500">
                    Ouvrez le lien et connectez-vous avec un administrateur général du locataire du client : il est ajouté automatiquement au retour.
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="min-w-48 flex-1">
                        <label for="consent-client" class="sr-only">Client à rattacher</label>
                        <SelectInput id="consent-client" v-model="consentClient" :options="clients" placeholder="— Client à rattacher (facultatif) —" />
                    </div>
                    <a
                        v-if="consent.available"
                        :href="consentUrl"
                        class="btn-primary btn-sm"
                    >
                        <Link2 class="h-3.5 w-3.5" aria-hidden="true" /> Lien de consentement
                    </a>
                    <span v-else class="text-xs text-amber-800">Enregistrez d'abord l'ID d'application et le secret.</span>
                </div>
            </div>
            <form class="space-y-2" novalidate @submit.prevent="addAccount">
                <p class="text-sm font-medium text-navy-900">Ajouter un {{ definition.account_label }} déjà autorisé</p>
                <div class="flex flex-wrap items-start gap-2">
                    <div class="min-w-48 flex-1">
                        <label for="account-identifier" class="sr-only">{{ manualAccounts.label }}</label>
                        <TextInput id="account-identifier" v-model="accountForm.identifier" :placeholder="manualAccounts.placeholder" :invalid="!!accountForm.errors.identifier" />
                    </div>
                    <div class="min-w-48 flex-1">
                        <label for="account-client" class="sr-only">Client</label>
                        <SelectInput id="account-client" v-model="accountForm.client_id" :options="clients" placeholder="— Client (facultatif) —" />
                    </div>
                    <button type="submit" class="btn-secondary btn-sm" :disabled="accountForm.processing || !accountForm.identifier">Vérifier et ajouter</button>
                </div>
                <p v-if="accountForm.errors.identifier" class="text-xs text-red-700">{{ accountForm.errors.identifier }}</p>
                <p v-else class="text-xs text-slate-500">{{ manualAccounts.help }}</p>
            </form>
        </div>

        <div v-if="accounts.length" class="flex flex-wrap items-center gap-3 border-b border-slate-200 px-4 py-2.5">
            <div class="min-w-56 flex-1">
                <label for="account-search" class="sr-only">Rechercher un compte</label>
                <input id="account-search" v-model="accountSearch" type="search" class="form-input h-9" placeholder="Rechercher un compte ou un client…" />
            </div>
            <div class="w-56">
                <label for="account-filter" class="sr-only">Filtrer</label>
                <SelectInput id="account-filter" v-model="accountFilter" :options="filterOptions" />
            </div>
        </div>

        <div v-if="filteredAccounts.length" class="overflow-x-auto">
            <table class="table-base">
                <caption class="sr-only">Comptes de l'intégration</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ definition.account_label }}</th>
                        <th scope="col" class="w-80">Client TechIndex</th>
                        <th scope="col">Licences</th>
                        <th scope="col">Synchronisation</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="account in filteredAccounts" :key="account.id" :class="account.is_ignored ? 'opacity-60' : ''">
                        <td>
                            <p class="font-medium text-navy-900">{{ account.name }}</p>
                            <p class="text-xs text-slate-500">{{ [account.domain, metadataText(account)].filter(Boolean).join(' · ') || account.external_id }}</p>
                        </td>
                        <td>
                            <p v-if="account.is_ignored" class="text-xs text-slate-500">Ignoré</p>
                            <template v-else>
                                <label :for="`account-client-${account.id}`" class="sr-only">Client de {{ account.name }}</label>
                                <SelectInput
                                    :id="`account-client-${account.id}`"
                                    :model-value="account.client?.id ?? null"
                                    :options="clients"
                                    placeholder="— Non rattaché —"
                                    @update:model-value="(value) => updateAccount(account, { client_id: (value as number | null) ?? null })"
                                />
                                <button
                                    v-if="account.suggested_client_id"
                                    type="button"
                                    class="mt-1 inline-flex items-center gap-1 text-xs text-turquoise-700 hover:underline"
                                    @click="updateAccount(account, { client_id: account.suggested_client_id })"
                                >
                                    <WandSparkles class="h-3 w-3" aria-hidden="true" /> Suggestion : {{ clientLabel(account.suggested_client_id) }}
                                </button>
                            </template>
                        </td>
                        <td class="text-sm">{{ account.client ? account.licenses_count : '—' }}</td>
                        <td class="text-xs">
                            <p v-if="account.last_error" class="max-w-72 text-red-700">{{ account.last_error }}</p>
                            <p v-else-if="account.last_synced_at" class="text-slate-600">{{ formatDateTime(account.last_synced_at) }}</p>
                            <p v-else class="text-slate-400">—</p>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <button
                                type="button"
                                class="btn-ghost btn-sm"
                                :title="account.is_ignored ? 'Ne plus ignorer' : 'Ignorer ce compte (aucun rattachement proposé)'"
                                @click="updateAccount(account, { is_ignored: !account.is_ignored })"
                            >
                                <component :is="account.is_ignored ? Undo2 : EyeOff" class="h-3.5 w-3.5" aria-hidden="true" />
                                <span class="sr-only">{{ account.is_ignored ? 'Ne plus ignorer' : 'Ignorer' }}</span>
                            </button>
                            <button v-if="account.is_manual" type="button" class="btn-ghost btn-sm text-red-700" title="Retirer de l'intégration" @click="removing = account">
                                <Trash2 class="h-3.5 w-3.5" aria-hidden="true" />
                                <span class="sr-only">Retirer</span>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else-if="accounts.length" class="px-4 py-6 text-center text-sm text-slate-500">Aucun compte ne correspond au filtre.</p>
        <p v-else class="px-4 py-6 text-center text-sm text-slate-500">
            {{ manualAccounts ? `Aucun ${definition.account_label} pour l'instant : autorisez-en un ci-dessus.` : 'Les comptes apparaîtront après la première synchronisation.' }}
        </p>
    </section>

    <!-- Historique -->
    <section v-if="runs.length" class="card mt-5" aria-labelledby="runs-title">
        <header class="border-b border-slate-200 px-4 py-3">
            <h2 id="runs-title" class="text-sm font-semibold text-navy-900">Dernières synchronisations</h2>
        </header>
        <div class="overflow-x-auto">
            <table class="table-base">
                <caption class="sr-only">Historique des synchronisations</caption>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Déclenchement</th>
                        <th scope="col">Résultat</th>
                        <th scope="col">Détail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="run in runs" :key="run.id">
                        <td class="text-xs whitespace-nowrap text-slate-600">{{ formatDateTime(run.created_at) }}</td>
                        <td class="text-xs text-slate-600">{{ run.trigger === 'manual' ? `Manuel${run.user ? ` (${run.user.name})` : ''}` : 'Automatique' }}</td>
                        <td>
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset" :class="statusClasses(run.status)">{{ run.status_label }}</span>
                        </td>
                        <td class="text-xs text-slate-600">
                            <p>{{ runSummary(run) }}</p>
                            <p v-if="run.status !== 'success' && run.message" class="mt-0.5 line-clamp-3 whitespace-pre-line text-red-700">{{ run.message }}</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <ConfirmDialog
        :show="confirmDelete"
        title="Supprimer l'intégration"
        message="Les identifiants, les comptes et l'historique de cette intégration seront supprimés. Les licences déjà importées restent chez les clients, comme des licences saisies à la main."
        confirm-label="Supprimer"
        danger
        @confirm="destroy"
        @close="confirmDelete = false"
    />
    <ConfirmDialog
        :show="removing !== null"
        title="Retirer ce compte"
        :message="`« ${removing?.name ?? ''} » ne sera plus synchronisé. Ses licences déjà importées restent chez le client.`"
        confirm-label="Retirer"
        danger
        @confirm="removeAccount"
        @close="removing = null"
    />
</template>
