<script setup lang="ts">
import { ShieldCheck } from '@lucide/vue';
import { route } from 'ziggy-js';
import ClientHeader from '@/components/ClientHeader.vue';
import CredentialsTable from '@/components/CredentialsTable.vue';
import Pagination from '@/components/Pagination.vue';
import SelectInput from '@/components/SelectInput.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, CredentialRow, Option, Paginated } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    context: ClientContext;
    credentials: Paginated<CredentialRow>;
    filters: { q: string; type: string | null; equipment: number | null; service: number | null; internet_link: number | null };
    types: Option[];
    equipment: Option<number>[];
    services: Option<number>[];
    internetLinks: Option<number>[];
    can: { create: boolean; write_secrets: boolean };
}>();

const { filters } = useFilters(() => route('clients.credentials.index', props.context.id), { ...props.filters });
</script>

<template>
    <ClientHeader :context="context" active="credentials" title="Accès et identifiants" :breadcrumbs="[{ label: 'Accès' }]" />

    <div
        class="mb-4 flex items-start gap-2.5 rounded-md border border-turquoise-200 bg-turquoise-50 px-4 py-3 text-sm text-turquoise-900"
        role="note"
    >
        <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        <p>
            Les secrets sont chiffrés. Leur affichage ou leur copie exige
            <template v-if="context.permissions.secrets">une confirmation récente de votre mot de passe et est enregistré dans l'historique.</template>
            <template v-else>le droit « consultation des secrets », que vous n'avez pas sur ce client.</template>
        </p>
    </div>

    <div class="card mb-4 grid grid-cols-1 gap-3 p-4" :class="internetLinks.length ? 'sm:grid-cols-5' : 'sm:grid-cols-4'">
        <div>
            <label for="cred-q" class="sr-only">Rechercher</label>
            <input id="cred-q" v-model="filters.q" type="search" class="form-input" placeholder="Libellé, utilisateur, hôte…" />
        </div>
        <div>
            <label for="cred-type" class="sr-only">Type</label>
            <SelectInput id="cred-type" v-model="filters.type" :options="types" placeholder="Tous les types" />
        </div>
        <div>
            <label for="cred-eq" class="sr-only">Équipement</label>
            <SelectInput id="cred-eq" v-model="filters.equipment" :options="equipment" placeholder="Tous les équipements" />
        </div>
        <div>
            <label for="cred-srv" class="sr-only">Service</label>
            <SelectInput id="cred-srv" v-model="filters.service" :options="services" placeholder="Tous les services" />
        </div>
        <div v-if="internetLinks.length">
            <label for="cred-isp" class="sr-only">Lien Internet</label>
            <SelectInput id="cred-isp" v-model="filters.internet_link" :options="internetLinks" placeholder="Tous les liens FAI" />
        </div>
    </div>

    <CredentialsTable
        :client-id="context.id"
        :credentials="credentials.data"
        :types="types"
        :equipment="equipment"
        :services="services"
        :internet-links="internetLinks"
        :can-edit="can.create"
        :can-write-secrets="can.write_secrets"
    />
    <Pagination :paginator="credentials" class="card mt-2" />
</template>
