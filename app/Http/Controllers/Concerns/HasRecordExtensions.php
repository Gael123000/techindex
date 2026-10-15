<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Client;
use App\Services\Records\RecordExtensions;
use Illuminate\Database\Eloquent\Model;

/**
 * Champs ajoutés aux fiches par défaut avec le constructeur de fiches :
 * formulaire, affichage et enregistrement (voir RecordExtensions).
 */
trait HasRecordExtensions
{
    protected function recordExtensions(): RecordExtensions
    {
        return app(RecordExtensions::class);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function extensionForm(string $entity, Client $client, ?Model $model): ?array
    {
        return $this->recordExtensions()->form($entity, $client, $model);
    }

    /**
     * @param  array<string, mixed>  $builtin  valeurs des champs d'origine (conditions d'affichage)
     * @return list<array<string, mixed>>|null
     */
    protected function extensionDisplay(string $entity, Client $client, Model $model, array $builtin = []): ?array
    {
        return $this->recordExtensions()->present($entity, $client->id, $model, $builtin);
    }

    /**
     * Enregistre les champs ajoutés ; pour une modification, les changements
     * rejoignent l'historique du modèle, à enregistrer ensuite.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function saveExtension(string $entity, Model $model, Client $client, array $validated, bool $isNew): void
    {
        $this->recordExtensions()->save($entity, $model, (int) $client->id, $validated, $isNew);
    }
}
