import { reactive } from 'vue';
import { route } from 'ziggy-js';
import { requestJson } from '@/lib/http';

interface ConfirmationState {
    open: boolean;
    resolver: ((confirmed: boolean) => void) | null;
}

const state = reactive<ConfirmationState>({ open: false, resolver: null });

/**
 * Ouvre la fenêtre de confirmation du mot de passe (montée une seule fois
 * dans la mise en page) et résout la promesse lorsque l'utilisateur confirme
 * ou annule.
 */
export function requestPasswordConfirmation(): Promise<boolean> {
    state.resolver?.(false);
    state.open = true;

    return new Promise((resolve) => {
        state.resolver = resolve;
    });
}

export function settlePasswordConfirmation(confirmed: boolean): void {
    state.open = false;
    state.resolver?.(confirmed);
    state.resolver = null;
}

export function usePasswordConfirmationState(): ConfirmationState {
    return state;
}

/**
 * Vérifie côté serveur si une confirmation récente existe, et la demande sinon.
 */
export async function ensurePasswordConfirmed(): Promise<boolean> {
    const status = await requestJson<{ confirmed: boolean }>(route('security.confirmation-status'));

    return status.confirmed ? true : requestPasswordConfirmation();
}
