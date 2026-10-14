<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Download, Network, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';
import CheckboxInput from '@/components/CheckboxInput.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormField from '@/components/FormField.vue';
import Modal from '@/components/Modal.vue';
import SelectInput from '@/components/SelectInput.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ClientContext, Option } from '@/types';

defineOptions({ layout: AppLayout });

interface Vlan {
    id: number;
    site_id: number | null;
    number: number;
    name: string;
    description: string | null;
    subnets_count: number;
    site: { id: number; name: string } | null;
}

interface Subnet {
    id: number;
    site_id: number | null;
    vlan_id: number | null;
    name: string;
    cidr: string;
    gateway: string | null;
    dns_servers: string | null;
    dhcp_enabled: boolean;
    dhcp_range: string | null;
    description: string | null;
    interfaces_count: number;
    site: { id: number; name: string } | null;
    vlan: { id: number; number: number; name: string } | null;
}

const props = defineProps<{ context: ClientContext; vlans: Vlan[]; subnets: Subnet[]; sites: Option<number>[]; can: { edit: boolean } }>();

const vlanOptions = computed(() => props.vlans.map((vlan) => ({ value: vlan.id, label: `${vlan.number} – ${vlan.name}` })));

const vlanModal = ref(false);
const editingVlan = ref<Vlan | null>(null);
const vlanForm = useForm({ site_id: null as number | null, number: null as number | null, name: '', description: '' });

const subnetModal = ref(false);
const editingSubnet = ref<Subnet | null>(null);
const subnetForm = useForm({
    site_id: null as number | null,
    vlan_id: null as number | null,
    name: '',
    cidr: '',
    gateway: '',
    dns_servers: '',
    dhcp_enabled: false,
    dhcp_range: '',
    description: '',
});

const toDelete = ref<{ type: 'vlan' | 'subnet'; id: number; label: string } | null>(null);

function openVlan(vlan: Vlan | null): void {
    editingVlan.value = vlan;
    vlanForm.clearErrors();
    vlanForm.site_id = vlan?.site_id ?? null;
    vlanForm.number = vlan?.number ?? null;
    vlanForm.name = vlan?.name ?? '';
    vlanForm.description = vlan?.description ?? '';
    vlanModal.value = true;
}

function saveVlan(): void {
    const options = { preserveScroll: true, onSuccess: () => (vlanModal.value = false) };

    if (editingVlan.value) {
        vlanForm.put(route('clients.vlans.update', [props.context.id, editingVlan.value.id]), options);
    } else {
        vlanForm.post(route('clients.vlans.store', props.context.id), options);
    }
}

function openSubnet(subnet: Subnet | null): void {
    editingSubnet.value = subnet;
    subnetForm.clearErrors();
    subnetForm.site_id = subnet?.site_id ?? null;
    subnetForm.vlan_id = subnet?.vlan_id ?? null;
    subnetForm.name = subnet?.name ?? '';
    subnetForm.cidr = subnet?.cidr ?? '';
    subnetForm.gateway = subnet?.gateway ?? '';
    subnetForm.dns_servers = subnet?.dns_servers ?? '';
    subnetForm.dhcp_enabled = subnet?.dhcp_enabled ?? false;
    subnetForm.dhcp_range = subnet?.dhcp_range ?? '';
    subnetForm.description = subnet?.description ?? '';
    subnetModal.value = true;
}

function saveSubnet(): void {
    const options = { preserveScroll: true, onSuccess: () => (subnetModal.value = false) };

    if (editingSubnet.value) {
        subnetForm.put(route('clients.subnets.update', [props.context.id, editingSubnet.value.id]), options);
    } else {
        subnetForm.post(route('clients.subnets.store', props.context.id), options);
    }
}

function destroy(): void {
    if (!toDelete.value) {
        return;
    }

    const name = toDelete.value.type === 'vlan' ? 'clients.vlans.destroy' : 'clients.subnets.destroy';
    router.delete(route(name, [props.context.id, toDelete.value.id]), { preserveScroll: true, onFinish: () => (toDelete.value = null) });
}
</script>

