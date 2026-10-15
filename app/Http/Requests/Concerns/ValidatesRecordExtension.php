<?php

namespace App\Http\Requests\Concerns;

use App\Models\Client;
use App\Services\Records\RecordExtensions;
use Illuminate\Database\Eloquent\Model;

/**
 * Validation des champs ajoutés à une fiche par défaut (saisie « fields[...] »).
 */
trait ValidatesRecordExtension
{
    /**
     * @return array<string, mixed>
     */
    protected function extensionRules(string $entity, mixed $model = null): array
    {
        $client = $this->route('client');

        return app(RecordExtensions::class)->rules(
            $entity,
            $client instanceof Client ? $client : new Client,
            $this->all(),
            $model instanceof Model ? $model : null,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function extensionAttributes(string $entity): array
    {
        return app(RecordExtensions::class)->attributes($entity);
    }
}
