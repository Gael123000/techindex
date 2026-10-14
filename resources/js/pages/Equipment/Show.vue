<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { Archive, ExternalLink, FilePlus2, Link2, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { route } from 'ziggy-js';
import AttachmentsPanel from '@/components/AttachmentsPanel.vue';
import ClientHeader from '@/components/ClientHeader.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CredentialsTable from '@/components/CredentialsTable.vue';
import DeleteResource from '@/components/DeleteResource.vue';
import DynamicIcon from '@/components/DynamicIcon.vue';
import ExpiryBadge from '@/components/ExpiryBadge.vue';
import FormField from '@/components/FormField.vue';
import HistoryList from '@/components/HistoryList.vue';
import Modal from '@/components/Modal.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TagList from '@/components/TagList.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatDateTime } from '@/lib/format';
import type { AttachmentsPayload, AuditEntry, ClientContext, CredentialRow, Option, TagRef } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    context: ClientContext;
    equipment: {
        id: number;
        name: string;
        hostname: string | null;
        manufacturer: string | null;
        model: string | null;
        serial_number: string | null;
        inventory_number: string | null;
        location: string | null;
        os_name: string | null;
        os_version: string | null;
        firmware_version: string | null;
        role: string | null;
        description: string | null;
        supplier: string | null;
        admin_url: string | null;
        notes: string | null;
        status: string;
        status_label: string;
        purchase_date: string | null;
        commissioning_date: string | null;
        warranty_expires_at: string | null;
        end_of_life_at: string | null;
        type: { id: number; name: string; icon: string } | null;
        site: { id: number; name: string } | null;
        tags: TagRef[];
        interfaces: Array<{ id: number; label: string | null; ip_address: string | null; mac_address: string | null; is_primary: boolean; notes: string | null; subnet: { id: number; name: string; cidr: string } | null }>;
        created_at: string;
        updated_at: string;
        creator: { id: number; name: string } | null;
        editor: { id: number; name: string } | null;
    };
    customFields: Array<{ label: string; type: string; value: string | null }>;
    links: Array<{ id: number; label: string; other: { id: number; name: string; hostname: string | null } | null; notes: string | null }>;
    services: Array<{ id: number; name: string; category: string; status: string }>;
    licenses: Array<{ id: number; name: string; expires_at: string | null; status: string }>;
    certificates: Array<{ id: number; name: string; common_name: string | null; expires_at: string | null; status: string }>;
    documents: Array<{ id: number; title: string; status: string }>;
    credentials: CredentialRow[];
    accessTypes: Option[];
    attachments: AttachmentsPayload;
    history: AuditEntry[];
    linkOptions: { types: Option[]; equipment: Option<number>[] };
    can: { update: boolean; delete: boolean; write_secrets: boolean };
}>();

const confirmArchive = ref(false);
const showLinkForm = ref(false);
const linkToDelete = ref<number | null>(null);

const linkForm = useForm({
    source_equipment_id: props.equipment.id,
    target_equipment_id: null as number | null,
    type: 'connected_to',
    notes: '',
    direction: 'outgoing' as 'outgoing' | 'incoming',
});

function saveLink(): void {
    linkForm
        .transform((data) => ({
            source_equipment_id: data.direction === 'outgoing' ? props.equipment.id : data.target_equipment_id,
            target_equipment_id: data.direction === 'outgoing' ? data.target_equipment_id : props.equipment.id,
            type: data.type,
            notes: data.notes,
        }))
        .post(route('clients.equipment-links.store', props.context.id), {
            preserveScroll: true,
            onSuccess: () => {
                showLinkForm.value = false;
                linkForm.reset();
            },
        });
}

function deleteLink(): void {
    if (linkToDelete.value !== null) {
        router.delete(route('clients.equipment-links.destroy', [props.context.id, linkToDelete.value]), {
            preserveScroll: true,
            onFinish: () => (linkToDelete.value = null),
        });
    }
}

function archive(): void {
    router.post(route('clients.equipment.archive', [props.context.id, props.equipment.id]), {}, { onFinish: () => (confirmArchive.value = false) });
}

