<?php

namespace App\Models\Concerns;

use App\Services\Audit\AuditLogger;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Journalise automatiquement création, modification, archivage et suppression.
 *
 * Seuls les champs non sensibles sont enregistrés : les attributs masqués du
 * modèle ($hidden), les horodatages et les champs listés dans
 * auditExcluded() ne figurent jamais dans l'historique.
 */
trait Auditable
{
    /**
     * Modifications supplémentaires (relations, listes...) à joindre à la
     * prochaine entrée d'historique.
     *
     * @var array<string, array{old: mixed, new: mixed}>
     */
    protected array $pendingAuditChanges = [];

    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            $model->writeAudit('created');
        });

        static::updated(function (Model $model): void {
            $model->writeAudit($model->resolveAuditUpdateAction(), $model->auditableChanges());
        });

        static::deleted(function (Model $model): void {
            $model->writeAudit('deleted');
        });
    }

    public function auditType(): string
    {
        return $this->getMorphClass();
    }

    public function auditLabel(): string
    {
        $attributes = $this->getAttributes();

        foreach (['name', 'title', 'label'] as $attribute) {
            if (filled($attributes[$attribute] ?? null)) {
                return (string) $attributes[$attribute];
            }
        }

        return '#'.$this->getKey();
    }

    /**
     * Champs jamais journalisés, en plus des attributs masqués.
     *
     * @return list<string>
     */
    protected function auditExcluded(): array
    {
        return [];
    }

    /**
     * Clés étrangères dont on journalise le libellé plutôt que l'identifiant.
     * Format : ['site_id' => [Site::class, 'name']].
     *
     * @return array<string, array{0: class-string<Model>, 1: string}>
     */
    protected function auditRelations(): array
    {
        return [];
    }

    public function addAuditChange(string $field, mixed $old, mixed $new): static
    {
        if ($old !== $new) {
            $this->pendingAuditChanges[$field] = ['old' => $old, 'new' => $new];
        }

        return $this;
    }

    /**
     * Enregistre les modifications en attente si la sauvegarde du modèle n'a
     * produit aucune entrée (cas où seules des relations ont changé).
     */
    public function flushPendingAudit(): void
    {
        if ($this->pendingAuditChanges !== []) {
            $this->writeAudit('updated');
        }
    }

    protected function resolveAuditUpdateAction(): string
    {
        if ($this->wasChanged('status')) {
            $status = $this->getAttributes()['status'] ?? null;
            $previous = $this->getRawOriginal('status');

            if ($status === 'archived') {
                return 'archived';
            }

            if ($previous === 'archived') {
                return 'restored';
            }
        }

        return 'updated';
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    protected function auditableChanges(): array
    {
        $excluded = array_merge(
            ['created_at', 'updated_at', 'created_by', 'updated_by', 'archived_at'],
            $this->getHidden(),
            $this->auditExcluded(),
        );

        $relations = $this->auditRelations();
        $changes = [];

        foreach (array_keys($this->getChanges()) as $key) {
            if (in_array($key, $excluded, true)) {
                continue;
            }

            $old = $this->getRawOriginal($key);
            $new = $this->getAttributes()[$key] ?? null;

            if (isset($relations[$key])) {
                [$class, $column] = $relations[$key];
                $old = $old === null ? null : $class::query()->whereKey($old)->value($column);
                $new = $new === null ? null : $class::query()->whereKey($new)->value($column);
            } else {
                $old = $this->formatAuditValue($key, $old);
                $new = $this->formatAuditValue($key, $new);
            }

            if ($old !== $new) {
                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    protected function formatAuditValue(string $key, mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $cast = $this->getCasts()[$key] ?? null;

        if ($cast !== null && is_string($cast) && enum_exists($cast)) {
            $enum = $cast::tryFrom($raw);

            if ($enum instanceof BackedEnum && method_exists($enum, 'label')) {
                return $enum->label();
            }
        }

        if ($cast === 'boolean' || $cast === 'bool') {
            return (bool) $raw ? 'Oui' : 'Non';
        }

        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d H:i');
        }

        if ($raw instanceof UnitEnum) {
            return $raw instanceof BackedEnum ? $raw->value : $raw->name;
        }

        if (is_array($raw)) {
            return json_encode($raw, JSON_UNESCAPED_UNICODE);
        }

        $value = (string) $raw;

        // Les textes longs sont tronqués : l'historique sert à repérer un
        // changement, pas à conserver une copie intégrale du contenu.
        return mb_strlen($value) > 500 ? mb_substr($value, 0, 500).'…' : $value;
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    protected function writeAudit(string $action, array $changes = []): void
    {
        $changes = array_merge($changes, $this->pendingAuditChanges);
        $this->pendingAuditChanges = [];

        if ($action === 'updated' && $changes === []) {
            return;
        }

        // auditClientId() est fourni par BelongsToClient ou par le modèle lui-même.
        app(AuditLogger::class)->log(
            event: $this->auditType().'.'.$action,
            subject: $this,
            changes: $changes,
            clientId: method_exists($this, 'auditClientId') ? $this->auditClientId() : null,
        );
    }
}