<template>
    <ClientHeader :context="context" active="network" title="Réseau et plan d'adressage" :breadcrumbs="[{ label: 'Réseau' }]">
        <template #actions>
            <a v-if="context.permissions.export" :href="route('clients.export', [context.id, 'subnets', 'xlsx'])" class="btn-secondary">
                <Download class="h-4 w-4" aria-hidden="true" /> Exporter
            </a>
        </template>
    </ClientHeader>

    <div class="space-y-5">
        <section class="card" aria-labelledby="subnets-title">
            <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 id="subnets-title" class="text-sm font-semibold text-navy-900">Sous-réseaux</h2>
                <button v-if="can.edit" type="button" class="btn-secondary btn-sm" @click="openSubnet(null)"><Plus class="h-4 w-4" aria-hidden="true" /> Sous-réseau</button>
            </header>
            <div v-if="subnets.length" class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th scope="col">Réseau</th>
                            <th scope="col">Nom</th>
                            <th scope="col">VLAN</th>
                            <th scope="col">Site</th>
                            <th scope="col">Passerelle</th>
                            <th scope="col">DNS</th>
                            <th scope="col">DHCP</th>
                            <th scope="col">Adresses</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="subnet in subnets" :key="subnet.id">
                            <td class="font-mono text-xs">{{ subnet.cidr }}</td>
                            <td>
                                {{ subnet.name }}
                                <p v-if="subnet.description" class="text-xs text-slate-500">{{ subnet.description }}</p>
                            </td>
                            <td>{{ subnet.vlan ? `${subnet.vlan.number} – ${subnet.vlan.name}` : '—' }}</td>
                            <td>{{ subnet.site?.name ?? '—' }}</td>
                            <td class="font-mono text-xs">{{ subnet.gateway ?? '—' }}</td>
                            <td class="font-mono text-xs">{{ subnet.dns_servers ?? '—' }}</td>
                            <td class="text-xs">{{ subnet.dhcp_enabled ? `Oui${subnet.dhcp_range ? ` (${subnet.dhcp_range})` : ''}` : 'Non' }}</td>
                            <td>{{ subnet.interfaces_count }}</td>
                            <td class="text-right whitespace-nowrap">
                                <template v-if="can.edit">
                                    <button type="button" class="btn-ghost btn-sm" @click="openSubnet(subnet)"><Pencil class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Modifier {{ subnet.cidr }}</span></button>
                                    <button type="button" class="btn-ghost btn-sm text-red-700" @click="toDelete = { type: 'subnet', id: subnet.id, label: subnet.cidr }">
                                        <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer {{ subnet.cidr }}</span>
                                    </button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else :icon="Network" title="Aucun sous-réseau" description="Documentez le plan d'adressage : réseaux, passerelles, DHCP et VLAN." />
        </section>

        <section class="card" aria-labelledby="vlans-title">
            <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 id="vlans-title" class="text-sm font-semibold text-navy-900">VLAN</h2>
                <button v-if="can.edit" type="button" class="btn-secondary btn-sm" @click="openVlan(null)"><Plus class="h-4 w-4" aria-hidden="true" /> VLAN</button>
            </header>
            <div v-if="vlans.length" class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th scope="col">Numéro</th>
                            <th scope="col">Nom</th>
                            <th scope="col">Site</th>
                            <th scope="col">Sous-réseaux</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="vlan in vlans" :key="vlan.id">
                            <td class="font-mono">{{ vlan.number }}</td>
                            <td>
                                {{ vlan.name }}
                                <p v-if="vlan.description" class="text-xs text-slate-500">{{ vlan.description }}</p>
                            </td>
                            <td>{{ vlan.site?.name ?? 'Tous' }}</td>
                            <td>{{ vlan.subnets_count }}</td>
                            <td class="text-right whitespace-nowrap">
                                <template v-if="can.edit">
                                    <button type="button" class="btn-ghost btn-sm" @click="openVlan(vlan)"><Pencil class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Modifier le VLAN {{ vlan.number }}</span></button>
                                    <button type="button" class="btn-ghost btn-sm text-red-700" @click="toDelete = { type: 'vlan', id: vlan.id, label: `VLAN ${vlan.number}` }">
                                        <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer le VLAN {{ vlan.number }}</span>
                                    </button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="px-4 py-4 text-sm text-slate-500">Aucun VLAN.</p>
        </section>
    </div>

    <Modal :show="vlanModal" :title="editingVlan ? `Modifier le VLAN ${editingVlan.number}` : 'Nouveau VLAN'" @close="vlanModal = false">
        <form id="vlan-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" novalidate @submit.prevent="saveVlan">
            <FormField id="vlan_number" label="Numéro (1 à 4094)" :error="vlanForm.errors.number" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="vlan_number" v-model="vlanForm.number" type="number" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <FormField id="vlan_name" label="Nom" :error="vlanForm.errors.name" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="vlan_name" v-model="vlanForm.name" :described-by="describedBy" :invalid="invalid" required />
                </template>
            </FormField>
            <FormField id="vlan_site" label="Site" :error="vlanForm.errors.site_id" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="vlan_site" v-model="vlanForm.site_id" :options="sites" placeholder="Tous les sites" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="vlan_description" label="Description" :error="vlanForm.errors.description" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="vlan_description" v-model="vlanForm.description" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="vlanModal = false">Annuler</button>
            <button type="submit" form="vlan-form" class="btn-primary" :disabled="vlanForm.processing">Enregistrer</button>
        </template>
    </Modal>

    <Modal :show="subnetModal" :title="editingSubnet ? `Modifier ${editingSubnet.cidr}` : 'Nouveau sous-réseau'" size="lg" @close="subnetModal = false">
        <form id="subnet-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" novalidate @submit.prevent="saveSubnet">
            <FormField id="subnet_cidr" label="Réseau (CIDR)" :error="subnetForm.errors.cidr" hint="Ex. 192.168.10.0/24" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="subnet_cidr" v-model="subnetForm.cidr" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <FormField id="subnet_name" label="Nom" :error="subnetForm.errors.name" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="subnet_name" v-model="subnetForm.name" :described-by="describedBy" :invalid="invalid" required />
                </template>
            </FormField>
            <FormField id="subnet_gateway" label="Passerelle" :error="subnetForm.errors.gateway">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="subnet_gateway" v-model="subnetForm.gateway" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="subnet_dns" label="Serveurs DNS" :error="subnetForm.errors.dns_servers">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="subnet_dns" v-model="subnetForm.dns_servers" placeholder="192.168.10.1, 1.1.1.1" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="subnet_vlan" label="VLAN" :error="subnetForm.errors.vlan_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="subnet_vlan" v-model="subnetForm.vlan_id" :options="vlanOptions" placeholder="— Aucun —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="subnet_site" label="Site" :error="subnetForm.errors.site_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="subnet_site" v-model="subnetForm.site_id" :options="sites" placeholder="— Aucun —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <CheckboxInput id="subnet_dhcp" v-model="subnetForm.dhcp_enabled" label="DHCP actif" class="self-end" />
            <FormField id="subnet_dhcp_range" label="Plage DHCP" :error="subnetForm.errors.dhcp_range">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="subnet_dhcp_range" v-model="subnetForm.dhcp_range" placeholder=".100 – .200" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="subnet_description" label="Description" :error="subnetForm.errors.description" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="subnet_description" v-model="subnetForm.description" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="subnetModal = false">Annuler</button>
            <button type="submit" form="subnet-form" class="btn-primary" :disabled="subnetForm.processing">Enregistrer</button>
        </template>
    </Modal>

    <ConfirmDialog
        :show="toDelete !== null"
        title="Confirmer la suppression"
        :message="`Supprimer ${toDelete?.label} ? Les adresses des équipements sont conservées.`"
        confirm-label="Supprimer"
        danger
        @close="toDelete = null"
        @confirm="destroy"
    />
</template>
