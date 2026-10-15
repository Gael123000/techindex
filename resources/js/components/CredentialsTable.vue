<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { KeyRound, Pencil, Plus, Trash2, Vault } from '@lucide/vue';
import { ref } from 'vue';
import { route } from 'ziggy-js';
import { formatDateTime } from '@/lib/format';
import type { CredentialRow, Option } from '@/types';
import ConfirmDialog from './ConfirmDialog.vue';
import CredentialFormModal from './CredentialFormModal.vue';
import EmptyState from './EmptyState.vue';
import SecretValue from './SecretValue.vue';

const props = withDefaults(
    defineProps<{
        clientId: number;
        credentials: CredentialRow[];
        types: Option[];
        canEdit: boolean;
        canWriteSecrets: boolean;
        equipment?: Option<number>[];
        services?: Option<number>[];
        internetLinks?: Option<number>[];
        fixedEquipmentId?: number | null;
        fixedServiceId?: number | null;
        fixedInternetLinkId?: number | null;
        showTarget?: boolean;
        compact?: boolean;
        /** Lien vers les mêmes accès dans le gestionnaire de mots de passe. */
        vaultUrl?: string;
    }>(),
    {
        showTarget: true,
        compact: false,
        fixedEquipmentId: undefined,
        fixedServiceId: undefined,
        fixedInternetLinkId: undefined,
        equipment: undefined,
        services: undefined,
        internetLinks: undefined,
        vaultUrl: undefined,
    },
);

const editing = ref<CredentialRow | null>(null);
const showForm = ref(false);
const toDelete = ref<CredentialRow | null>(null);

function create(): void {
    editing.value = null;
    showForm.value = true;
}

function edit(credential: CredentialRow): void {
    editing.value = credential;
    showForm.value = true;
}

function destroy(): void {
    if (!toDelete.value) {
        return;
    }

    router.delete(route('clients.credentials.destroy', [props.clientId, toDelete.value.id]), {
        preserveScroll: true,
        onFinish: () => {
            toDelete.value = null;
        },
    });
}
</script>

<template>
    <section class="card" aria-labelledby="credentials-title">
        <header class="flex items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
            <h2 id="credentials-title" class="flex items-center gap-2 text-sm font-semibold text-navy-900">
                <KeyRound class="h-4 w-4 text-turquoise-700" aria-hidden="true" /> Accès et identifiants
            </h2>
            <span class="flex items-center gap-2">
                <Link v-if="vaultUrl" :href="vaultUrl" class="link inline-flex items-center gap-1 text-xs">
                    <Vault class="h-3.5 w-3.5" aria-hidden="true" /> Gestionnaire de mots de passe
                </Link>
                <button v-if="canEdit" type="button" class="btn-secondary btn-sm" @click="create"><Plus class="h-4 w-4" aria-hidden="true" /> Ajouter</button>
            </span>
        </header>

        <div v-if="credentials.length" class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th scope="col">Libellé</th>
                        <th scope="col">Type</th>
                        <th scope="col">Utilisateur</th>
                        <th scope="col">Secret</th>
                        <th v-if="!compact" scope="col">Adresse</th>
                        <th v-if="showTarget" scope="col">Rattaché à</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="credential in credentials" :key="credential.id">
                        <td class="font-medium text-slate-900">
                            {{ credential.label }}
                            <p v-if="credential.notes && !compact" class="mt-0.5 text-xs font-normal whitespace-pre-line text-slate-500">{{ credential.notes }}</p>
                        </td>
                        <td>{{ credential.access_type_label }}</td>
                        <td class="font-mono text-xs">{{ credential.username ?? '—' }}</td>
                        <td>
                            <SecretValue v-if="credential.has_secret" :reveal-url="credential.reveal_url" :label="credential.label" />
                            <span v-else class="text-xs text-slate-400">Aucun</span>
                            <p v-if="credential.secret_updated_at" class="mt-0.5 text-xs text-slate-400">modifié le {{ formatDateTime(credential.secret_updated_at) }}</p>
                        </td>
                        <td v-if="!compact" class="text-xs break-all">
                            <a v-if="credential.url && credential.url.startsWith('http')" :href="credential.url" target="_blank" rel="noopener noreferrer" class="link">{{ credential.url }}</a>
                            <span v-else-if="credential.url">{{ credential.url }}</span>
                            <span v-if="credential.host" class="block font-mono">{{ credential.host }}<template v-if="credential.port">:{{ credential.port }}</template></span>
                        </td>
                        <td v-if="showTarget" class="text-xs">
                            <Link v-if="credential.equipment" :href="route('clients.equipment.show', [clientId, credential.equipment.id])" class="link">{{ credential.equipment.name }}</Link>
                            <Link v-else-if="credential.service" :href="route('clients.services.show', [clientId, credential.service.id])" class="link">{{ credential.service.name }}</Link>
                            <Link v-else-if="credential.internet_link" :href="route('clients.internet-links.show', [clientId, credential.internet_link.id])" class="link">{{ credential.internet_link.name }}</Link>
                            <span v-else class="text-slate-400">Client</span>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <template v-if="canEdit">
                                <button type="button" class="btn-ghost btn-sm" @click="edit(credential)">
                                    <Pencil class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Modifier {{ credential.label }}</span>
                                </button>
                                <button type="button" class="btn-ghost btn-sm text-red-700" @click="toDelete = credential">
                                    <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer {{ credential.label }}</span>
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else :icon="KeyRound" title="Aucun accès enregistré" description="Les identifiants sont chiffrés et ne sont révélés qu'à la demande, après déverrouillage par clé d'accès." />
    </section>

    <CredentialFormModal
        :show="showForm"
        :client-id="clientId"
        :credential="editing"
        :types="types"
        :equipment="equipment"
        :services="services"
        :internet-links="internetLinks"
        :can-write-secrets="canWriteSecrets"
        :fixed-equipment-id="fixedEquipmentId"
        :fixed-service-id="fixedServiceId"
        :fixed-internet-link-id="fixedInternetLinkId"
        @close="showForm = false"
    />

    <ConfirmDialog
        :show="toDelete !== null"
        title="Supprimer l'accès"
        :message="`L'accès « ${toDelete?.label} » et son secret seront supprimés définitivement.`"
        confirm-label="Supprimer"
        danger
        @close="toDelete = null"
        @confirm="destroy"
    />
</template>
