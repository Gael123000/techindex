<script setup lang="ts">
import { Copy, Eye, EyeOff } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import { requestVaultUnlock, useVaultSession } from '@/composables/useVaultSession';
import { HttpError, requestJson } from '@/lib/http';

/**
 * Secret masqué par défaut. La valeur n'est demandée au serveur qu'au clic
 * (révélation ou copie) ; le serveur exige que les mots de passe aient été
 * déverrouillés par clé d'accès depuis moins de 5 minutes (sur une fiche
 * comme dans le gestionnaire) et journalise la demande. La valeur affichée
 * est effacée de la mémoire de la page après 30 secondes, ou dès le
 * reverrouillage.
 */
const props = defineProps<{ revealUrl: string | null; label: string }>();

const secret = ref<string | null>(null);
const remaining = ref(0);
const busy = ref(false);
const message = ref<string | null>(null);
const error = ref<string | null>(null);
const { remaining: unlockedFor } = useVaultSession();
let timer: ReturnType<typeof setInterval> | undefined;

function hide(): void {
    secret.value = null;
    remaining.value = 0;
    clearInterval(timer);
}

async function fetchSecret(purpose: 'reveal' | 'copy'): Promise<{ secret: string; expires_in: number } | null> {
    if (!props.revealUrl) {
        return null;
    }

    busy.value = true;
    error.value = null;

    try {
        return await requestJson<{ secret: string; expires_in: number }>(props.revealUrl, { method: 'POST', body: { purpose } });
    } catch (exception) {
        // Mots de passe verrouillés : déverrouillage par clé d'accès, puis nouvelle demande.
        if (exception instanceof HttpError && exception.status === 423 && exception.payload.vault_locked) {
            busy.value = false;

            return (await requestVaultUnlock()) ? fetchSecret(purpose) : null;
        }

        error.value =
            exception instanceof HttpError && exception.status === 429
                ? 'Trop de demandes : patientez une minute.'
                : exception instanceof Error
                  ? exception.message
                  : 'Erreur inattendue.';

        return null;
    } finally {
        busy.value = false;
    }
}

function show(value: string, seconds: number): void {
    clearInterval(timer);
    secret.value = value;
    remaining.value = seconds;
    timer = setInterval(() => {
        remaining.value--;

        if (remaining.value <= 0) {
            hide();
        }
    }, 1000);
}

async function reveal(): Promise<void> {
    if (secret.value !== null) {
        hide();

        return;
    }

    const result = await fetchSecret('reveal');

    if (result) {
        show(result.secret, result.expires_in);
    }
}

async function copy(): Promise<void> {
    const result = await fetchSecret('copy');

    if (!result) {
        return;
    }

    try {
        await navigator.clipboard.writeText(result.secret);
        message.value = 'Copié dans le presse-papiers.';
    } catch {
        // Presse-papiers indisponible (contexte non sécurisé) : affichage temporaire.
        show(result.secret, result.expires_in);
        message.value = 'Copie automatique impossible : sélectionnez la valeur affichée.';
    }

    setTimeout(() => {
        message.value = null;
    }, 4000);
}

// Reverrouillage (fin des 5 minutes ou verrouillage manuel) : le secret affiché disparaît.
watch(unlockedFor, (seconds) => {
    if (seconds <= 0) {
        hide();
    }
});

onBeforeUnmount(hide);
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5">
        <code
            class="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs"
            :class="secret !== null ? 'text-slate-900 select-all' : 'tracking-widest text-slate-500'"
            :aria-label="secret !== null ? `Secret de ${label}` : `Secret de ${label} masqué`"
        >
            {{ secret ?? '••••••••••' }}
        </code>
        <template v-if="revealUrl">
            <button type="button" class="btn-ghost btn-sm" :disabled="busy" :aria-pressed="secret !== null" @click="reveal">
                <EyeOff v-if="secret !== null" class="h-3.5 w-3.5" aria-hidden="true" />
                <Eye v-else class="h-3.5 w-3.5" aria-hidden="true" />
                <span>{{ secret !== null ? `Masquer (${remaining} s)` : 'Afficher' }}</span>
                <span class="sr-only">le secret de {{ label }}</span>
            </button>
            <button type="button" class="btn-ghost btn-sm" :disabled="busy" @click="copy">
                <Copy class="h-3.5 w-3.5" aria-hidden="true" />
                <span>Copier</span>
                <span class="sr-only">le secret de {{ label }}</span>
            </button>
        </template>
        <span v-if="message" class="text-xs text-turquoise-800" role="status">{{ message }}</span>
        <span v-if="error" class="text-xs text-red-700" role="alert">{{ error }}</span>
    </div>
</template>
