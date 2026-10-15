<?php

namespace App\Services\Inventory;

use App\Models\Client;
use App\Models\Equipment;
use App\Models\NetworkInterface;
use App\Services\Records\RecordExtensions;
use App\Services\Tags\TagResolver;
use Illuminate\Support\Facades\DB;

/**
 * Enregistrement d'un équipement avec ses interfaces réseau, les champs
 * ajoutés avec le constructeur de fiches et ses étiquettes, en une transaction et une seule
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

    public function __construct(
        private readonly TagResolver $tags,
        private readonly RecordExtensions $extensions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Equipment $equipment, Client $client, array $data): Equipment
    {
        return DB::transaction(function () use ($equipment, $client, $data): Equipment {
            $isNew = ! $equipment->exists;
            $equipment->fill(array_intersect_key($data, array_flip(self::ATTRIBUTES)));

            if ($isNew) {
                $equipment->client()->associate($client);
                $equipment->save();
            }

            if (array_key_exists('interfaces', $data)) {
                $this->syncInterfaces($equipment, $data['interfaces'] ?? [], $isNew);
            }

            $this->extensions->save('equipment', $equipment, $client->id, $data, $isNew);

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
}
