<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Fingerprint, KeyRound, LockKeyhole } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import { settleVaultUnlock, unlockVault, useVaultSession } from '@/composables/useVaultSession';
import { passkeysSupported } from '@/lib/webauthn';
import Modal from './Modal.vue';

/**
 * Déverrouillage des mots de passe depuis une fiche (équipement, service,
 * accès) : même vérification par clé d'accès que le gestionnaire, et même
 * session de 5 minutes. Le mot de passe TechIndex ne suffit pas.
 */
const page = usePage();
const { promptOpen } = useVaultSession();
const busy = ref(false);
const error = ref<string | null>(null);
const supported = ref(true);

const hasPasskeys = computed(() => page.props.auth?.user.has_passkeys === true);

watch(promptOpen, (open) => {
    error.value = null;

    if (open) {
        supported.value = passkeysSupported();
    }
});

async function unlock(): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        await unlockVault();
        settleVaultUnlock(true);
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'Le déverrouillage a échoué.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Modal :show="promptOpen" title="Mots de passe verrouillés" size="sm" @close="settleVaultUnlock(false)">
        <div class="flex gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-navy-900 text-white" aria-hidden="true">
                <LockKeyhole class="h-5 w-5" />
            </span>
            <div class="space-y-2 text-sm text-slate-600">
                <template v-if="hasPasskeys">
                    <p>Pour afficher ou copier un mot de passe, déverrouillez avec votre clé d'accès : Windows Hello, empreinte, visage, téléphone ou clé de sécurité.</p>
                    <p class="text-xs text-slate-500">Les mots de passe restent ouverts 5 minutes, ici comme dans le gestionnaire, puis se reverrouillent automatiquement.</p>
                </template>
                <p v-else>
                    Les mots de passe ne s'affichent qu'après vérification par une clé d'accès. Ajoutez-en une depuis votre profil (Windows Hello, téléphone ou
                    clé de sécurité).
                </p>
                <p v-if="hasPasskeys && !supported" class="text-red-700" role="alert">
                    Ce navigateur ne prend pas en charge les clés d'accès, ou la page n'est pas servie en HTTPS.
                </p>
                <p v-if="error" class="text-red-700" role="alert">{{ error }}</p>
            </div>
        </div>
        <template #footer>
            <button type="button" class="btn-secondary" @click="settleVaultUnlock(false)">Annuler</button>
            <button v-if="hasPasskeys" type="button" class="btn-accent" :disabled="busy || !supported" @click="unlock">
                <Fingerprint class="h-4 w-4" aria-hidden="true" />
                {{ busy ? 'Vérification en cours…' : 'Déverrouiller avec ma clé d\'accès' }}
            </button>
            <Link v-else :href="route('profile.show')" class="btn-accent" @click="settleVaultUnlock(false)">
                <KeyRound class="h-4 w-4" aria-hidden="true" /> Ajouter une clé d'accès
            </Link>
        </template>
    </Modal>
</template>
