<?php

namespace Tests\Concerns;

use App\Models\RecordField;
use App\Models\RecordType;
use App\Services\Records\RecordTypeManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outils des tests du constructeur de fiches. Ces tests modifient la
 * structure de la base (tables cx_...) : ils utilisent CommittedDataTestCase
 * et suppriment les tables créées à la fin de chaque test.
 */
trait InteractsWithRecordBuilder
{
    protected function dropRecordTables(): void
    {
        foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $table) {
            if (str_starts_with($table, 'cx_')) {
                Schema::drop($table);
            }
        }

        // La liste des tables à vider (DatabaseTruncation) est mise en cache : elle est recalculée.
        if (property_exists(static::class, 'allTables')) {
            static::$allTables = [];
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createRecordType(string $table = 'contrats', array $overrides = []): RecordType
    {
        return app(RecordTypeManager::class)->createType([
            'name' => 'Contrat',
            'plural_name' => 'Contrats',
            'icon' => 'file-text',
            'table' => $table,
            'title_label' => 'Référence',
            'tab_after' => 'licenses',
            'settings' => ['has_site' => true, 'has_tags' => true, 'has_attachments' => true],
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function addRecordField(RecordType $type, string $key, string $fieldType, array $data = []): RecordField
    {
        return app(RecordTypeManager::class)->addField($type, [
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'key' => $key,
            'type' => $fieldType,
            ...$data,
        ]);
    }
}
