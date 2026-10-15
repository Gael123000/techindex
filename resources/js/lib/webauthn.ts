import { HttpError, requestJson } from './http';

/**
 * Clés d'accès (WebAuthn) côté navigateur.
 *
 * Le serveur transmet les options au format JSON (identifiants et défis en
 * base64url) ; elles sont converties pour navigator.credentials, puis la
 * réponse de l'authentificateur est renvoyée au serveur, qui la vérifie.
 * La clé privée ne quitte jamais l'appareil (Windows Hello, téléphone, clé USB...).
 */

type Json = Record<string, unknown>;

interface DescriptorJson {
    id: string;
    type: string;
    transports?: string[];
}

export class PasskeyError extends Error {}

export function passkeysSupported(): boolean {
    return typeof window !== 'undefined' && window.isSecureContext && typeof window.PublicKeyCredential === 'function' && 'credentials' in navigator;
}

function toBuffer(value: string): ArrayBuffer {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
    const binary = atob(base64 + '='.repeat((4 - (base64.length % 4)) % 4));
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes.buffer;
}

function toBase64Url(buffer: ArrayBuffer): string {
    let binary = '';

    for (const byte of new Uint8Array(buffer)) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function descriptors(list: unknown): PublicKeyCredentialDescriptor[] | undefined {
    if (!Array.isArray(list)) {
        return undefined;
    }

    return (list as DescriptorJson[]).map((item) => ({
        type: 'public-key',
        id: toBuffer(item.id),
        transports: item.transports as AuthenticatorTransport[] | undefined,
    }));
}

function creationOptions(options: Json): PublicKeyCredentialCreationOptions {
    const user = options.user as { id: string; name: string; displayName: string };

    return {
        ...(options as unknown as PublicKeyCredentialCreationOptions),
        challenge: toBuffer(options.challenge as string),
        user: { ...user, id: toBuffer(user.id) },
        excludeCredentials: descriptors(options.excludeCredentials),
    };
}

function requestOptions(options: Json): PublicKeyCredentialRequestOptions {
    return {
        ...(options as unknown as PublicKeyCredentialRequestOptions),
        challenge: toBuffer(options.challenge as string),
        allowCredentials: descriptors(options.allowCredentials),
    };
}

function serialize(credential: PublicKeyCredential): Json {
    const response = credential.response;
    const json: Json = {
        id: credential.id,
        rawId: toBase64Url(credential.rawId),
        type: credential.type,
        clientExtensionResults: credential.getClientExtensionResults(),
    };

    if ('attestationObject' in response) {
        const attestation = response as AuthenticatorAttestationResponse;

        json.response = {
            clientDataJSON: toBase64Url(attestation.clientDataJSON),
            attestationObject: toBase64Url(attestation.attestationObject),
            transports: typeof attestation.getTransports === 'function' ? attestation.getTransports() : [],
        };
    } else {
        const assertion = response as AuthenticatorAssertionResponse;

        json.response = {
            clientDataJSON: toBase64Url(assertion.clientDataJSON),
            authenticatorData: toBase64Url(assertion.authenticatorData),
            signature: toBase64Url(assertion.signature),
            userHandle: assertion.userHandle ? toBase64Url(assertion.userHandle) : null,
        };
    }

    return json;
}

/**
 * Message compréhensible pour les erreurs du navigateur.
 */
function explain(error: unknown): Error {
    if (error instanceof HttpError || error instanceof PasskeyError) {
        return error;
    }

    if (error instanceof DOMException) {
        switch (error.name) {
            case 'NotAllowedError':
                return new PasskeyError('Opération annulée ou délai dépassé. Réessayez.');
            case 'InvalidStateError':
                return new PasskeyError('Cet appareil possède déjà une clé d\'accès pour votre compte.');
            case 'SecurityError':
                return new PasskeyError('Le navigateur refuse les clés d\'accès pour cette adresse (connexion non sécurisée ou domaine différent).');
            case 'NotSupportedError':
                return new PasskeyError('Aucun authentificateur compatible n\'est disponible sur cet appareil.');
            case 'AbortError':
                return new PasskeyError('Opération interrompue.');
        }
    }

    return error instanceof Error ? error : new PasskeyError('Erreur inattendue.');
}

function ensureSupported(): void {
    if (!passkeysSupported()) {
        throw new PasskeyError('Ce navigateur ne prend pas en charge les clés d\'accès, ou la page n\'est pas servie en HTTPS.');
    }
}

/**
 * Crée une clé d'accès sur l'appareil et l'enregistre sur le compte, avec sa
 * description (type et précision).
 */
export async function registerPasskey(optionsUrl: string, storeUrl: string, description: Json): Promise<void> {
    ensureSupported();

    try {
        const { options } = await requestJson<{ options: Json }>(optionsUrl);
        const credential = await navigator.credentials.create({ publicKey: creationOptions(options) });

        if (!(credential instanceof PublicKeyCredential)) {
            throw new PasskeyError('Aucune clé d\'accès n\'a été créée.');
        }

        await requestJson(storeUrl, { method: 'POST', body: { ...description, credential: serialize(credential) } });
    } catch (error) {
        throw explain(error);
    }
}

/**
 * Demande à l'appareil de prouver la possession d'une clé d'accès du compte,
 * puis transmet la preuve au serveur.
 */
export async function verifyPasskey<T = Json>(optionsUrl: string, verifyUrl: string, extra: Json = {}): Promise<T> {
    ensureSupported();

    try {
        const { options } = await requestJson<{ options: Json }>(optionsUrl);
        const credential = await navigator.credentials.get({ publicKey: requestOptions(options) });

        if (!(credential instanceof PublicKeyCredential)) {
            throw new PasskeyError('Aucune clé d\'accès n\'a été présentée.');
        }

        return await requestJson<T>(verifyUrl, { method: 'POST', body: { ...extra, credential: serialize(credential) } });
    } catch (error) {
        throw explain(error);
    }
}
