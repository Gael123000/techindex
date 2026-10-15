<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Database, Lock, Plus, Settings2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import CheckboxInput from '@/components/CheckboxInput.vue';
import DynamicIcon from '@/components/DynamicIcon.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormField from '@/components/FormField.vue';
import Modal from '@/components/Modal.vue';
import PageHeader from '@/components/PageHeader.vue';
import SelectInput from '@/components/SelectInput.vue';
import TextArea from '@/components/TextArea.vue';
import TextInput from '@/components/TextInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { suggestIdentifier } from '@/lib/records';
import type { Option } from '@/types';

defineOptions({ layout: AppLayout });

interface TypeRow {
    id: number;
    key: string;
    name: string;
    plural_name: string;
    description: string | null;
    icon: string;
    is_system: boolean;
    is_active: boolean;
    table_name: string;
    tab_after: string | null;
    fields_count: number;
    builtin_count: number;
    records_count: number | null;
}

const props = defineProps<{ types: TypeRow[]; icons: string[]; tabs: Option[] }>();

const systemTypes = computed(() => props.types.filter((type) => type.is_system));
const customTypes = computed(() => props.types.filter((type) => !type.is_system));
const tabLabels = computed(() => Object.fromEntries(props.tabs.map((tab) => [tab.value, tab.label])));

const showCreate = ref(false);
const tableEdited = ref(false);
const form = useForm({
    name: '',
    plural_name: '',
    description: '',
    icon: 'clipboard-list',
    title_label: 'Nom',
    table: '',
    tab_after: 'documents',
    settings: { has_site: true, has_tags: true, has_attachments: true },
});

// Nom de table proposé à partir du nom au pluriel, tant qu'il n'a pas été modifié.
watch(
    () => form.plural_name,
    (value) => {
        if (!tableEdited.value) {
            form.table = suggestIdentifier(value, 'fiche');
        }
    },
);

function open(): void {
    form.reset();
    form.clearErrors();
    tableEdited.value = false;
    showCreate.value = true;
}

function create(): void {
    form.post(route('admin.record-types.store'), { onSuccess: () => (showCreate.value = false) });
}
</script>

