<?php

use App\Enums\RecordFieldType;
use App\Models\RecordField;
use App\Models\RecordType;
use App\Services\Records\RecordSchema;
use App\Services\Records\SystemRecordTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fiches par défaut du constructeur, puis reprise des anciens champs
 * personnalisés des équipements :
 * - chaque champ devient un champ ajouté à la fiche « Équipements », stocké
 *   dans une colonne de cx_ext_equipment ;
 * - un champ propre à un type d'équipement reçoit la condition d'affichage
 *   « Type d'équipement = ce type » ;
 * - les valeurs sont recopiées, puis les anciennes tables supprimées.
 */
return new class extends Migration
{
    public function up(): void
    {
        SystemRecordTypes::sync();

        if (! Schema::hasTable('custom_fields')) {
            return;
        }

        $legacy = DB::table('custom_fields')->orderByRaw('equipment_type_id IS NOT NULL')->orderBy('sort_order')->orderBy('id')->get();
        $type = RecordType::query()->where('entity', 'equipment')->firstOrFail();
        $schema = app(RecordSchema::class);
        $fields = [];
        $used = RecordField::query()->where('record_type_id', $type->id)->pluck('column_name')->all();

        foreach ($legacy as $index => $old) {
            $column = RecordSchema::suggestIdentifier((string) ($old->key ?: $old->label));

            if (! RecordSchema::isValidColumn($column)) {
                $column = 'champ_'.$old->id;
            }

            $base = substr($column, 0, 34);
            $suffix = 2;

            while (in_array($column, $used, true)) {
                $column = $base.'_'.$suffix++;
            }

            $used[] = $column;
            $longText = $old->type === 'text'
                && DB::table('custom_field_values')->where('custom_field_id', $old->id)->whereRaw('CHAR_LENGTH(value_text) > 255')->exists();

            $fieldType = match ($old->type) {
                'number' => RecordFieldType::Decimal,
                'date' => RecordFieldType::Date,
                'boolean' => RecordFieldType::Boolean,
                'select' => RecordFieldType::Select,
                'url' => RecordFieldType::Url,
                default => $longText ? RecordFieldType::Textarea : RecordFieldType::Text,
            };

            $choices = $old->type === 'select' ? array_values(array_map('strval', (array) json_decode((string) $old->options, true))) : [];

            $field = new RecordField([
                'label' => $old->label,
                'type' => $fieldType,
                'options' => $choices === [] ? null : ['choices' => $choices],
                'help_text' => $old->help_text,
                'is_required' => (bool) $old->is_required && $fieldType !== RecordFieldType::Boolean,
                'in_list' => false,
                'is_searchable' => in_array($fieldType, [RecordFieldType::Text, RecordFieldType::Textarea, RecordFieldType::Select], true),
                'section' => RecordType::DEFAULT_SECTION,
                'width' => $fieldType === RecordFieldType::Textarea ? 'full' : '1',
                'sort_order' => ($index + 1) * 10,
                'visibility' => $old->equipment_type_id === null
                    ? null
                    : ['field' => 'equipment_type_id', 'operator' => 'equals', 'value' => [(string) $old->equipment_type_id]],
            ]);
            $field->forceFill(['record_type_id' => $type->id, 'key' => $column, 'column_name' => $column, 'is_title' => false]);
            $field->save();

            $schema->addColumn($type, $field);
            $fields[$old->id] = $field;
        }

        foreach (DB::table('custom_field_values')->orderBy('id')->cursor() as $value) {
            $field = $fields[$value->custom_field_id] ?? null;

            if ($field === null) {
                continue;
            }

            $stored = match ($field->type) {
                RecordFieldType::Decimal => $value->value_number,
                RecordFieldType::Date => $value->value_date,
                RecordFieldType::Boolean => $value->value_boolean,
                default => $value->value_text,
            };

            if ($stored !== null) {
                DB::table($type->table_name)->updateOrInsert(['record_id' => $value->equipment_id], [$field->column_name => $stored]);
            }
        }

        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
    }

    public function down(): void
    {
        // Reprise de données : pas de retour automatique (les champs restent dans le constructeur).
    }
};
