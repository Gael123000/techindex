<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus, ShieldCheck, Users } from '@lucide/vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import SelectInput from '@/components/SelectInput.vue';
import { useFilters } from '@/composables/useFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import type { Option, Paginated } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    users: Paginated<{
        id: number;
        name: string;
        email: string;
        role: string;
        role_label: string;
        is_active: boolean;
        two_factor_enabled: boolean;
        passkeys_count: number;
        last_login_at: string | null;
        assignments_count: number;
    }>;
    filters: { q: string; role: string | null; status: string | null };
    roles: Option[];
}>();

const { filters } = useFilters(() => route('admin.users.index'), { ...props.filters });
const statuses: Option[] = [
    { value: 'active', label: 'Actifs' },
    { value: 'inactive', label: 'Désactivés' },
];
</script>

<template>
    <PageHeader title="Utilisateurs" description="Collaborateurs ayant accès à TechIndex. Aucune inscription publique : les comptes sont créés ici." :breadcrumbs="[{ label: 'Administration' }, { label: 'Utilisateurs' }]">
        <template #actions>
            <Link :href="route('admin.users.create')" class="btn-primary"><Plus class="h-4 w-4" aria-hidden="true" /> Nouvel utilisateur</Link>
        </template>
    </PageHeader>

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-3">
            <div>
                <label for="users-q" class="sr-only">Rechercher</label>
                <input id="users-q" v-model="filters.q" type="search" class="form-input" placeholder="Nom ou e-mail…" />
            </div>
            <div>
                <label for="users-role" class="sr-only">Rôle</label>
                <SelectInput id="users-role" v-model="filters.role" :options="roles" placeholder="Tous les rôles" />
            </div>
            <div>
                <label for="users-status" class="sr-only">Statut</label>
                <SelectInput id="users-status" v-model="filters.status" :options="statuses" placeholder="Tous" />
            </div>
        </div>
        <div v-if="users.data.length" class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th scope="col">Utilisateur</th>
                        <th scope="col">Rôle</th>
                        <th scope="col">Clients attribués</th>
                        <th scope="col">Sécurité</th>
                        <th scope="col">Dernière connexion</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="user in users.data" :key="user.id">
                        <td>
                            <Link :href="route('admin.users.edit', user.id)" class="link">{{ user.name }}</Link>
                            <p class="text-xs text-slate-500">{{ user.email }}</p>
                        </td>
                        <td>{{ user.role_label }}</td>
                        <td>{{ user.role === 'admin' ? 'Tous' : user.assignments_count }}</td>
                        <td class="space-x-1">
                            <Badge v-if="!user.is_active" color="red">Désactivé</Badge>
                            <Badge v-if="user.two_factor_enabled" color="green"><ShieldCheck class="h-3 w-3" aria-hidden="true" /> 2FA</Badge>
                            <Badge v-if="user.passkeys_count" color="teal">Clé d'accès</Badge>
                            <Badge v-if="!user.two_factor_enabled && !user.passkeys_count" color="slate">Sans 2FA</Badge>
                        </td>
                        <td class="text-xs">{{ user.last_login_at ? formatDateTime(user.last_login_at) : 'Jamais' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else :icon="Users" title="Aucun utilisateur" />
        <Pagination :paginator="users" />
    </div>
</template>
