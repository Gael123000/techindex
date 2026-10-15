<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CloudDownload, LoaderCircle, RefreshCw } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import Badge from '@/components/Badge.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { ensurePasswordConfirmed } from '@/composables/usePasswordConfirmation';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatBytes, formatDateTime } from '@/lib/format';
import { HttpError, requestJson } from '@/lib/http';

defineOptions({ layout: AppLayout });

interface ReleaseInfo {
    version: string;
    tag: string;
    commit: string;
    published_at: string | null;
    migrations: boolean;
    notes: string;
    archive: string;
    size: number;
}

interface UpdateState {
    installed: string;
    deployment: 'plesk' | 'docker' | 'manual';
    channel: { enabled: boolean; configured: boolean; repository: string };
    check: { checked_at: string; latest: ReleaseInfo | null; available: boolean; error: string | null } | null;
    can_apply: boolean;
    pending: { version: string; requested_at: string | null; requested_by: string | null } | null;
    status: {
        state: 'idle' | 'queued' | 'running' | 'succeeded' | 'failed';
        version: string | null;
        from: string | null;
        step: string | null;
        message: string | null;
        source: string | null;
        started_at: string | null;
        finished_at: string | null;
        requested_by: string | null;
    };
    log: string[];
}

const props = defineProps<UpdateState>();

const state = ref<UpdateState>({ ...props });
const checking = ref(false);
const confirming = ref(false);
const applying = ref(false);
const actionError = ref<string | null>(null);
const actionInfo = ref<string | null>(null);
const maintenance = ref(false);

watch(
    () => props,
    (value) => {
        state.value = { ...value };
    },
    { deep: true },
);

const latest = computed(() => state.value.check?.latest ?? null);
const busy = computed(() => state.value.pending !== null || ['queued', 'running'].includes(state.value.status.state));
const canInstall = computed(() => state.value.can_apply && state.value.check?.available === true && latest.value !== null && !busy.value);

const deploymentLabel: Record<UpdateState['deployment'], string> = {
    plesk: 'Hébergement Plesk',
    docker: 'Serveur Linux (Docker)',
    manual: 'Poste de développement',
};

// Pas de « // » littéral dans les attributs du gabarit (vue-tsc) : URL construite ici.
const repositoryUrl = computed(() => (state.value.channel.repository ? `https://github.com/${state.value.channel.repository}/releases` : null));

const stateLabels: Record<UpdateState['status']['state'], { label: string; color: string }> = {
    idle: { label: 'Aucune', color: 'slate' },
    queued: { label: 'Programmée', color: 'blue' },
    running: { label: 'En cours', color: 'amber' },
    succeeded: { label: 'Réussie', color: 'green' },
    failed: { label: 'Échec', color: 'red' },
};

async function refresh(): Promise<void> {
    try {
        const next = await requestJson<UpdateState>(route('admin.updates.status'));
        const wasBusy = busy.value || maintenance.value;
        maintenance.value = false;
        state.value = next;

        if (wasBusy && !busy.value) {
            // Fin de la mise à jour : nouvelle version, menu et messages à jour.
            router.reload();
        }
    } catch (exception) {
        // Site en maintenance pendant la mise à jour : on réessaie.
        maintenance.value = exception instanceof HttpError && exception.status === 503;
    }
}

let timer: number | undefined;

watch(
    () => busy.value || maintenance.value,
    (active) => {
        window.clearInterval(timer);
        timer = active ? window.setInterval(refresh, 5000) : undefined;
    },
    { immediate: true },
);

onBeforeUnmount(() => window.clearInterval(timer));

function check(): void {
    actionError.value = null;
    actionInfo.value = null;
    router.post(route('admin.updates.check'), {}, {
        preserveScroll: true,
        onStart: () => (checking.value = true),
        onFinish: () => (checking.value = false),
    });
}

