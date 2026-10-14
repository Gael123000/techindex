<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Eye, EyeOff, SlidersHorizontal, WandSparkles } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import { defaultPasswordOptions, generatePassword, passwordStrength } from '@/lib/password';
import type { CredentialRow, Option } from '@/types';
import CheckboxInput from './CheckboxInput.vue';
import FormField from './FormField.vue';
import Modal from './Modal.vue';
import SelectInput from './SelectInput.vue';
import TextArea from './TextArea.vue';
import TextInput from './TextInput.vue';

/**
 * Création / modification d'un accès. Le champ secret est en écriture
 * seule : il n'est jamais prérempli avec la valeur existante.
 *
 * Le même formulaire sert sur les fiches (équipement, service, accès) et dans
 * le gestionnaire de mots de passe, où il propose en plus le dossier de rangement.
 */
const props = defineProps<{
    show: boolean;
    clientId: number;
    credential: CredentialRow | null;
    types: Option[];
    equipment?: Option<number>[];
    services?: Option<number>[];
    folders?: Option<number>[];
    canWriteSecrets: boolean;
    fixedEquipmentId?: number | null;
    fixedServiceId?: number | null;
    defaults?: { equipment_id?: number | null; service_id?: number | null; folder_id?: number | null };
}>();

const emit = defineEmits<{ close: [] }>();

// Exemple affiché dans le champ URL.
const URL_PLACEHOLDER = 'https://';
const STRENGTH_COLORS = ['bg-red-500', 'bg-amber-500', 'bg-turquoise-500', 'bg-green-600'];

const form = useForm({
    label: '',
    access_type: 'web',
    username: '',
    secret: '',
    clear_secret: false,
    url: '',
    host: '',
    port: null as number | null,
    notes: '',
    equipment_id: null as number | null,
    service_id: null as number | null,
    folder_id: null as number | null,
});

const showSecret = ref(false);
const showGenerator = ref(false);
const generator = reactive({ ...defaultPasswordOptions });
const strength = computed(() => passwordStrength(form.secret));

watch(
    () => props.show,
    (show) => {
        if (!show) {
            return;
        }

        form.clearErrors();
        const c = props.credential;
        form.label = c?.label ?? '';
        form.access_type = c?.access_type ?? 'web';
        form.username = c?.username ?? '';
        form.secret = '';
        form.clear_secret = false;
        form.url = c?.url ?? '';
        form.host = c?.host ?? '';
        form.port = c?.port ?? null;
        form.notes = c?.notes ?? '';
        form.equipment_id = c ? (c.equipment?.id ?? null) : (props.fixedEquipmentId ?? props.defaults?.equipment_id ?? null);
        form.service_id = c ? (c.service?.id ?? null) : (props.fixedServiceId ?? props.defaults?.service_id ?? null);
        form.folder_id = c ? (c.folder?.id ?? null) : (props.defaults?.folder_id ?? null);
        showSecret.value = false;
        showGenerator.value = false;
    },
);

const title = computed(() => (props.credential ? `Modifier l'accès « ${props.credential.label} »` : 'Nouvel accès'));

function generate(): void {
    form.secret = generatePassword(generator);
    form.clear_secret = false;
    showSecret.value = true;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onFinish: () => {
            form.secret = '';
            showSecret.value = false;
        },
    };

    // Hors du gestionnaire, le dossier n'est pas modifiable : il n'est pas envoyé.
    form.transform((data) => {
        if (props.folders !== undefined) {
            return data;
        }

        const payload: Partial<typeof data> = { ...data };
        delete payload.folder_id;

        return payload;
    });

    if (props.credential) {
        form.put(route('clients.credentials.update', [props.clientId, props.credential.id]), options);
    } else {
        form.post(route('clients.credentials.store', props.clientId), options);
    }
}
</script>

