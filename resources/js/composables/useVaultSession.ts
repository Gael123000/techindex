import { computed, reactive } from 'vue';
import type { ComputedRef } from 'vue';
import { route } from 'ziggy-js';
import { requestJson } from '@/lib/http';
import { verifyPasskey } from '@/lib/webauthn';

/**
 * Déverrouillage des mots de passe, commun au gestionnaire et aux fiches
 * (équipements, services, accès). Le serveur ouvre les mots de passe pour
 * une durée fixe après une vérification par clé d'accès, puis les reverrouille
 * même en cas d'activité. L'interface ne fait qu'afficher le temps restant :
 * chaque révélation est contrôlée par le serveur.
 */
interface VaultSessionState {
    expiresAt: number;
    now: number;
    prompt: { open: boolean; resolver: ((unlocked: boolean) => void) | null };
}

const state = reactive<VaultSessionState>({
    expiresAt: 0,
    now: Date.now(),
    prompt: { open: false, resolver: null },
});

let timer: ReturnType<typeof setInterval> | undefined;

function tick(): void {
    state.now = Date.now();

    if (state.now >= state.expiresAt) {
        clearInterval(timer);
        timer = undefined;
    }
}

/**
 * Temps restant transmis par le serveur (propriété partagée « vault » ou
 * réponse du déverrouillage).
 */
export function syncVaultSession(expiresIn: number): void {
    state.now = Date.now();
    state.expiresAt = state.now + Math.max(0, expiresIn) * 1000;

    if (state.expiresAt > state.now && timer === undefined) {
        timer = setInterval(tick, 1000);
    }
}

/**
 * Vérification par clé d'accès, puis ouverture des mots de passe.
 */
export async function unlockVault(): Promise<void> {
    const result = await verifyPasskey<{ expires_in: number }>(route('vault.unlock.options'), route('vault.unlock'));

    syncVaultSession(result.expires_in);
}

export async function lockVault(): Promise<void> {
    await requestJson(route('vault.lock'), { method: 'POST' });
    syncVaultSession(0);
}

/**
 * Ouvre la fenêtre de déverrouillage (montée une seule fois dans la mise en
 * page) et résout la promesse une fois les mots de passe ouverts, ou false
 * si l'utilisateur annule.
 */
export function requestVaultUnlock(): Promise<boolean> {
    state.prompt.resolver?.(false);
    state.prompt.open = true;

    return new Promise((resolve) => {
        state.prompt.resolver = resolve;
    });
}

export function settleVaultUnlock(unlocked: boolean): void {
    state.prompt.open = false;
    state.prompt.resolver?.(unlocked);
    state.prompt.resolver = null;
}

export function useVaultSession(): {
    remaining: ComputedRef<number>;
    remainingLabel: ComputedRef<string>;
    promptOpen: ComputedRef<boolean>;
} {
    const remaining = computed(() => Math.max(0, Math.ceil((state.expiresAt - state.now) / 1000)));

    return {
        remaining,
        remainingLabel: computed(() => `${Math.floor(remaining.value / 60)}:${String(remaining.value % 60).padStart(2, '0')}`),
        promptOpen: computed(() => state.prompt.open),
    };
}
