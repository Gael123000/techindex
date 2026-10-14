<?php

namespace App\Http\Requests;

use App\Enums\EquipmentStatus;
use App\Models\Client;
use App\Models\CustomField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Les lignes d'interface entièrement vides sont ignorées.
        $interfaces = collect($this->input('interfaces', []))
            ->filter(fn ($row): bool => is_array($row) && (filled($row['ip_address'] ?? null) || filled($row['mac_address'] ?? null) || filled($row['label'] ?? null)))
            ->values()
            ->all();

        $this->merge(['interfaces' => $interfaces]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Client $client */
        $client = $this->route('client');

        $rules = [
            'equipment_type_id' => ['required', 'integer', Rule::exists('equipment_types', 'id')],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('client_id', $client->id)],
            'name' => ['required', 'string', 'max:255'],
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
            'status' => ['required', Rule::enum(EquipmentStatus::class)],
            'purchase_date' => ['nullable', 'date'],
            'commissioning_date' => ['nullable', 'date'],
            'warranty_expires_at' => ['nullable', 'date'],
            'end_of_life_at' => ['nullable', 'date'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'admin_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            'interfaces' => ['nullable', 'array', 'max:32'],
            'interfaces.*.label' => ['nullable', 'string', 'max:100'],
            'interfaces.*.ip_address' => ['nullable', 'ip'],
            'interfaces.*.mac_address' => ['nullable', 'mac_address'],
            'interfaces.*.subnet_id' => ['nullable', 'integer', Rule::exists('subnets', 'id')->where('client_id', $client->id)],
            'interfaces.*.is_primary' => ['boolean'],
            'interfaces.*.notes' => ['nullable', 'string', 'max:255'],
            'custom_fields' => ['nullable', 'array'],
        ];

        foreach ($this->customFields() as $field) {
            $rules['custom_fields.'.$field->id] = $field->valueRules();
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->customFields()
            ->mapWithKeys(fn (CustomField $field): array => ['custom_fields.'.$field->id => mb_strtolower($field->label)])
            ->all();
    }

    public function messages(): array
    {
        return [
            'hostname.regex' => 'Le nom d\'hôte ne peut contenir que des lettres, chiffres, points, tirets et tirets bas.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $primary = collect($this->input('interfaces', []))->filter(fn ($row): bool => (bool) ($row['is_primary'] ?? false))->count();

                if ($primary > 1) {
                    $validator->errors()->add('interfaces', 'Une seule adresse peut être principale.');
                }
            },
        ];
    }

    /**
     * Champs personnalisés applicables au type choisi.
     *
     * @return Collection<int, CustomField>
     */
    public function customFields(): Collection
    {
        $typeId = $this->integer('equipment_type_id') ?: null;

        return once(fn () => CustomField::query()->forType($typeId)->get())->values();
    }
}
