<?php

namespace App\Secrets;

use App\Enums\ClientPermission;
use App\Models\Credential;
use App\Models\User;
use App\Secrets\Contracts\SecretVault;
use App\Secrets\Exceptions\SecretVaultException;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture et de lecture des secrets des accès.
 *
 * - L'écriture chiffre immédiatement la valeur ; seule la charge chiffrée est
 *   conservée sur le modèle.
 * - La lecture vérifie à nouveau le droit "secrets" (défense en profondeur,
 *   en plus de la politique et du middleware de réauthentification) et
 *   journalise la demande, sans jamais enregistrer la valeur.
 */
class CredentialSecrets
{
    public const PURPOSE_REVEAL = 'reveal';

    public const PURPOSE_COPY = 'copy';

    public function __construct(
        private readonly SecretVault $vault,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Prépare le secret sur le modèle (sans sauvegarder). Une valeur vide
     * conserve le secret existant, sauf si $clear est vrai.
     */
    public function apply(Credential $credential, ?string $plaintext, bool $clear = false): void
    {
        $hadSecret = $credential->secret_ciphertext !== null;

        if ($clear) {
            if ($hadSecret) {
                $credential->forceFill([
                    'secret_ciphertext' => null,
                    'secret_key_id' => null,
                    'secret_updated_at' => now(),
                ]);
                $credential->addAuditChange('secret', 'défini', 'supprimé');
            }

            return;
        }

        if ($plaintext === null || $plaintext === '') {
            return;
        }

        $sealed = $this->vault->seal($plaintext);

        $credential->forceFill([
            'secret_ciphertext' => $sealed->ciphertext,
            'secret_key_id' => $sealed->keyId,
            'secret_updated_at' => now(),
        ]);

        // La clé "secret" est systématiquement masquée par le Redactor :
        // l'historique indique qu'il y a eu modification, jamais la valeur.
        $credential->addAuditChange('secret', $hadSecret ? 'défini' : null, 'modifié');
    }

    /**
     * Déchiffre le secret pour un utilisateur autorisé et journalise la demande.
     *
     * @throws AuthorizationException
     * @throws SecretVaultException
     */
    public function reveal(Credential $credential, User $user, string $purpose = self::PURPOSE_REVEAL): string
    {
        if (! $user->hasClientPermission($credential->client_id, ClientPermission::Secrets)) {
            throw new AuthorizationException('Accès aux secrets refusé.');
        }

        if ($credential->secret_ciphertext === null) {
            throw new SecretVaultException('Aucun secret n\'est enregistré pour cet accès.');
        }

        $event = $purpose === self::PURPOSE_COPY ? 'credential.secret_copied' : 'credential.secret_revealed';

        try {
            $plaintext = $this->vault->unseal(new SealedSecret($credential->secret_ciphertext, (string) $credential->secret_key_id));
        } catch (SecretVaultException $exception) {
            $this->audit->log($event, $credential, metadata: ['result' => 'échec'], clientId: $credential->client_id, user: $user);

            throw $exception;
        }

        $this->audit->log($event, $credential, metadata: ['result' => 'succès'], clientId: $credential->client_id, user: $user);

        return $plaintext;
    }

    /**
     * Rechiffre avec la clé courante tous les secrets scellés avec une autre clé.
     *
     * @return array{rotated: int, failed: int}
     */
    public function rotate(): array
    {
        $rotated = 0;
        $failed = 0;
        $currentId = null;

        Credential::query()
            ->whereNotNull('secret_ciphertext')
            ->orderBy('id')
            ->chunkById(200, function ($credentials) use (&$rotated, &$failed, &$currentId): void {
                foreach ($credentials as $credential) {
                    $sealed = new SealedSecret($credential->secret_ciphertext, (string) $credential->secret_key_id);

                    if (! $this->vault->needsResealing($sealed)) {
                        continue;
                    }

                    try {
                        $resealed = $this->vault->seal($this->vault->unseal($sealed));
                    } catch (SecretVaultException) {
                        $failed++;

                        continue;
                    }

                    $currentId = $resealed->keyId;

                    // Mise à jour directe : la rotation ne change pas la valeur
                    // du secret, elle ne doit donc pas modifier secret_updated_at.
                    DB::table('credentials')->where('id', $credential->id)->update([
                        'secret_ciphertext' => $resealed->ciphertext,
                        'secret_key_id' => $resealed->keyId,
                    ]);

                    $rotated++;
                }
            });

        $this->audit->log('secrets.rotated', metadata: ['rechiffrés' => $rotated, 'échecs' => $failed, 'clé' => $currentId]);

        return ['rotated' => $rotated, 'failed' => $failed];
    }
}
