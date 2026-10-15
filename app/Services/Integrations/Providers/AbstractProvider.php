<?php

namespace App\Services\Integrations\Providers;

use App\Services\Integrations\Contracts\IntegrationProvider;
use App\Services\Integrations\IntegrationContext;
use App\Services\Integrations\IntegrationException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Outils communs des connecteurs : jeton OAuth2 « client credentials »,
 * lecture JSON avec erreurs traduites, pagination et conversion des dates.
 */
abstract class AbstractProvider implements IntegrationProvider
{
    /**
     * Jeton d'accès OAuth2 (client credentials), mis en cache pour l'exécution.
     *
     * @param  array<string, string>  $form
     */
    protected function oauthToken(IntegrationContext $context, string $cacheKey, string $url, array $form, string $what, bool $asJson = false, ?Closure $configure = null): string
    {
        return $context->token($cacheKey, function () use ($context, $url, $form, $what, $asJson, $configure): array {
            $request = $context->http();
            $request = $configure === null ? $request : $configure($request);

            $response = $context->guard($what, fn (): Response => $asJson ? $request->post($url, $form) : $request->asForm()->post($url, $form));

            if ($response->failed()) {
                throw IntegrationException::fromResponse($response, $what);
            }

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                throw IntegrationException::invalidResponse($what);
            }

            return [$token, (int) ($response->json('expires_in') ?? 3600)];
        });
    }

    /**
     * Requête GET renvoyant un objet JSON.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function getJson(IntegrationContext $context, PendingRequest $request, string $url, array $query, string $what): array
    {
        return $this->json($context, $what, fn (): Response => $request->get($url, $query));
    }

    /**
     * @param  Closure(): Response  $call
     * @return array<string, mixed>
     */
    protected function json(IntegrationContext $context, string $what, Closure $call): array
    {
        $response = $context->guard($what, $call);

        if ($response->failed()) {
            throw IntegrationException::fromResponse($response, $what);
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw IntegrationException::invalidResponse($what);
        }

        return $json;
    }

    /**
     * Date du fournisseur (ISO 8601, date simple ou horodatage) ; null si illisible.
     */
    protected function date(mixed $value, bool $milliseconds = false): ?CarbonImmutable
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $seconds = (int) $value;

                return CarbonImmutable::createFromTimestampUTC($milliseconds ? intdiv($seconds, 1000) : $seconds);
            }

            $date = CarbonImmutable::parse((string) $value);

            // Dates « vides » renvoyées par certains services (0001-01-01).
            return $date->year < 1990 || $date->year > 2200 ? null : $date;
        } catch (Throwable) {
            return null;
        }
    }

    protected function int(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    protected function string(mixed $value, int $limit = 255): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /**
     * Pluriel français simple pour les résumés (« 1 client », « 3 clients »).
     */
    protected function count(int $count, string $singular, ?string $plural = null): string
    {
        return $count.' '.($count > 1 ? ($plural ?? $singular.'s') : $singular);
    }
}
