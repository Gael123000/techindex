<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ce dont un connecteur dispose pendant une exécution : identifiants
 * déchiffrés, réglages et client HTTP. Les jetons d'accès obtenus ne sont
 * conservés qu'en mémoire, le temps de l'exécution.
 */
final class IntegrationContext
{
    /** @var array<string, array{value: string, expires: int}> */
    private array $tokens = [];

    /**
     * @param  array<string, string>  $credentials
     * @param  array<string, mixed>  $settings
     * @param  (Closure(string, string): void)|null  $persistCredential  enregistre un identifiant renouvelé par le service
     */
    public function __construct(
        public readonly Integration $integration,
        private array $credentials,
        public readonly array $settings,
        private readonly ProviderDefinition $definition,
        private readonly ?Closure $persistCredential = null,
    ) {}

    /**
     * Remplace un identifiant renouvelé par le service (jeton de
     * rafraîchissement à usage unique) et l'enregistre aussitôt, chiffré.
     */
    public function replaceCredential(string $key, string $value): void
    {
        $this->credentials[$key] = $value;

        if ($this->persistCredential !== null) {
            ($this->persistCredential)($key, $value);
        }
    }

    public function credential(string $key): string
    {
        $value = $this->credentials[$key] ?? '';

        if ($value === '') {
            $field = collect($this->definition->credentials)->firstWhere('key', $key);

            throw IntegrationException::missingCredential($field['label'] ?? $key);
        }

        return $value;
    }

    public function optionalCredential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? '';

        return $value === '' ? null : $value;
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        $value = $this->settings[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $field = collect($this->definition->settings)->firstWhere('key', $key);

        return $field['default'] ?? $default;
    }

    /**
     * Client HTTP commun : délais bornés, JSON, nouvelles tentatives en cas
     * de saturation (429, en respectant Retry-After) ou de panne passagère.
     */
    public function http(): PendingRequest
    {
        return Http::acceptJson()
            ->withUserAgent('TechIndex/'.config('techindex.version', '1').' (integrations)')
            ->connectTimeout(10)
            ->timeout(40)
            ->retry(3, function (int $attempt, Throwable $exception): int {
                $retryAfter = $exception instanceof RequestException ? (int) $exception->response->header('Retry-After') : 0;

                return $retryAfter > 0 ? min($retryAfter, 30) * 1000 : $attempt * 1000;
            }, function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError());
            }, throw: false);
    }

    /**
     * Jeton mis en cache pour la durée de l'exécution (marge d'une minute).
     *
     * @param  Closure(): array{0: string, 1: int}  $resolver  renvoie [jeton, durée de validité en secondes]
     */
    public function token(string $key, Closure $resolver): string
    {
        $cached = $this->tokens[$key] ?? null;

        if ($cached !== null && $cached['expires'] > time()) {
            return $cached['value'];
        }

        [$value, $ttl] = $resolver();
        $this->tokens[$key] = ['value' => $value, 'expires' => time() + max(60, $ttl) - 60];

        return $value;
    }

    /**
     * Appel HTTP avec traduction des erreurs réseau en IntegrationException.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    public function guard(string $context, Closure $call): mixed
    {
        try {
            return $call();
        } catch (ConnectionException) {
            throw IntegrationException::unreachable($context);
        }
    }
}