<template>
    <Modal :show="show" :title="title" size="lg" @close="emit('close')">
        <form id="credential-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" autocomplete="off" @submit.prevent="submit">
            <FormField id="cred_label" label="Libellé" :error="form.errors.label" required>
                <template #default="{ describedBy, invalid }">
                    <TextInput id="cred_label" v-model="form.label" :described-by="describedBy" :invalid="invalid" required autofocus />
                </template>
            </FormField>
            <FormField id="cred_type" label="Type d'accès" :error="form.errors.access_type" required>
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="cred_type" v-model="form.access_type" :options="types" :described-by="describedBy" :invalid="invalid" required />
                </template>
            </FormField>
            <FormField id="cred_username" label="Nom d'utilisateur" :error="form.errors.username">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="cred_username" v-model="form.username" autocomplete="off" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>

            <FormField
                v-if="canWriteSecrets"
                id="cred_secret"
                label="Mot de passe ou secret"
                :error="form.errors.secret"
                :hint="credential?.has_secret ? 'Laissez vide pour conserver le secret actuel.' : 'Chiffré avant enregistrement ; jamais réaffiché sans demande explicite.'"
            >
                <template #default="{ describedBy, invalid }">
                    <div class="flex gap-1.5">
                        <TextInput
                            id="cred_secret"
                            v-model="form.secret"
                            :type="showSecret ? 'text' : 'password'"
                            autocomplete="new-password"
                            :described-by="describedBy"
                            :invalid="invalid"
                        />
                        <button type="button" class="btn-secondary shrink-0 px-2.5" :aria-pressed="showSecret" @click="showSecret = !showSecret">
                            <EyeOff v-if="showSecret" class="h-4 w-4" aria-hidden="true" />
                            <Eye v-else class="h-4 w-4" aria-hidden="true" />
                            <span class="sr-only">{{ showSecret ? 'Masquer' : 'Afficher' }} la saisie</span>
                        </button>
                        <button type="button" class="btn-secondary shrink-0 px-2.5" title="Générer un mot de passe" @click="generate">
                            <WandSparkles class="h-4 w-4" aria-hidden="true" />
                            <span class="sr-only">Générer un mot de passe</span>
                        </button>
                        <button
                            type="button"
                            class="btn-ghost shrink-0 px-2"
                            :aria-expanded="showGenerator"
                            title="Options du générateur"
                            @click="showGenerator = !showGenerator"
                        >
                            <SlidersHorizontal class="h-4 w-4" aria-hidden="true" />
                            <span class="sr-only">Options du générateur</span>
                        </button>
                    </div>
                    <div v-if="form.secret" class="mt-1.5 flex items-center gap-2" aria-live="polite">
                        <div class="flex h-1.5 flex-1 gap-0.5" aria-hidden="true">
                            <span
                                v-for="index in 4"
                                :key="index"
                                class="flex-1 rounded-full"
                                :class="index - 1 <= strength.level ? STRENGTH_COLORS[strength.level] : 'bg-slate-200'"
                            />
                        </div>
                        <span class="text-xs text-slate-600">{{ strength.label }} · {{ strength.bits }} bits</span>
                    </div>
                </template>
            </FormField>
            <p v-else class="self-end rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                Vous n'avez pas le droit de définir les secrets de ce client : seules les informations descriptives sont modifiables.
            </p>

            <div v-if="canWriteSecrets && showGenerator" class="rounded-md border border-slate-200 bg-slate-50 p-3 sm:col-span-2">
                <p class="mb-2 text-xs font-semibold text-slate-700">Générateur de mot de passe</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label for="gen_length" class="text-xs text-slate-700 sm:col-span-3">
                        Longueur : <strong>{{ generator.length }}</strong> caractères
                        <input id="gen_length" v-model.number="generator.length" type="range" min="12" max="64" class="mt-1 block w-full accent-turquoise-600" />
                    </label>
                    <CheckboxInput id="gen_upper" v-model="generator.upper" label="Majuscules" />
                    <CheckboxInput id="gen_digits" v-model="generator.digits" label="Chiffres" />
                    <CheckboxInput id="gen_symbols" v-model="generator.symbols" label="Symboles" />
                    <CheckboxInput id="gen_readable" v-model="generator.readable" label="Sans caractères ambigus" description="Exclut 0/O, 1/l/I…" class="sm:col-span-2" />
                    <button type="button" class="btn-secondary btn-sm self-start" @click="generate"><WandSparkles class="h-4 w-4" aria-hidden="true" /> Générer</button>
                </div>
            </div>

            <div v-if="canWriteSecrets && credential?.has_secret" class="sm:col-span-2">
                <CheckboxInput id="cred_clear" v-model="form.clear_secret" label="Supprimer le secret enregistré" />
            </div>

            <FormField id="cred_url" label="URL" :error="form.errors.url" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="cred_url" v-model="form.url" type="url" :placeholder="URL_PLACEHOLDER" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="cred_host" label="Hôte" :error="form.errors.host">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="cred_host" v-model="form.host" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField id="cred_port" label="Port" :error="form.errors.port">
                <template #default="{ describedBy, invalid }">
                    <TextInput id="cred_port" v-model="form.port" type="number" inputmode="numeric" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>

            <FormField v-if="equipment && fixedEquipmentId === undefined" id="cred_equipment" label="Équipement" :error="form.errors.equipment_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="cred_equipment" v-model="form.equipment_id" :options="equipment" placeholder="— Aucun —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField v-if="services && fixedServiceId === undefined" id="cred_service" label="Service" :error="form.errors.service_id">
                <template #default="{ describedBy, invalid }">
                    <SelectInput id="cred_service" v-model="form.service_id" :options="services" placeholder="— Aucun —" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
            <FormField
                v-if="folders"
                id="cred_folder"
                label="Dossier"
                :error="form.errors.folder_id"
                hint="Rangement dans le gestionnaire de mots de passe."
                class="sm:col-span-2"
            >
                <template #default="{ describedBy, invalid }">
                    <SelectInput
                        id="cred_folder"
                        v-model="form.folder_id"
                        :options="folders"
                        placeholder="— Racine du client —"
                        :described-by="describedBy"
                        :invalid="invalid"
                    />
                </template>
            </FormField>

            <FormField id="cred_notes" label="Notes d'utilisation" :error="form.errors.notes" class="sm:col-span-2">
                <template #default="{ describedBy, invalid }">
                    <TextArea id="cred_notes" v-model="form.notes" :described-by="describedBy" :invalid="invalid" />
                </template>
            </FormField>
        </form>
        <template #footer>
            <button type="button" class="btn-secondary" @click="emit('close')">Annuler</button>
            <button type="submit" form="credential-form" class="btn-primary" :disabled="form.processing">Enregistrer</button>
        </template>
    </Modal>
</template>
