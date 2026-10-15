<?php

namespace App\Services\Records;

use App\Enums\RecordFieldType;
use App\Models\Attachment;
use App\Models\RecordField;
use App\Models\RecordType;
use App\Services\Attachments\AttachmentStorage;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Opérations du constructeur de fiches : chaque changement de structure
 * (table, colonne) est exécuté par RecordSchema, puis les métadonnées sont
 * enregistrées. En cas d'échec, l'opération est annulée des deux côtés.
 */
final class RecordTypeManager
{
    public function __construct(
        private readonly RecordSchema $schema,
        private readonly RecordValues $values,
        private readonly AuditLogger $audit,
        private readonly AttachmentStorage $attachments,
    ) {}

    /**
     * Crée une fiche, sa table cx_<nom> et son champ titre.
     *
     * @param  array<string, mixed>  $data
     */
    public function createType(array $data): RecordType
    {
        return $this->schema->run(function () use ($data): RecordType {
            $suffix = (string) $data['table'];

            $type = new RecordType([
                'name' => $data['name'],
                'plural_name' => $data['plural_name'],
                'description' => $data['description'] ?? null,
                'icon' => $data['icon'] ?? 'clipboard-list',
                'tab_after' => $data['tab_after'] ?? null,
                'is_active' => true,
                'sections' => [['key' => RecordType::DEFAULT_SECTION, 'title' => 'Informations', 'columns' => 2]],
                'settings' => $data['settings'] ?? [],
                'sort_order' => (int) RecordType::query()->custom()->max('sort_order') + 10,
            ]);
            $type->forceFill([
                'key' => str_replace('_', '-', $suffix),
                'table_name' => RecordSchema::TABLE_PREFIX.$suffix,
                'is_system' => false,
            ]);
            $type->save();

            try {
                $this->schema->createTable($type);
            } catch (Throwable $exception) {
                $type->delete();

                throw $exception;
            }

            $this->addFieldUnlocked($type, [
                'label' => $data['title_label'] ?? 'Nom',
                'type' => RecordFieldType::Text->value,
                'key' => 'nom',
                'is_required' => true,
                'in_list' => true,
                'is_searchable' => true,
                'width' => 'full',
            ], isTitle: true);

            return $type;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addField(RecordType $type, array $data): RecordField
    {
        return $this->schema->run(fn (): RecordField => $this->addFieldUnlocked($type, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addFieldUnlocked(RecordType $type, array $data, bool $isTitle = false): RecordField
    {
        $key = (string) $data['key'];
        $sections = array_column($type->sectionList(), 'key');
        $section = in_array($data['section'] ?? null, $sections, true) ? $data['section'] : $sections[0];

        if ($type->fields()->where(fn ($query) => $query->where('key', $key)->orWhere('column_name', $key))->exists()) {
            throw ValidationException::withMessages(['key' => 'Ce nom technique est déjà utilisé dans cette fiche.']);
        }

        $field = new RecordField([
            'label' => $data['label'],
            'type' => $data['type'],
            'options' => $data['options'] ?? null,
            'help_text' => $data['help_text'] ?? null,
            'is_required' => (bool) ($data['is_required'] ?? false),
            'is_unique' => (bool) ($data['is_unique'] ?? false),
            'in_list' => (bool) ($data['in_list'] ?? false),
            'is_searchable' => (bool) ($data['is_searchable'] ?? false),
            'section' => $section,
            'width' => $data['width'] ?? '1',
            'visibility' => $data['visibility'] ?? null,
            'sort_order' => (int) $type->fields()->where('section', $section)->max('sort_order') + 10,
        ]);
        $field->forceFill([
            'record_type_id' => $type->id,
            'key' => $key,
            'column_name' => $key,
            'is_title' => $isTitle,
        ]);
        $field->save();

        try {
            $this->schema->addColumn($type, $field);
        } catch (Throwable $exception) {
            $field->delete();

            throw $exception;
        }

        $this->values->forget($type);
        $type->touch();

        return $field;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateField(RecordField $field, array $data): RecordField
    {
        return $this->schema->run(function () use ($field, $data): RecordField {
            $type = $field->recordType;
            $from = $field->type;
            $to = RecordFieldType::from((string) $data['type']);
            $oldTarget = (string) $field->option('target');
            $newTarget = (string) (($data['options'] ?? [])['target'] ?? '');
            $targetChanged = $to === RecordFieldType::Reference && $from === RecordFieldType::Reference && $oldTarget !== $newTarget;

            if ($field->is_title && $to->storage() !== 'string') {
                throw ValidationException::withMessages(['type' => 'Le champ titre doit rester un texte court.']);
            }

            $hasData = $this->schema->columnHasData($type, $field);

            if ($hasData && ($targetChanged || ! $from->canConvertTo($to))) {
                throw ValidationException::withMessages([
                    'type' => 'Des valeurs sont déjà saisies : ce changement les rendrait invalides. Créez plutôt un nouveau champ.',
                ]);
            }

            $field->fill([
                'label' => $data['label'],
                'type' => $to,
                'options' => $data['options'] ?? null,
                'help_text' => $data['help_text'] ?? null,
                'is_required' => (bool) ($data['is_required'] ?? false),
                'is_unique' => (bool) ($data['is_unique'] ?? false),
                'in_list' => (bool) ($data['in_list'] ?? false),
                'is_searchable' => (bool) ($data['is_searchable'] ?? false),
                'width' => $data['width'] ?? $field->width,
                'visibility' => $data['visibility'] ?? null,
            ]);

            if ($from !== $to || $targetChanged) {
                $this->schema->changeColumn($type, $field, $from);
            }

            $field->save();
            $this->values->forget($type);
            $type->touch();

            return $field;
        });
    }

    public function deleteField(RecordField $field): void
    {
        if ($field->is_title) {
            throw ValidationException::withMessages(['field' => 'Le champ titre ne peut pas être supprimé.']);
        }

        $this->schema->run(function () use ($field): void {
            $type = $field->recordType;

            // Les conditions d'affichage qui dépendaient de ce champ sont retirées.
            foreach ($type->fields()->whereKeyNot($field->id)->get() as $other) {
                if (($other->visibility['field'] ?? null) === $field->key) {
                    $other->update(['visibility' => null]);
                }
            }

            if ($type->setting('sort_field') === $field->key) {
                $type->update(['settings' => [...($type->settings ?? []), 'sort_field' => null]]);
            }

            $this->schema->dropColumn($type, $field);
            $field->delete();
            $this->values->forget($type);
            $type->touch();
        });
    }

    /**
     * Enregistre les sections et la position, la section et la largeur des champs.
     *
     * @param  list<array{key: string, title: string, description?: string|null, columns: int}>  $sections
     * @param  list<array{id: int, section: string, width: string}>  $placements  dans l'ordre d'affichage
     */
    public function saveLayout(RecordType $type, array $sections, array $placements): void
    {
        DB::transaction(function () use ($type, $sections, $placements): void {
            $keys = array_column($sections, 'key');
            $order = [];

            foreach ($placements as $index => $placement) {
                $order[(int) $placement['id']] = [
                    'section' => in_array($placement['section'], $keys, true) ? $placement['section'] : $keys[0],
                    'width' => in_array($placement['width'], RecordField::WIDTHS, true) ? $placement['width'] : '1',
                    'sort_order' => ($index + 1) * 10,
                ];
            }

            foreach ($type->fields()->get() as $field) {
                if (isset($order[$field->id])) {
                    // Mise à jour directe : la disposition n'est pas un changement de données.
                    RecordField::query()->whereKey($field->id)->update($order[$field->id]);
                } elseif (! in_array($field->section, $keys, true)) {
                    RecordField::query()->whereKey($field->id)->update(['section' => $keys[0]]);
                }
            }

            $type->update(['sections' => array_map(fn (array $section): array => [
                'key' => $section['key'],
                'title' => $section['title'],
                'description' => $section['description'] ?? null,
                'columns' => max(1, min(3, (int) $section['columns'])),
            ], $sections)]);

            $this->audit->log('record_type.layout_updated', $type, metadata: [
                'sections' => count($sections),
                'champs' => count($placements),
            ]);
        });

        $this->values->forget($type);
    }

    /**
     * Supprime une fiche créée, ses enregistrements, ses fichiers et sa table.
     */
    public function deleteType(RecordType $type): void
    {
        if ($type->is_system) {
            throw ValidationException::withMessages(['type' => 'Une fiche par défaut ne peut pas être supprimée.']);
        }

        $users = RecordField::query()
            ->where('type', RecordFieldType::Reference->value)
            ->where('record_type_id', '!=', $type->id)
            ->with('recordType:id,name')
            ->get()
            ->filter(fn (RecordField $field): bool => $field->option('target') === 'record:'.$type->id);

        if ($users->isNotEmpty()) {
            throw ValidationException::withMessages([
                'type' => 'Cette fiche est désignée par le champ « '.$users->first()->label.' » de la fiche « '
                    .$users->first()->recordType->name.' » : supprimez d\'abord ce champ.',
            ]);
        }

        $this->schema->run(function () use ($type): void {
            $ids = $type->records()->pluck('id');

            foreach ($ids->chunk(500) as $chunk) {
                DB::table('taggables')->where('taggable_type', 'custom_record')->whereIn('taggable_id', $chunk)->delete();

                Attachment::query()->where('attachable_type', 'custom_record')->whereIn('attachable_id', $chunk)->get()
                    ->each(fn (Attachment $attachment) => $this->attachments->delete($attachment));
            }

            $this->schema->dropTable($type);
            $type->delete();
        });
    }
}
