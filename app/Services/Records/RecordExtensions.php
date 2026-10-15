<?php

namespace App\Services\Records;

use App\Models\Client;
use App\Models\RecordType;
use Illuminate\Database\Eloquent\Model;

/**
 * Champs ajoutés aux fiches par défaut (sites, équipements, services...).
 *
 * Les contrôleurs de ces fiches l'utilisent pour :
 * - transmettre la définition et les valeurs au formulaire (form()) ;
 * - valider la saisie « fields[...] » (rules()) ;
 * - enregistrer les valeurs et les consigner dans l'historique (save()) ;
 * - afficher les valeurs sur la fiche (present()).
 *
 * Tant qu'aucun champ n'a été ajouté à une fiche, rien ne change pour elle.
 */
final class RecordExtensions
{
    /** @var array<string, RecordType|null> */
    private array $types = [];

    public function __construct(private readonly RecordValues $values) {}

    public function type(string $entity): ?RecordType
    {
        if (! array_key_exists($entity, $this->types)) {
            $type = SystemRecordTypes::type($entity);
            $this->types[$entity] = $type !== null && $this->values->fields($type)->isNotEmpty() ? $type : null;
        }

        return $this->types[$entity];
    }

    /**
     * Définition et valeurs du formulaire ; null si la fiche n'a aucun champ ajouté.
     *
     * @return array<string, mixed>|null
     */
    public function form(string $entity, Client $client, ?Model $model): ?array
    {
        $type = $this->type($entity);

        if ($type === null) {
            return null;
        }

        $stored = $model?->exists ? ($this->values->load($type, [(int) $model->getKey()])[(int) $model->getKey()] ?? []) : null;

        return [
            ...$this->values->formDefinition($type, $client),
            'values' => (object) $this->values->formValues($type, $stored),
        ];
    }

    /**
     * Valeurs initiales seules (formulaires en fenêtre : contacts, sous-réseaux).
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function valuesFor(string $entity, array $ids): array
    {
        $type = $this->type($entity);

        if ($type === null) {
            return [];
        }

        $stored = $this->values->load($type, $ids);
        $result = [];

        foreach ($ids as $id) {
            $result[$id] = $this->values->formValues($type, $stored[$id] ?? []);
        }

        return $result;
    }

    /**
     * Définition seule (formulaire en fenêtre).
     *
     * @return array<string, mixed>|null
     */
    public function definition(string $entity, Client $client): ?array
    {
        $type = $this->type($entity);

        return $type === null ? null : [
            ...$this->values->formDefinition($type, $client),
            'defaults' => (object) $this->values->formValues($type, null),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function rules(string $entity, Client $client, array $input, ?Model $model): array
    {
        $type = $this->type($entity);

        return $type === null ? [] : $this->values->rules($type, $client, $input, $model?->exists ? (int) $model->getKey() : null);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(string $entity): array
    {
        $type = $this->type($entity);

        return $type === null ? [] : $this->values->attributes($type);
    }

    /**
     * Enregistre les champs ajoutés. Pour une modification, les changements
     * sont joints à l'historique du modèle (à enregistrer ensuite, puis
     * flushPendingAudit()).
     *
     * @param  array<string, mixed>  $input  saisie validée
     */
    public function save(string $entity, Model $model, int $clientId, array $input, bool $isNew): void
    {
        $type = $this->type($entity);

        if ($type === null || ! array_key_exists('fields', $input)) {
            return;
        }

        $changes = $this->values->save($type, (int) $model->getKey(), $clientId, $input, $isNew);

        if (method_exists($model, 'addAuditChange')) {
            foreach ($changes as $label => $change) {
                $model->addAuditChange('Champ « '.$label.' »', $change['old'], $change['new']);
            }
        }
    }

    /**
     * Valeurs présentées pour une liste (contacts, sous-réseaux), en une seule lecture.
     *
     * @param  iterable<Model>  $models
     * @param  (callable(Model): array<string, mixed>)|null  $builtin
     * @return array<int, list<array<string, mixed>>>
     */
    public function presentMany(string $entity, int $clientId, iterable $models, ?callable $builtin = null): array
    {
        $type = $this->type($entity);

        if ($type === null) {
            return [];
        }

        $models = collect($models);
        $stored = $this->values->load($type, $models->map(fn (Model $model): int => (int) $model->getKey())->all());
        $result = [];

        foreach ($models as $model) {
            $id = (int) $model->getKey();
            $result[$id] = $this->values->present($type, $clientId, $stored[$id] ?? [], $builtin === null ? [] : $builtin($model));
        }

        return $result;
    }

    /**
     * Valeurs présentées sur la fiche ; null si aucun champ ajouté.
     *
     * @param  array<string, mixed>  $builtin  valeurs des champs d'origine (conditions d'affichage)
     * @return list<array<string, mixed>>|null
     */
    public function present(string $entity, int $clientId, Model $model, array $builtin = []): ?array
    {
        $type = $this->type($entity);

        if ($type === null) {
            return null;
        }

        $values = $this->values->load($type, [(int) $model->getKey()])[(int) $model->getKey()] ?? [];

        return $this->values->present($type, $clientId, $values, $builtin);
    }
}