async function install(): Promise<void> {
    if (!latest.value) {
        return;
    }

    applying.value = true;
    actionError.value = null;

    try {
        if (!(await ensurePasswordConfirmed())) {
            return;
        }

        const result = await requestJson<{ message: string }>(route('admin.updates.apply'), { method: 'POST', body: { version: latest.value.version } });
        actionInfo.value = result.message;
        confirming.value = false;
        await refresh();
    } catch (exception) {
        actionError.value = exception instanceof Error ? exception.message : 'La mise à jour n\'a pas pu être programmée.';
        confirming.value = false;
    } finally {
        applying.value = false;
    }
}
</script>

<template>
    <PageHeader
        title="Mises à jour"
        description="Version installée, recherche et installation des nouvelles versions publiées et signées."
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Mises à jour' }]"
    >
        <template #actions>
            <button type="button" class="btn-secondary" :disabled="checking || !state.channel.configured || !state.channel.enabled" @click="check">
                <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': checking }" aria-hidden="true" /> Rechercher une mise à jour
            </button>
        </template>
    </PageHeader>

    <div class="space-y-5">
        <div v-if="maintenance" class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="status">
            <LoaderCircle class="h-4 w-4 animate-spin" aria-hidden="true" /> Le site est en maintenance pendant la mise à jour. Cette page se recharge d'elle-même à la fin.
        </div>
        <p v-if="actionError" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">{{ actionError }}</p>
        <p v-if="actionInfo" class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800" role="status">{{ actionInfo }}</p>

        <section class="card p-5" aria-labelledby="installed-title">
            <h2 id="installed-title" class="text-base font-semibold text-navy-900">Installation</h2>
            <dl class="dl-grid mt-4">
                <div>
                    <dt>Version installée</dt>
                    <dd class="font-mono">{{ state.installed }}</dd>
                </div>
                <div>
                    <dt>Mode d'installation</dt>
                    <dd>{{ deploymentLabel[state.deployment] }}</dd>
                </div>
                <div>
                    <dt>Canal des versions</dt>
                    <dd>
                        <a v-if="repositoryUrl" :href="repositoryUrl" class="link" target="_blank" rel="noopener noreferrer">{{ state.channel.repository }}</a>
                        <span v-else class="text-slate-500">Non configuré (config/release-channel.json)</span>
                    </dd>
                </div>
                <div>
                    <dt>Dernière recherche</dt>
                    <dd>{{ state.check ? formatDateTime(state.check.checked_at) : 'Jamais' }}</dd>
                </div>
            </dl>
        </section>

        <section class="card p-5" aria-labelledby="latest-title">
            <h2 id="latest-title" class="text-base font-semibold text-navy-900">Dernière version publiée</h2>

            <p v-if="!state.channel.enabled" class="mt-3 text-sm text-slate-600">La recherche de mises à jour est désactivée (UPDATE_CHECK_ENABLED=false).</p>
            <p v-else-if="!state.check" class="mt-3 text-sm text-slate-600">Aucune recherche pour le moment : cliquez sur « Rechercher une mise à jour ».</p>
            <p v-else-if="state.check.error" class="mt-3 flex items-start gap-2 text-sm text-red-800">
                <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /> {{ state.check.error }}
            </p>

            <template v-else-if="latest">
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="font-mono text-lg font-semibold text-navy-900">{{ latest.version }}</span>
                    <Badge v-if="state.check.available" color="teal">Nouvelle version</Badge>
                    <Badge v-else color="green"><CircleCheck class="h-3 w-3" aria-hidden="true" /> TechIndex est à jour</Badge>
                    <Badge :color="latest.migrations ? 'amber' : 'slate'">{{ latest.migrations ? 'Modifie la base de données' : 'Sans changement de la base' }}</Badge>
                </div>
                <p class="mt-1 text-xs text-slate-500">
                    Publiée le {{ formatDateTime(latest.published_at) }} · signature vérifiée<span v-if="latest.size"> · {{ formatBytes(latest.size) }}</span>
                </p>
                <pre v-if="latest.notes" class="mt-4 max-h-80 overflow-auto rounded-md bg-slate-50 p-3 font-sans text-sm whitespace-pre-wrap text-slate-700">{{ latest.notes }}</pre>

                <div v-if="state.check.available" class="mt-5 border-t border-slate-200 pt-4">
                    <template v-if="state.can_apply">
                        <p class="text-sm text-slate-600">
                            L'installation commence par une sauvegarde complète, puis le site passe en maintenance (en général moins d'une minute)
                            le temps d'appliquer la nouvelle version et de mettre à jour la base. En cas d'échec, la version actuelle reste en service.
                        </p>
                        <button type="button" class="btn-primary mt-3" :disabled="!canInstall" @click="confirming = true">
                            <CloudDownload class="h-4 w-4" aria-hidden="true" /> Installer la version {{ latest.version }}
                        </button>
                    </template>
                    <p v-else-if="state.deployment === 'docker'" class="text-sm text-slate-600">
                        Sur un serveur Docker, lancez sur le serveur : <code class="rounded bg-slate-100 px-1.5 py-0.5">sudo ./scripts/techindex.sh update</code>
                    </p>
                    <p v-else-if="state.deployment === 'plesk'" class="text-sm text-slate-600">
                        Installation depuis l'interface indisponible (script techindex-plesk.sh introuvable) : en SSH,
                        <code class="rounded bg-slate-100 px-1.5 py-0.5">./techindex-plesk.sh update</code>.
                    </p>
                    <p v-else class="text-sm text-slate-600">Poste de développement : mettez à jour le code avec Git (voir docs/publication-et-mises-a-jour.md).</p>
                </div>
            </template>
        </section>

        <section v-if="state.pending || state.status.state !== 'idle'" class="card p-5" aria-labelledby="status-title" aria-live="polite">
            <div class="flex flex-wrap items-center gap-2">
                <h2 id="status-title" class="text-base font-semibold text-navy-900">Dernière mise à jour</h2>
                <Badge v-if="state.pending" color="blue">Demandée</Badge>
                <Badge v-else :color="stateLabels[state.status.state].color">{{ stateLabels[state.status.state].label }}</Badge>
                <LoaderCircle v-if="busy" class="h-4 w-4 animate-spin text-slate-500" aria-hidden="true" />
            </div>

            <p v-if="state.pending" class="mt-3 text-sm text-slate-600">
                Version {{ state.pending.version }} demandée<span v-if="state.pending.requested_by"> par {{ state.pending.requested_by }}</span>
                le {{ formatDateTime(state.pending.requested_at) }} : elle démarre à la prochaine exécution de la tâche planifiée (au plus une minute).
            </p>
            <dl v-else class="dl-grid mt-4">
                <div>
                    <dt>Version</dt>
                    <dd class="font-mono">{{ state.status.from ?? '?' }} → {{ state.status.version ?? '?' }}</dd>
                </div>
                <div>
                    <dt>Étape</dt>
                    <dd>{{ state.status.step ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Début</dt>
                    <dd>{{ formatDateTime(state.status.started_at) }}<span v-if="state.status.requested_by"> ({{ state.status.requested_by }})</span></dd>
                </div>
                <div>
                    <dt>Fin</dt>
                    <dd>{{ state.status.finished_at ? formatDateTime(state.status.finished_at) : '—' }}</dd>
                </div>
            </dl>
            <p v-if="state.status.message && !state.pending" class="mt-3 text-sm" :class="state.status.state === 'failed' ? 'text-red-800' : 'text-slate-700'">
                {{ state.status.message }}
            </p>

            <details v-if="state.log.length" class="mt-4">
                <summary class="cursor-pointer text-sm font-medium text-navy-800">Journal de la mise à jour</summary>
                <pre class="mt-2 max-h-96 overflow-auto rounded-md bg-navy-950 p-3 text-xs whitespace-pre-wrap text-slate-100">{{ state.log.join('\n') }}</pre>
            </details>
        </section>
    </div>

    <ConfirmDialog
        :show="confirming"
        title="Installer la mise à jour"
        :message="`TechIndex passera de la version ${state.installed} à la version ${latest?.version ?? ''}. Une sauvegarde est faite avant toute modification ; le site sera brièvement en maintenance.`"
        confirm-label="Installer"
        :processing="applying"
        @confirm="install"
        @close="confirming = false"
    />
</template>
