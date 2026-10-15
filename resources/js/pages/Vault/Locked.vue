<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Fingerprint, KeyRound, LockKeyhole, ShieldCheck } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { unlockVault } from '@/composables/useVaultSession';
import AppLayout from '@/layouts/AppLayout.vue';
import { passkeysSupported } from '@/lib/webauthn';

defineOptions({ layout: AppLayout });

/**
 * Écran affiché tant que les mots de passe sont verrouillés. Seule une clé
 * d'accès (biométrie ou code PIN de l'appareil) les déverrouille, pour une
 * durée fixe commune au gestionnaire et aux fiches.
 */
defineProps<{ hasPasskeys: boolean; lockMinutes: number }>();

const busy = ref(false);
const error = ref<string | null>(null);
const supported = ref(true);

onMounted(() => {
    supported.value = passkeysSupported();
});

async function unlock(): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        await unlockVault();
        router.reload();
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'Le déverrouillage a échoué.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <PageHeader title="Gestionnaire de mots de passe" :breadcrumbs="[{ label: 'Mots de passe' }]" />

    <section class="card mx-auto max-w-xl px-6 py-10 text-center" aria-labelledby="vault-locked-title">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-navy-900 text-white">
            <LockKeyhole class="h-7 w-7" aria-hidden="true" />
        </div>
        <h2 id="vault-locked-title" class="mt-4 text-lg font-semibold text-navy-900">Coffre verrouillé</h2>

        <template v-if="hasPasskeys">
            <p class="mx-auto mt-2 max-w-md text-sm text-slate-600">
                Déverrouillez le gestionnaire avec votre clé d'accès : Windows Hello, empreinte, visage, téléphone ou clé de sécurité.
            </p>
            <button type="button" class="btn-accent mt-6" :disabled="busy || !supported" @click="unlock">
                <Fingerprint class="h-4 w-4" aria-hidden="true" />
                {{ busy ? 'Vérification en cours…' : 'Déverrouiller avec ma clé d\'accès' }}
            </button>
            <p v-if="!supported" class="mt-3 text-sm text-red-700" role="alert">
                Ce navigateur ne prend pas en charge les clés d'accès, ou la page n'est pas servie en HTTPS.
            </p>
        </template>

        <template v-else>
            <p class="mx-auto mt-2 max-w-md text-sm text-slate-600">
                Le gestionnaire de mots de passe ne s'ouvre qu'avec une clé d'accès. Ajoutez-en une depuis votre profil (Windows Hello, téléphone ou clé de
                sécurité), puis revenez ici.
            </p>
            <Link :href="route('profile.show')" class="btn-accent mt-6"><KeyRound class="h-4 w-4" aria-hidden="true" /> Ajouter une clé d'accès</Link>
        </template>

        <p v-if="error" class="mt-4 text-sm text-red-700" role="alert">{{ error }}</p>

        <p class="mt-8 flex items-center justify-center gap-1.5 text-xs text-slate-500">
            <ShieldCheck class="h-3.5 w-3.5" aria-hidden="true" />
            Reverrouillage automatique {{ lockMinutes }} min après l'ouverture, même en cas d'activité. Chaque ouverture est enregistrée dans le journal
            d'audit.
        </p>
    </section>
</template>
