<?php

namespace App\Secrets;

use App\Models\Integration;
use App\Secrets\Contracts\SecretVault;
use App\Secrets\Exceptions\SecretVaultException;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Seul point d'écriture et de lecture des identifiants d'API des intégrations
 * (clés, secrets d'application, jetons de rafraîchissement).
 *
 * Les identifiants sont chiffrés ensemble (JSON) par le coffre des secrets,
 * avec la même clé que les accès des clients. Ils ne sont déchiffrés que
 * pour appeler le service externe : jamais renvoyés à l'interface, jamais
 * journalisés ; l'historique indique seulement les champs modifiés.
 */
class IntegrationSecrets
{
    public function __construct(private readonly SecretVault $vault) {}

    /**
     * Prépare les identifiants sur le modèle (sans sauvegarder). Un champ
     * laissé vide conserve la valeur enregistrée ; $clear retire les champs
     * listés.
     *
     * @param  array<string, string|null>  $input
     * @param  list<string>  $clear
     * @return list<string> champs modifiés
     */
    public function apply(Integration $integration, array $input, array $clear = [], bool $audit = true): array
    {
        $current = $integration->hasCredentials() ? $this->read($integration) : [];
        $next = $current;

        foreach ($input as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $next[$key] = trim($value);
            }
        }

        foreach ($clear as $key) {
            unset($next[$key]);
        }

        $changed = array_values(array_unique([
            ...array_keys(array_diff_assoc($next, $current)),
            ...array_keys(array_diff_key($current, $next)),
        ]));

        if ($changed === []) {
            return [];
        }

        if ($next === []) {
            $integration->forceFill(['credentials_ciphertext' => null, 'credentials_key_id' => null]);
        } else {
            $sealed = $this->vault->seal(json_encode($next, JSON_THROW_ON_ERROR));
            $integration->forceFill(['credentials_ciphertext' => $sealed->ciphertext, 'credentials_key_id' => $sealed->keyId]);
        }

        $integration->forceFill(['credentials_updated_at' => now()]);

        // Noms des champs seulement : les valeurs ne quittent jamais ce service.
        foreach ($audit ? $changed : [] as $key) {
            $integration->addAuditChange('identifiant « '.$key.' »', null, 'modifié');
        }

        return $changed;
    }

    /**
     * Enregistre aussitôt un identifiant renouvelé par le service externe
     * (jeton de rafraîchissement à usage unique), sans entrée d'historique.
     */
    public function store(Integration $integration, string $key, string $value): void
    {
        $this->apply($integration, [$key => $value], audit: false);

        if ($integration->isDirty('credentials_ciphertext')) {
            $integration->saveQuietly();
        }
    }

    /**
     * Identifiants déchiffrés, pour l'appel au service externe uniquement.
     *
     * @return array<string, string>
     *
     * @throws SecretVaultException
     */
    public function read(Integration $integration): array
    {
        if (! $integration->hasCredentials()) {
            return [];
        }

        $plaintext = $this->vault->unseal(new SealedSecret($integration->credentials_ciphertext, (string) $integration->credentials_key_id));

        try {
            $values = json_decode($plaintext, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SecretVaultException::decryptionFailed();
        }

        return is_array($values) ? array_map('strval', array_filter($values, 'is_scalar')) : [];
    }

    /**
     * Champs renseignés (sans leur valeur), pour l'interface.
     *
     * @return list<string>
     */
    public function configuredKeys(Integration $integration): array
    {
        try {
            return array_keys(array_filter($this->read($integration), fn (string $value): bool => $value !== ''));
        } catch (SecretVaultException) {
            return [];
        }
    }

    /**
     * Rechiffre les identifiants avec la clé courante (rotation de la clé des secrets).
     *
     * @return array{rotated: int, failed: int}
     */
    public function rotate(): array
    {
        $rotated = 0;
        $failed = 0;

        Integration::query()->whereNotNull('credentials_ciphertext')->orderBy('id')->each(function (Integration $integration) use (&$rotated, &$failed): void {
            $sealed = new SealedSecret($integration->credentials_ciphertext, (string) $integration->credentials_key_id);

            if (! $this->vault->needsResealing($sealed)) {
                return;
            }

            try {
                $resealed = $this->vault->seal($this->vault->unseal($sealed));
            } catch (SecretVaultException) {
                $failed++;

                return;
            }

            // Mise à jour directe : la rotation ne modifie pas les identifiants eux-mêmes.
            DB::table('integrations')->where('id', $integration->id)->update([
                'credentials_ciphertext' => $resealed->ciphertext,
                'credentials_key_id' => $resealed->keyId,
            ]);
            $rotated++;
        });

        return ['rotated' => $rotated, 'failed' => $failed];
    }
}
