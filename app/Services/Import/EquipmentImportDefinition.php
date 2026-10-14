<?php

namespace App\Services\Import;

use App\Enums\EquipmentStatus;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\NetworkInterface;
use App\Models\User;
use App\Services\Inventory\EquipmentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class EquipmentImportDefinition implements ImportDefinition
{
    private const TEXT_ATTRIBUTES = [
        'name', 'hostname', 'manufacturer', 'model', 'serial_number', 'inventory_number', 'location',
        'os_name', 'os_version', 'firmware_version', 'role', 'description', 'supplier', 'admin_url', 'notes',
    ];

    private const DATE_ATTRIBUTES = ['purchase_date', 'commissioning_date', 'warranty_expires_at', 'end_of_life_at'];

    /** @var Collection<int, EquipmentType>|null */
    private ?Collection $types = null;

    /** @var array<string, int>|null */
    private ?array $sites = null;

    public function __construct(
        private readonly Client $client,
        private readonly EquipmentService $service,
    ) {}

    public function columns(): array
    {
        return [
            'name' => ['label' => 'Nom', 'required' => true, 'example' => 'SRV-AD01', 'aliases' => ['equipement', 'nom equipement']],
            'type' => ['label' => 'Type', 'required' => true, 'example' => 'Serveur virtuel', 'help' => 'Nom d\'un type d\'équipement configuré dans TechIndex.'],
            'hostname' => ['label' => 'Nom d\'hôte', 'required' => false, 'example' => 'srv-ad01', 'aliases' => ['hostname', 'hote']],
            'manufacturer' => ['label' => 'Fabricant', 'required' => false, 'example' => 'Dell', 'aliases' => ['marque', 'constructeur']],
            'model' => ['label' => 'Modèle', 'required' => false, 'example' => 'PowerEdge R650'],
            'serial_number' => ['label' => 'Numéro de série', 'required' => false, 'example' => 'ABC1234', 'help' => 'Sert à détecter les doublons.', 'aliases' => ['n serie', 'serie', 'sn', 'serial']],
            'inventory_number' => ['label' => 'Référence d\'inventaire', 'required' => false, 'example' => 'INV-0042', 'aliases' => ['inventaire']],
            'site' => ['label' => 'Site', 'required' => false, 'example' => 'Siège', 'help' => 'Nom d\'un site existant du client.'],
            'location' => ['label' => 'Emplacement', 'required' => false, 'example' => 'Baie 1 - U12', 'aliases' => ['emplacement physique']],
            'ip_addresses' => ['label' => 'Adresses IP', 'required' => false, 'example' => '192.168.10.10', 'help' => 'Plusieurs adresses séparées par des virgules.', 'aliases' => ['ip', 'adresse ip']],
            'mac_addresses' => ['label' => 'Adresses MAC', 'required' => false, 'example' => '00:11:22:33:44:55', 'aliases' => ['mac', 'adresse mac']],
            'os_name' => ['label' => 'Système d\'exploitation', 'required' => false, 'example' => 'Windows Server', 'aliases' => ['os', 'systeme']],
            'os_version' => ['label' => 'Version du système', 'required' => false, 'example' => '2022', 'aliases' => ['version os']],
            'firmware_version' => ['label' => 'Firmware', 'required' => false, 'example' => ''],
            'role' => ['label' => 'Rôle', 'required' => false, 'example' => 'Contrôleur de domaine'],
            'status' => ['label' => 'Statut', 'required' => false, 'example' => 'En service', 'help' => 'En service, Maintenance, Hors service ou Archivé (défaut : En service).'],
            'purchase_date' => ['label' => 'Date d\'achat', 'required' => false, 'example' => '2024-03-15', 'help' => 'AAAA-MM-JJ ou JJ/MM/AAAA.'],
            'commissioning_date' => ['label' => 'Date de mise en service', 'required' => false, 'example' => '2024-04-01'],
            'warranty_expires_at' => ['label' => 'Fin de garantie', 'required' => false, 'example' => '2029-03-15', 'aliases' => ['garantie']],
            'end_of_life_at' => ['label' => 'Fin de vie', 'required' => false, 'example' => ''],
            'supplier' => ['label' => 'Fournisseur', 'required' => false, 'example' => ''],
            'admin_url' => ['label' => 'URL d\'administration', 'required' => false, 'example' => 'https://192.168.10.10'],
            'description' => ['label' => 'Description', 'required' => false, 'example' => ''],
            'tags' => ['label' => 'Étiquettes', 'required' => false, 'example' => 'critique', 'help' => 'Séparées par des virgules.'],
            'notes' => ['label' => 'Notes', 'required' => false, 'example' => ''],
        ];
    }

    public function duplicateRule(): string
    {
        return 'Un équipement du client est considéré comme existant s\'il a le même numéro de série ; à défaut de numéro de série, le même nom (sans tenir compte des majuscules).';
    }

    public function normalize(array $row): array
    {
        $data = [];

        foreach (self::TEXT_ATTRIBUTES as $attribute) {
            $data[$attribute] = Normalizer::text($row[$attribute] ?? null);
        }

        foreach (self::DATE_ATTRIBUTES as $attribute) {
            $data[$attribute] = Normalizer::date($row[$attribute] ?? null);
        }

        $data['type'] = Normalizer::text($row['type'] ?? null);
        $data['equipment_type_id'] = $data['type'] === null ? null : $this->typeId($data['type']);

        $data['site'] = Normalizer::text($row['site'] ?? null);
        $data['site_id'] = $data['site'] === null ? null : ($this->siteIds()[Normalizer::key($data['site'])] ?? null);

        $status = Normalizer::text($row['status'] ?? null);
        $data['status_raw'] = $status;
        $data['status'] = $status === null ? null : $this->status($status);

        $data['ip_addresses'] = Normalizer::list($row['ip_addresses'] ?? null, splitOnSpaces: true);
        $data['mac_addresses'] = array_map(
            fn (string $mac): string => (string) NetworkInterface::normalizeMac($mac),
            Normalizer::list($row['mac_addresses'] ?? null, splitOnSpaces: true),
        );
        $data['tags'] = Normalizer::list($row['tags'] ?? null);

        return $data;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required'],
            'equipment_type_id' => ['required_with:type'],
            'site_id' => ['required_with:site'],
            'status' => ['required_with:status_raw', 'nullable', Rule::enum(EquipmentStatus::class)],
            'hostname' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:150'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'inventory_number' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:255'],
            'os_name' => ['nullable', 'string', 'max:120'],
            'os_version' => ['nullable', 'string', 'max:120'],
            'firmware_version' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'admin_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'purchase_date' => ['nullable', 'date_format:Y-m-d'],
            'commissioning_date' => ['nullable', 'date_format:Y-m-d'],
            'warranty_expires_at' => ['nullable', 'date_format:Y-m-d'],
            'end_of_life_at' => ['nullable', 'date_format:Y-m-d'],
            'ip_addresses' => ['array', 'max:32'],
            'ip_addresses.*' => ['ip'],
            'mac_addresses' => ['array', 'max:32'],
            'mac_addresses.*' => ['mac_address'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'equipment_type_id.required_with' => 'Type d\'équipement inconnu. Types acceptés : '.$this->types()->pluck('name')->implode(', ').'.',
            'site_id.required_with' => 'Ce site n\'existe pas pour ce client.',
            'status.required_with' => 'Statut inconnu (En service, Maintenance, Hors service ou Archivé).',
            'hostname.regex' => 'Le nom d\'hôte ne peut contenir que des lettres, chiffres, points, tirets et tirets bas.',
            '*.date_format' => 'La date doit être au format AAAA-MM-JJ ou JJ/MM/AAAA.',
            'ip_addresses.*.ip' => 'Adresse IP invalide.',
            'mac_addresses.*.mac_address' => 'Adresse MAC invalide.',
        ];
    }

    public function duplicateKey(array $data): ?string
    {
        if ($data['serial_number'] !== null) {
            return 'sn:'.mb_strtolower($data['serial_number']);
        }

        return $data['name'] === null ? null : 'name:'.mb_strtolower($data['name']);
    }

    public function findExisting(array $data): ?Model
    {
        $query = $this->client->equipment();

        if ($data['serial_number'] !== null) {
            return $query->whereRaw('LOWER(serial_number) = ?', [mb_strtolower($data['serial_number'])])->first();
        }

        if ($data['name'] === null) {
            return null;
        }

        return $query->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->first();
    }

    public function canUpdate(Model $existing, User $user): bool
    {
        return $user->can('update', $existing);
    }

    public function create(array $data, User $user): Model
    {
        return $this->service->save(new Equipment, $this->client, [
            ...$this->attributes($data, onlyFilled: false),
            'status' => $data['status'] ?? EquipmentStatus::InService->value,
            'interfaces' => $this->interfaces($data),
            'tags' => $data['tags'],
        ], collect());
    }

    public function update(Model $existing, array $data, User $user): void
    {
        /** @var Equipment $existing */
        $payload = $this->attributes($data, onlyFilled: true);

        if ($data['status'] !== null) {
            $payload['status'] = $data['status'];
        }

        if ($data['ip_addresses'] !== [] || $data['mac_addresses'] !== []) {
            $payload['interfaces'] = $this->interfaces($data);
        }

        if ($data['tags'] !== []) {
            $payload['tags'] = $data['tags'];
        }

        $this->service->save($existing, $this->client, $payload, collect());
    }

    public function rowLabel(array $data): string
    {
        return trim(($data['name'] ?? '?').($data['serial_number'] ? ' – S/N '.$data['serial_number'] : ''));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, bool $onlyFilled): array
    {
        $attributes = array_intersect_key($data, array_flip([...self::TEXT_ATTRIBUTES, ...self::DATE_ATTRIBUTES, 'equipment_type_id', 'site_id']));

        return $onlyFilled ? array_filter($attributes, fn ($value): bool => $value !== null) : $attributes;
    }

    /**
     * Associe adresses IP et MAC par position (1re IP avec 1re MAC...).
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function interfaces(array $data): array
    {
        $count = max(count($data['ip_addresses']), count($data['mac_addresses']));
        $interfaces = [];

        for ($i = 0; $i < $count; $i++) {
            $interfaces[] = [
                'ip_address' => $data['ip_addresses'][$i] ?? null,
                'mac_address' => $data['mac_addresses'][$i] ?? null,
                'is_primary' => $i === 0,
            ];
        }

        return $interfaces;
    }

    private function typeId(string $value): ?int
    {
        $key = Normalizer::key($value);

        return $this->types()->first(fn (EquipmentType $type): bool => Normalizer::key($type->name) === $key || Normalizer::key($type->slug) === $key)?->id;
    }

    private function status(string $value): ?string
    {
        $key = Normalizer::key($value);

        foreach (EquipmentStatus::cases() as $status) {
            if ($key === Normalizer::key($status->value) || $key === Normalizer::key($status->label())) {
                return $status->value;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, EquipmentType>
     */
    private function types(): Collection
    {
        return $this->types ??= EquipmentType::query()->orderBy('name')->get(['id', 'name', 'slug']);
    }

    /**
     * @return array<string, int>
     */
    private function siteIds(): array
    {
        return $this->sites ??= $this->client->sites()->get(['id', 'name'])
            ->mapWithKeys(fn ($site): array => [Normalizer::key($site->name) => $site->id])
            ->all();
    }
}
