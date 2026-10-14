/**
 * Appels JSON hors navigation Inertia (révélation d'un secret, statut de
 * confirmation du mot de passe). Le jeton CSRF est lu dans le cookie
 * XSRF-TOKEN, renouvelé par Laravel à chaque réponse.
 */
export class HttpError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly payload: Record<string, unknown> = {},
    ) {
        super(message);
    }
}

function xsrfToken(): string | null {
    const match = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=')[1] ?? '') : null;
}

/**
 * Envoi d'un formulaire multipart (fichier) avec réponse JSON. Les erreurs de
 * validation sont regroupées en un message lisible.
 */
export async function postForm<T = Record<string, unknown>>(url: string, data: FormData): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const token = xsrfToken();

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    const response = await fetch(url, { method: 'POST', headers, body: data, credentials: 'same-origin', cache: 'no-store' });
    const isJson = response.headers.get('Content-Type')?.includes('application/json') ?? false;
    const payload = isJson ? ((await response.json()) as Record<string, unknown>) : {};

    if (response.status === 401) {
        window.location.reload();
    }

    if (!response.ok) {
        const errors = payload.errors as Record<string, string[]> | undefined;
        const message =
            response.status === 413
                ? 'Fichier trop volumineux pour le serveur.'
                : response.status === 429
                  ? 'Trop d\'envois : patientez une minute.'
                  : errors
                    ? Object.values(errors).flat().join(' ')
                    : typeof payload.message === 'string'
                      ? payload.message
                      : 'Une erreur est survenue.';

        throw new HttpError(response.status, message, payload);
    }

    return payload as T;
}

export async function requestJson<T = Record<string, unknown>>(
    url: string,
    options: { method?: string; body?: Record<string, unknown> } = {},
): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const token = xsrfToken();

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    if (options.body) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(url, {
        method: options.method ?? 'GET',
        headers,
        body: options.body ? JSON.stringify(options.body) : undefined,
        credentials: 'same-origin',
        cache: 'no-store',
    });

    const isJson = response.headers.get('Content-Type')?.includes('application/json') ?? false;
    const payload = isJson ? ((await response.json()) as Record<string, unknown>) : {};

    if (response.status === 401 || response.redirected) {
        // Session expirée : retour à la page de connexion.
        window.location.reload();
    }

    if (!response.ok) {
        const message = typeof payload.message === 'string' ? payload.message : 'Une erreur est survenue.';

        throw new HttpError(response.status, message, payload);
    }

    return payload as T;
}