<template>
    <PageHeader
        title="Fiches et champs"
        description="Créez vos propres fiches (un onglet par fiche dans chaque client) et ajoutez des champs aux fiches existantes. Chaque fiche dispose de sa propre table dans la base de données."
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Fiches et champs' }]"
    >
        <template #actions>
            <button type="button" class="btn-primary" @click="open"><Plus class="h-4 w-4" aria-hidden="true" /> Nouvelle fiche</button>
        </template>
    </PageHeader>

    <section class="mb-8" aria-labelledby="custom-types">
        <h2 id="custom-types" class="mb-3 text-sm font-semibold tracking-wide text-slate-500 uppercase">Fiches créées</h2>
        <ul v-if="customTypes.length" class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            <li v-for="type in customTypes" :key="type.id">
                <Link :href="route('admin.record-types.show', type.id)" class="card flex h-full items-start gap-3 p-4 hover:border-turquoise-300 hover:shadow-md">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-turquoise-50 text-turquoise-700">
                        <DynamicIcon :name="type.icon" class="h-5 w-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2">
                            <span class="truncate font-semibold text-navy-900">{{ type.plural_name }}</span>
                            <Badge v-if="!type.is_active" color="slate">Désactivée</Badge>
                        </span>
                        <span class="mt-0.5 block text-xs text-slate-500">
                            {{ type.fields_count }} champ(s) · {{ type.records_count }} enregistrement(s) · onglet après « {{ tabLabels[type.tab_after ?? 'documents'] }} »
                        </span>
                        <span class="mt-1 inline-flex items-center gap-1 font-mono text-xs text-slate-500"><Database class="h-3 w-3" aria-hidden="true" />{{ type.table_name }}</span>
                    </span>
                </Link>
            </li>
        </ul>
        <div v-else class="card">
            <EmptyState
                :icon="Settings2"
                title="Aucune fiche créée"
                description="Exemples : contrats de maintenance, interventions, matériel prêté, abonnements téléphoniques, caméras, badges d'accès…"
            >
                <button type="button" class="btn-primary" @click="open"><Plus class="h-4 w-4" aria-hidden="true" /> Créer une fiche</button>
            </EmptyState>
        </div>
    </section>

    <section aria-labelledby="system-types">
        <h2 id="system-types" class="mb-1 text-sm font-semibold tracking-wide text-slate-500 uppercase">Fiches par défaut</h2>
        <p class="mb-3 text-sm text-slate-600">Leurs champs d'origine sont verrouillés ; vous pouvez leur ajouter des champs et des sections.</p>
        <ul class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            <li v-for="type in systemTypes" :key="type.id">
                <Link :href="route('admin.record-types.show', type.id)" class="card flex h-full items-center gap-3 p-4 hover:border-turquoise-300 hover:shadow-md">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-600">
                        <DynamicIcon :name="type.icon" class="h-5 w-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold text-navy-900">{{ type.plural_name }}</span>
                        <span class="flex items-center gap-1 text-xs text-slate-500">
                            <Lock class="h-3 w-3" aria-hidden="true" /> {{ type.builtin_count }} champs d'origine
                            <template v-if="type.fields_count"> · {{ type.fields_count }} ajouté(s)</template>
                        </span>
                    </span>
                    <span class="text-xs font-medium text-turquoise-700">Personnaliser</span>
                </Link>
            </li>
        </ul>
    </section>

    <Modal :show="showCreate" title="Nouvelle fiche" size="lg" @close="showCreate = false">
        <form id="type-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" novalidate @submit.prevent="create">
            <FormField id="type_name" label="Nom d'un élément" :error="form.errors.name" hint="Au singulier, ex. Contrat de maintenance" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="type_name" v-model="form.name" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <FormField id="type_plural" label="Nom de l'onglet" :error="form.errors.plural_name" hint="Au pluriel, ex. Contrats de maintenance" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="type_plural" v-model="form.plural_name" :described-by="describedBy" :invalid="invalid" required />
                </template>
            </FormField>
            <FormField id="type_title" label="Libellé du champ principal" :error="form.errors.title_label" hint="Titre de chaque élément, ex. Référence du contrat">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="type_title" v-model="form.title_label" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="type_tab" label="Onglet placé après" :error="form.errors.tab_after">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="type_tab" v-model="form.tab_after" :options="tabs" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField
                id="type_table"
                label="Table dans la base de données"
                :error="form.errors.table"
                hint="Lettres minuscules, chiffres et « _ ». Le nom est figé une fois la fiche créée."
                class="sm:col-span-2"
                required
            >
                <template #default="{ describedBy, invalid }">
                    <div class="flex items-stretch">
                        <span class="inline-flex items-center rounded-l-md border border-r-0 border-slate-300 bg-slate-50 px-2.5 font-mono text-sm text-slate-500">cx_</span>
                        <input
                            id="type_table"
                            v-model="form.table"
                            class="form-input rounded-l-none font-mono"
                            :class="invalid ? 'border-red-500' : ''"
                            maxlength="40"
                            spellcheck="false"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid ? 'true' : undefined"
                            @input="tableEdited = true"
                        />
                    </div>
                </template>
            </FormField>
            <FormField id="type_description" label="Description" :error="form.errors.description" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="type_description" v-model="form.description" :rows="2" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <fieldset class="sm:col-span-2">
                <legend class="mb-1 text-sm font-medium text-slate-700">Icône</legend>
                <div class="flex flex-wrap gap-1.5">
                    <label
                        v-for="icon in icons"
                        :key="icon"
                        class="flex h-9 w-9 cursor-pointer items-center justify-center rounded-md border"
                        :class="form.icon === icon ? 'border-turquoise-600 bg-turquoise-50 text-turquoise-800' : 'border-slate-200 text-slate-600 hover:bg-slate-50'"
                        :title="icon"
                    >
                        <input v-model="form.icon" type="radio" name="icon" :value="icon" class="sr-only" />
                        <DynamicIcon :name="icon" class="h-4 w-4" />
                        <span class="sr-only">{{ icon }}</span>
                    </label>
                </div>
            </fieldset>
            <div class="space-y-2 sm:col-span-2">
                <CheckboxInput id="type_site" v-model="form.settings.has_site" label="Rattachement à un site du client" />
                <CheckboxInput id="type_tags" v-model="form.settings.has_tags" label="Étiquettes" />
                <CheckboxInput id="type_files" v-model="form.settings.has_attachments" label="Pièces jointes" />
            </div>
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="showCreate = false">Annuler</button>
            <button type="submit" form="type-form" class="btn-primary" :disabled="form.processing">Créer la fiche</button>
        </template>
    </Modal>
</template>
