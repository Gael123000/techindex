<?php

namespace App\Services\Records;

use App\Enums\RecordFieldType;
use App\Models\RecordField;
use App\Models\RecordType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Structure SQL des fiches : création des tables (cx_<nom>, cx_ext_<fiche>)
 * et de leurs colonnes typées.
 *
 * Sécurité :
 * - les noms de tables et de colonnes sont contrôlés par une expression
 *   stricte (minuscules, chiffres, « _ ») et toujours préfixés « cx_ » : une
 *   fiche ne peut ni viser ni écraser une table de TechIndex ;
 * - les requêtes sont construites par le Schema Builder de Laravel, qui
 *   entoure chaque identifiant de guillemets ;
 * - MariaDB valide implicitement une transaction en cours lors d'un
 *   changement de structure : ces opérations refusent donc de s'exécuter
 *   dans une transaction, et un verrou les exécute une à une.
 */
final class RecordSchema
{
    public const TABLE_PREFIX = 'cx_';

    public const EXTENSION_PREFIX = 'cx_ext_';

    /** Nom de table (sans préfixe) ou de colonne accepté. */
    private const IDENTIFIER = '/^[a-z][a-z0-9_]{1,39}$/';

    /** Colonnes réservées par TechIndex. */
    public const RESERVED_COLUMNS = [
        'id', 'record_id', 'client_id', 'site_id', 'record_type_id', 'title', 'status', 'search_text',
        'created_at', 'updated_at', 'created_by', 'updated_by', 'deleted_at',
    ];

    public static function isValidIdentifier(string $name): bool
    {
        return preg_match(self::IDENTIFIER, $name) === 1 && ! str_contains($name, '__') && ! str_ends_with($name, '_');
    }

    public static function isValidColumn(string $name): bool
    {
        return self::isValidIdentifier($name) && ! in_array($name, self::RESERVED_COLUMNS, true);
    }

    /**
     * Identifiant proposé à partir d'un libellé : « Date de résiliation » → date_de_resiliation.
     */
    public static function suggestIdentifier(string $label, string $fallback = 'champ'): string
    {
        $name = trim((string) preg_replace('/_+/', '_', Str::slug(Str::ascii($label), '_')), '_');

        if ($name === '' || ! ctype_alpha($name[0])) {
            $name = $fallback.($name === '' ? '' : '_'.$name);
        }

        $name = rtrim(substr($name, 0, 40), '_');

        return self::isValidIdentifier($name) ? $name : $fallback;
    }

    /**
     * Exécute une modification de structure, hors transaction et sous verrou.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('Une modification de structure ne peut pas être exécutée dans une transaction.');
        }

        return Cache::lock('techindex:record-schema', 120)->block(30, $callback);
    }

    public function createTable(RecordType $type): void
    {
        $this->assertTable($type);

        if (Schema::hasTable($type->table_name)) {
            throw new InvalidArgumentException("La table {$type->table_name} existe déjà.");
        }

        $parent = $type->is_system ? SystemRecordTypes::tableFor((string) $type->entity) : 'custom_records';

        if ($parent === null) {
            throw new InvalidArgumentException('Fiche par défaut inconnue.');
        }

        Schema::create($type->table_name, function (Blueprint $table) use ($type, $parent): void {
            // Une ligne par enregistrement, supprimée avec lui.
            $table->unsignedBigInteger('record_id')->primary();
            $table->foreign('record_id', 'cxfk_t'.$type->id)->references('id')->on($parent)->cascadeOnDelete();
        });
    }

    public function addColumn(RecordType $type, RecordField $field): void
    {
        $this->assertTable($type);
        $this->assertColumn($field->column_name);

        if (! Schema::hasTable($type->table_name)) {
            $this->createTable($type);
        }

        if (Schema::hasColumn($type->table_name, $field->column_name)) {
            throw new InvalidArgumentException("La colonne {$field->column_name} existe déjà.");
        }

        Schema::table($type->table_name, function (Blueprint $table) use ($field): void {
            $this->define($table, $field->column_name, $field->type)->nullable();
            $this->addForeignKey($table, $field);
        });
    }

    /**
     * Change le type d'une colonne. Les conversions qui perdraient des
     * données sont refusées en amont (RecordFieldType::canConvertTo()).
     */
    public function changeColumn(RecordType $type, RecordField $field, RecordFieldType $from): void
    {
        $this->assertTable($type);
        $this->assertColumn($field->column_name);

        if ($from->sqlType() === $field->type->sqlType() && ! $this->hasForeignKey($from) && ! $this->hasForeignKey($field->type)) {
            return;
        }

        Schema::table($type->table_name, function (Blueprint $table) use ($field, $from): void {
            if ($this->hasForeignKey($from)) {
                $table->dropForeign('cxfk_'.$field->id);
            }
        });

        Schema::table($type->table_name, function (Blueprint $table) use ($field): void {
            $this->define($table, $field->column_name, $field->type)->nullable()->change();
            $this->addForeignKey($table, $field);
        });
    }