const details: Array<{ label: string; value: () => string | null }> = [
    { label: 'Type', value: () => props.equipment.type?.name ?? null },
    { label: 'Nom d\'hôte', value: () => props.equipment.hostname },
    { label: 'Fabricant / modèle', value: () => [props.equipment.manufacturer, props.equipment.model].filter(Boolean).join(' ') || null },
    { label: 'Numéro de série', value: () => props.equipment.serial_number },
    { label: 'Référence d\'inventaire', value: () => props.equipment.inventory_number },
    { label: 'Emplacement', value: () => props.equipment.location },
    { label: 'Rôle', value: () => props.equipment.role },
    { label: 'Système', value: () => [props.equipment.os_name, props.equipment.os_version].filter(Boolean).join(' ') || null },
    { label: 'Firmware', value: () => props.equipment.firmware_version },
    { label: 'Fournisseur', value: () => props.equipment.supplier },
    { label: 'Date d\'achat', value: () => (props.equipment.purchase_date ? formatDate(props.equipment.purchase_date) : null) },
    { label: 'Mise en service', value: () => (props.equipment.commissioning_date ? formatDate(props.equipment.commissioning_date) : null) },
];
</script>

<template>
    <ClientHeader
        :context="context"
        active="equipment"
        :title="equipment.name"
        :description="[equipment.type?.name, equipment.site?.name].filter(Boolean).join(' · ')"
        :breadcrumbs="[{ label: 'Équipements', href: route('clients.equipment.index', context.id) }, { label: equipment.name }]"
    >
        <template #actions>
            <Link v-if="can.update" :href="route('clients.documents.create', { client: context.id, equipment: equipment.id })" class="btn-secondary">
                <FilePlus2 class="h-4 w-4" aria-hidden="true" /> Rédiger une fiche
            </Link>
            <Link v-if="can.update" :href="route('clients.equipment.edit', [context.id, equipment.id])" class="btn-secondary"><Pencil class="h-4 w-4" aria-hidden="true" /> Modifier</Link>
            <button v-if="can.update && equipment.status !== 'archived'" type="button" class="btn-secondary" @click="confirmArchive = true">
                <Archive class="h-4 w-4" aria-hidden="true" /> Archiver
            </button>
            <DeleteResource v-if="can.delete" :url="route('clients.equipment.destroy', [context.id, equipment.id])" :name="equipment.name" />
        </template>
    </ClientHeader>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <section class="card p-5" aria-label="Caractéristiques">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <DynamicIcon :name="equipment.type?.icon" class="h-5 w-5 text-navy-700" />
                    <StatusBadge :status="equipment.status" :label="equipment.status_label" />
                    <TagList :tags="equipment.tags" />
                </div>
                <dl class="dl-grid">
                    <div v-for="item in details" :key="item.label">
                        <dt>{{ item.label }}</dt>
                        <dd>{{ item.value() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Fin de garantie</dt>
                        <dd><ExpiryBadge :date="equipment.warranty_expires_at" /></dd>
                    </div>
                    <div>
                        <dt>Fin de vie</dt>
                        <dd><ExpiryBadge :date="equipment.end_of_life_at" :warn-days="90" /></dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt>URL d'administration</dt>
                        <dd>
                            <a v-if="equipment.admin_url" :href="equipment.admin_url" target="_blank" rel="noopener noreferrer" class="link inline-flex items-center gap-1 break-all">
                                {{ equipment.admin_url }} <ExternalLink class="h-3 w-3" aria-hidden="true" />
                            </a>
                            <template v-else>—</template>
                        </dd>
                    </div>
                </dl>
                <p v-if="equipment.description" class="mt-4 text-sm whitespace-pre-line text-slate-700">{{ equipment.description }}</p>
                <div v-if="equipment.notes" class="mt-4 rounded-md bg-slate-50 p-3 text-sm whitespace-pre-line text-slate-700">{{ equipment.notes }}</div>
                <p class="mt-4 text-xs text-slate-400">
                    Créé le {{ formatDateTime(equipment.created_at) }}<template v-if="equipment.creator"> par {{ equipment.creator.name }}</template> · modifié le
                    {{ formatDateTime(equipment.updated_at) }}<template v-if="equipment.editor"> par {{ equipment.editor.name }}</template>
                </p>
            </section>

            <section class="card" aria-labelledby="eq-net">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="eq-net" class="text-sm font-semibold text-navy-900">Adresses réseau</h2></header>
                <div v-if="equipment.interfaces.length" class="overflow-x-auto">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th scope="col">Interface</th>
                                <th scope="col">Adresse IP</th>
                                <th scope="col">Adresse MAC</th>
                                <th scope="col">Sous-réseau</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="item in equipment.interfaces" :key="item.id">
                                <td>{{ item.label ?? '—' }} <span v-if="item.is_primary" class="text-xs text-turquoise-700">(principale)</span></td>
                                <td class="font-mono text-xs">{{ item.ip_address ?? '—' }}</td>
                                <td class="font-mono text-xs">{{ item.mac_address ?? '—' }}</td>
                                <td class="text-xs">{{ item.subnet ? `${item.subnet.name} (${item.subnet.cidr})` : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="px-4 py-4 text-sm text-slate-500">Aucune adresse renseignée.</p>
            </section>

            <section v-if="customFields.length" class="card p-5" aria-labelledby="eq-custom">
                <h2 id="eq-custom" class="mb-3 text-sm font-semibold text-navy-900">Champs personnalisés</h2>
                <dl class="dl-grid">
                    <div v-for="field in customFields" :key="field.label">
                        <dt>{{ field.label }}</dt>
                        <dd>
                            <a v-if="field.type === 'url' && field.value" :href="field.value" target="_blank" rel="noopener noreferrer" class="link break-all">{{ field.value }}</a>
                            <template v-else>{{ field.type === 'date' && field.value ? formatDate(field.value) : (field.value ?? '—') }}</template>
                        </dd>
                    </div>
                </dl>
            </section>

            <CredentialsTable
                :client-id="context.id"
                :credentials="credentials"
                :types="accessTypes"
                :can-edit="can.update"
                :can-write-secrets="can.write_secrets"
                :fixed-equipment-id="equipment.id"
                :show-target="false"
                :vault-url="route('vault.clients.show', { client: context.id, equipment: equipment.id })"
            />

            <section class="card" aria-labelledby="eq-history">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="eq-history" class="text-sm font-semibold text-navy-900">Historique</h2></header>
                <HistoryList :entries="history" />
            </section>
        </div>

        <div class="space-y-5">
            <section class="card" aria-labelledby="eq-links">
                <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h2 id="eq-links" class="flex items-center gap-2 text-sm font-semibold text-navy-900"><Link2 class="h-4 w-4 text-turquoise-700" aria-hidden="true" /> Relations</h2>
                    <button v-if="can.update" type="button" class="btn-ghost btn-sm" @click="showLinkForm = true"><Plus class="h-4 w-4" aria-hidden="true" /> Ajouter</button>
                </header>
                <ul v-if="links.length" class="divide-y divide-slate-100 text-sm">
                    <li v-for="link in links" :key="link.id" class="flex items-start justify-between gap-2 px-4 py-2.5">
                        <div>
                            <span class="text-slate-500">{{ link.label }}</span>
                            <Link v-if="link.other" :href="route('clients.equipment.show', [context.id, link.other.id])" class="link ml-1">{{ link.other.name }}</Link>
                            <p v-if="link.notes" class="text-xs text-slate-500">{{ link.notes }}</p>
                        </div>
                        <button v-if="can.update" type="button" class="btn-ghost btn-sm text-red-700" @click="linkToDelete = link.id">
                            <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">Supprimer la relation</span>
                        </button>
                    </li>
                </ul>
                <p v-else class="px-4 py-4 text-sm text-slate-500">Aucune relation (hôte, pare-feu, onduleur…).</p>
            </section>

            <section class="card" aria-labelledby="eq-related">
                <header class="border-b border-slate-200 px-4 py-3"><h2 id="eq-related" class="text-sm font-semibold text-navy-900">Éléments liés</h2></header>
                <div class="space-y-3 px-4 py-3 text-sm">
                    <div>
                        <h3 class="text-xs font-medium tracking-wide text-slate-500 uppercase">Services</h3>
                        <ul v-if="services.length" class="mt-1 space-y-0.5">
                            <li v-for="item in services" :key="item.id">
                                <Link :href="route('clients.services.show', [context.id, item.id])" class="link">{{ item.name }}</Link>
                                <span class="text-xs text-slate-500"> · {{ item.category }}</span>
                            </li>
                        </ul>
                        <p v-else class="text-slate-500">—</p>
                    </div>
                    <div>
                        <h3 class="text-xs font-medium tracking-wide text-slate-500 uppercase">Licences</h3>
                        <ul v-if="licenses.length" class="mt-1 space-y-0.5">
                            <li v-for="item in licenses" :key="item.id" class="flex items-center justify-between gap-2">
                                <Link :href="route('clients.licenses.show', [context.id, item.id])" class="link">{{ item.name }}</Link>
                                <ExpiryBadge :date="item.expires_at" />
                            </li>
                        </ul>
                        <p v-else class="text-slate-500">—</p>
                    </div>
                    <div>
                        <h3 class="text-xs font-medium tracking-wide text-slate-500 uppercase">Certificats</h3>
                        <ul v-if="certificates.length" class="mt-1 space-y-0.5">
                            <li v-for="item in certificates" :key="item.id" class="flex items-center justify-between gap-2">
                                <Link :href="route('clients.certificates.show', [context.id, item.id])" class="link">{{ item.name }}</Link>
                                <ExpiryBadge :date="item.expires_at" :warn-days="30" />
                            </li>
                        </ul>
                        <p v-else class="text-slate-500">—</p>
                    </div>
                    <div>
                        <h3 class="text-xs font-medium tracking-wide text-slate-500 uppercase">Documentation</h3>
                        <ul v-if="documents.length" class="mt-1 space-y-0.5">
                            <li v-for="item in documents" :key="item.id">
                                <Link :href="route('clients.documents.show', [context.id, item.id])" class="link">{{ item.title }}</Link>
                            </li>
                        </ul>
                        <p v-else class="text-slate-500">—</p>
                    </div>
                </div>
            </section>

            <AttachmentsPanel :attachments="attachments" />
        </div>
    </div>

    <Modal :show="showLinkForm" title="Ajouter une relation" @close="showLinkForm = false">
        <form id="link-form" class="space-y-4" @submit.prevent="saveLink">
            <fieldset>
                <legend class="mb-1 text-sm font-medium text-slate-700">Sens</legend>
                <label class="mr-4 inline-flex items-center gap-1.5 text-sm"><input v-model="linkForm.direction" type="radio" value="outgoing" /> {{ equipment.name }} → autre équipement</label>
                <label class="inline-flex items-center gap-1.5 text-sm"><input v-model="linkForm.direction" type="radio" value="incoming" /> autre équipement → {{ equipment.name }}</label>
            </fieldset>
            <FormField id="link-type" label="Relation" :error="linkForm.errors.type" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="link-type" v-model="linkForm.type" :options="linkOptions.types" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="link-target" label="Autre équipement" :error="linkForm.errors.target_equipment_id || linkForm.errors.source_equipment_id" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="link-target" v-model="linkForm.target_equipment_id" :options="linkOptions.equipment" placeholder="— Choisir —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="link-notes" label="Précision" :error="linkForm.errors.notes">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="link-notes" v-model="linkForm.notes" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="showLinkForm = false">Annuler</button>
            <button type="submit" form="link-form" class="btn-primary" :disabled="linkForm.processing || !linkForm.target_equipment_id">Ajouter</button>
        </template>
    </Modal>

    <ConfirmDialog :show="linkToDelete !== null" title="Supprimer la relation" message="La relation sera supprimée (les équipements ne sont pas modifiés)." confirm-label="Supprimer" danger @close="linkToDelete = null" @confirm="deleteLink" />
    <ConfirmDialog
        :show="confirmArchive"
        title="Archiver l'équipement"
        :message="`« ${equipment.name} » sera conservé avec son historique mais n'apparaîtra plus dans l'inventaire courant.`"
        confirm-label="Archiver"
        @close="confirmArchive = false"
        @confirm="archive"
    />
</template>
