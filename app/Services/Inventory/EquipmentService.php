<?php

namespace App\Services\Inventory;

use App\Enums\CustomFieldType;
use App\Models\Client;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Equipment;
use App\Models\NetworkInterface;
use App\Services\Tags\TagResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Enregistrement d'un équipement avec ses interfaces réseau, ses champs
 * personnalisés typés et ses étiquettes, en une transaction et une seule
 * entrée d'historique regroupant tous les changements.
 */
class EquipmentService
{
    private const ATTRIBUTES = [
        'equipment_type_id', 'site_id', 'name', 'hostname', 'manufacturer', 'model', 'serial_number',
        'inventory_number', 'location', 'os_name', 'os_version', 'firmware_version', 'role', 'description',
        'status', 'purchase_date', 'commissioning_date', 'warranty_expires_at', 'end_of_life_at', 'supplier',
        'admin_url', 'notes',
    ];

    public function __construct(private readonly TagResolver $tags) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, CustomField>  $fields
     */
    public function save(Equipment $equipment, Client $client, array $data, Collection $fields): Equipment
    {
        return DB::transaction(function () use ($equipment, $client, $data, $fields): Equipment {
            $isNew = ! $equipment->exists;
            $equipment->fill(array_intersect_key($data, array_flip(self::ATTRIBUTES)));

            if ($isNew) {
                $equipment->client()->associate($client);
                $equipment->save();
            }

            if (array_key_exists('interfaces', $data)) {
                $this->syncInterfaces($equipment, $data['interfaces'] ?? [], $isNew);
            }

            if (array_key_exists('custom_fields', $data)) {
                $this->syncCustomFields($equipment, $data['custom_fields'] ?? [], $fields, $isNew);
            }

            if (array_key_exists('tags', $data)) {
                $isNew
                    ? $equipment->tags()->sync($this->tags->idsFor($data['tags']))
                    : $equipment->syncTagsWithAudit($this->tags->idsFor($data['tags']));
            }

            if (! $isNew) {
                $equipment->save();
                $equipment->flushPendingAudit();
            }

            return $equipment;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncInterfaces(Equipment $equipment, array $rows, bool $isNew): void
    {
        $before = $isNew ? null : $equipment->addressSummary();

        $equipment->interfaces()->delete();

        foreach ($rows as $index => $row) {
            $equipment->interfaces()->create([
                'label' => $row['label'] ?? null,
                'ip_address' => $row['ip_address'] ?? null,
                'mac_address' => NetworkInterface::normalizeMac($row['mac_address'] ?? null),
                'subnet_id' => $row['subnet_id'] ?? null,
                'is_primary' => (bool) ($row['is_primary'] ?? false) || (count($rows) === 1 && $index === 0),
                'notes' => $row['notes'] ?? null,
            ]);
        }

        if (! $isNew) {
            $equipment->addAuditChange('interfaces', $before, $equipment->addressSummary());
        }
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  Collection<int, CustomField>  $fields
     */
    private function syncCustomFields(Equipment $equipment, array $values, Collection $fields, bool $isNew): void
    {
        // Seuls les champs applicables au type sont modifiés : les valeurs des
        // champs d'un ancien type sont conservées pour ne rien perdre.
        $existing = $equipment->customValues()->get()->keyBy('custom_field_id');

        foreach ($fields as $field) {
            $raw = $values[$field->id] ?? null;
            $current = $existing->get($field->id);
            $old = $current?->displayValue($field->type);

            if ($raw === null || $raw === '') {
                if ($current !== null) {
                    $current->delete();
                }
                $new = null;
            } else {
                $column = $field->type->column();
                $value = match ($field->type) {
                    CustomFieldType::Boolean => filter_var($raw, FILTER_VALIDATE_BOOL),
                    CustomFieldType::Number => (float) $raw,
                    default => (string) $raw,
                };

                $record = $current ?? new CustomFieldValue(['custom_field_id' => $field->id, 'equipment_id' => $equipment->id]);
                $record->fill(['value_text' => null, 'value_number' => null, 'value_date' => null, 'value_boolean' => null]);
                $record->setAttribute($column, $value);
                $record->save();
                $new = $record->displayValue($field->type);
            }

            if (! $isNew && $old !== $new) {
                $equipment->addAuditChange('Champ « '.$field->label.' »', $old, $new);
            }
        }
    }
}