    public function dropColumn(RecordType $type, RecordField $field): void
    {
        $this->assertTable($type);
        $this->assertColumn($field->column_name);

        if (! Schema::hasTable($type->table_name) || ! Schema::hasColumn($type->table_name, $field->column_name)) {
            return;
        }

        Schema::table($type->table_name, function (Blueprint $table) use ($field): void {
            if ($this->hasForeignKey($field->type)) {
                $table->dropForeign('cxfk_'.$field->id);
            }

            $table->dropColumn($field->column_name);
        });
    }

    public function dropTable(RecordType $type): void
    {
        $this->assertTable($type);
        Schema::dropIfExists($type->table_name);
    }

    public function columnHasData(RecordType $type, RecordField $field): bool
    {
        return Schema::hasTable($type->table_name)
            && Schema::hasColumn($type->table_name, $field->column_name)
            && DB::table($type->table_name)->whereNotNull($field->column_name)->exists();
    }

    /**
     * Description de la table pour l'onglet « Base de données ».
     *
     * @return array{table: string, exists: bool, rows: int, columns: list<array{name: string, type: string, nullable: bool}>}
     */
    public function describe(RecordType $type): array
    {
        $this->assertTable($type);

        if (! Schema::hasTable($type->table_name)) {
            return ['table' => $type->table_name, 'exists' => false, 'rows' => 0, 'columns' => []];
        }

        return [
            'table' => $type->table_name,
            'exists' => true,
            'rows' => DB::table($type->table_name)->count(),
            'columns' => array_map(fn (array $column): array => [
                'name' => (string) $column['name'],
                'type' => (string) $column['type'],
                'nullable' => (bool) $column['nullable'],
            ], Schema::getColumns($type->table_name)),
        ];
    }

    private function define(Blueprint $table, string $column, RecordFieldType $type): ColumnDefinition
    {
        return match ($type) {
            RecordFieldType::Text, RecordFieldType::Email => $table->string($column, 255),
            RecordFieldType::Textarea => $table->text($column),
            RecordFieldType::RichText => $table->mediumText($column),
            RecordFieldType::Integer => $table->bigInteger($column),
            RecordFieldType::Decimal => $table->decimal($column, 20, 4),
            RecordFieldType::Currency => $table->decimal($column, 14, 2),
            RecordFieldType::Date => $table->date($column),
            RecordFieldType::DateTime => $table->dateTime($column),
            RecordFieldType::Boolean => $table->boolean($column),
            RecordFieldType::Select => $table->string($column, 150),
            RecordFieldType::MultiSelect => $table->json($column),
            RecordFieldType::Url => $table->string($column, 500),
            RecordFieldType::Phone => $table->string($column, 50),
            RecordFieldType::Ip => $table->string($column, 45),
            RecordFieldType::Cidr => $table->string($column, 50),
            RecordFieldType::Reference, RecordFieldType::User => $table->unsignedBigInteger($column),
        };
    }

    private function hasForeignKey(RecordFieldType $type): bool
    {
        return $type === RecordFieldType::Reference || $type === RecordFieldType::User;
    }

    /**
     * Clé étrangère d'un lien : la valeur est effacée si l'élément visé est supprimé.
     */
    private function addForeignKey(Blueprint $table, RecordField $field): void
    {
        if (! $this->hasForeignKey($field->type)) {
            return;
        }

        $target = $field->type === RecordFieldType::User ? 'users' : RecordReferences::tableFor((string) $field->option('target'));

        if ($target === null) {
            throw new InvalidArgumentException('Cible du lien inconnue.');
        }

        $table->foreign($field->column_name, 'cxfk_'.$field->id)->references('id')->on($target)->nullOnDelete();
    }

    private function assertTable(RecordType $type): void
    {
        $prefix = $type->is_system ? self::EXTENSION_PREFIX : self::TABLE_PREFIX;
        $name = (string) $type->table_name;

        if (! str_starts_with($name, $prefix) || ! self::isValidIdentifier(substr($name, strlen($prefix)))) {
            throw new InvalidArgumentException("Nom de table refusé : {$name}.");
        }

        if (! $type->is_system && str_starts_with($name, self::EXTENSION_PREFIX)) {
            throw new InvalidArgumentException("Nom de table refusé : {$name}.");
        }
    }

    private function assertColumn(string $column): void
    {
        if (! self::isValidColumn($column)) {
            throw new InvalidArgumentException("Nom de colonne refusé : {$column}.");
        }
    }
}
