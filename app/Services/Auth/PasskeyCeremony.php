<?php

namespace App\Services\Auth;

use App\Models\Passkey;
use App\Models\User;
use Error;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Support\WebAuthn;
use PDOException;
use Throwable;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Cérémonies WebAuthn (clés d'accès) : enregistrement d'une clé, puis
 * vérification pour un usage donné.
 *
 * - Le défi (challenge) est aléatoire, conservé en session sous une clé propre
 *   à l'usage (connexion, confirmation, coffre) et consommé à la première
 *   tentative : une réponse ne peut pas être rejouée ni servir à un autre usage.
 * - La vérification est toujours liée à un utilisateur connu : seules ses
 *   propres clés sont proposées et acceptées.
 * - La vérification de l'utilisateur (biométrie ou code PIN) est exigée.
 * - Toute réponse invalide de l'authentificateur devient un refus générique,
 *   sans détail technique.
 */
class PasskeyCeremony
{
    public const LOGIN = 'login';

    public const CONFIRM = 'confirm';

    public const VAULT = 'vault';

    public function __construct(
        private readonly GenerateRegistrationOptions $generateRegistrationOptions,
        private readonly StorePasskey $storePasskey,
        private readonly GenerateVerificationOptions $generateVerificationOptions,
        private readonly VerifyPasskey $verifyPasskey,
    ) {}

    /**
     * Options transmises au navigateur pour créer une nouvelle clé d'accès.
     *
     * @return array<array-key, mixed>
     */
    public function registrationOptions(Request $request, User $user): array
    {
        $options = ($this->generateRegistrationOptions)($user);
        $request->session()->put('passkeys.registration', WebAuthn::toJson($options));

        return WebAuthn::toBrowserArray($options);
    }

    /**
     * Vérifie la réponse de l'authentificateur et enregistre la clé publique.
     *
     * @param  array<string, mixed>  $credential
     *
     * @throws ValidationException
     */
    public function register(Request $request, User $user, string $name, array $credential): Passkey
    {
        $serialized = $request->session()->pull('passkeys.registration');

        if (! is_string($serialized)) {
            throw ValidationException::withMessages(['credential' => 'La demande a expiré : recommencez l\'ajout de la clé d\'accès.']);
        }

        return $this->guard('La clé d\'accès n\'a pas pu être enregistrée. Réessayez ou utilisez un autre appareil.', function () use ($user, $name, $credential, $serialized): Passkey {
            /** @var Passkey */
            return ($this->storePasskey)(
                $user,
                $name,
                $this->parse($credential),
                WebAuthn::fromJson($serialized, PublicKeyCredentialCreationOptions::class),
            );
        });
    }

    /**
     * Options de vérification, limitées aux clés de l'utilisateur.
     *
     * @return array<array-key, mixed>
     */
    public function verificationOptions(Request $request, string $purpose, User $user): array
    {
        $options = ($this->generateVerificationOptions)($user);
        $request->session()->put($this->sessionKey($purpose), WebAuthn::toJson($options));

        return WebAuthn::toBrowserArray($options);
    }

    /**
     * Vérifie une assertion pour l'usage indiqué et retourne la clé utilisée.
     *
     * @param  array<string, mixed>  $credential
     *
     * @throws ValidationException
     */
    public function verify(Request $request, string $purpose, User $user, array $credential): Passkey
    {
        $serialized = $request->session()->pull($this->sessionKey($purpose));

        if (! is_string($serialized)) {
            throw ValidationException::withMessages(['credential' => 'La demande a expiré : recommencez la vérification.']);
        }

        return $this->guard('Clé d\'accès non reconnue ou vérification refusée.', function () use ($user, $credential, $serialized): Passkey {
            /** @var Passkey */
            return ($this->verifyPasskey)(
                $this->parse($credential),
                WebAuthn::fromJson($serialized, PublicKeyCredentialRequestOptions::class),
                $user,
            );
        });
    }

    /**
     * Règles de validation de la réponse de l'authentificateur.
     *
     * @return array<string, mixed>
     */
    public static function credentialRules(): array
    {
        return [
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string', 'max:1024'],
            'credential.rawId' => ['required', 'string', 'max:1024'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
        ];
    }

    private function sessionKey(string $purpose): string
    {
        return 'passkeys.'.$purpose;
    }

    /**
     * @param  array<string, mixed>  $credential
     *
     * @throws JsonException
     */
    private function parse(array $credential): PublicKeyCredential
    {
        return WebAuthn::fromJson(json_encode($credential, JSON_THROW_ON_ERROR), PublicKeyCredential::class);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws ValidationException
     */
    private function guard(string $message, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (PDOException $exception) {
            // Les erreurs d'infrastructure ne sont pas des refus d'authentification.
            throw $exception;
        } catch (Throwable $exception) {
            // Réponse falsifiée, rejouée, d'une autre clé ou mal formée : refus,
            // sans détail. Les erreurs de programmation sont signalées pour
            // diagnostic (les données WebAuthn ne contiennent aucun secret).
            if ($exception instanceof Error) {
                report($exception);
            }

            throw ValidationException::withMessages(['credential' => $message]);
        }
    }
}
