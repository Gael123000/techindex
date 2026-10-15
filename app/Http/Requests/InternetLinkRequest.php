<?php

namespace App\Http\Requests;

use App\Enums\InternetConnectionMode;
use App\Enums\InternetLinkRole;
use App\Enums\InternetLinkStatus;
use App\Enums\InternetTechnology;
use App\Http\Requests\Concerns\ValidatesRecordExtension;
use App\Models\Client;
use App\Models\InternetLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InternetLinkRequest extends FormRequest
{
    use ValidatesRecordExtension;

    public function authorize(): bool
    {
        // Contrôle effectué dans le contrôleur (politique ClientResourcePolicy).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Client $client */
        $client = $this->route('client');
        /** @var InternetLink|null $link */
        $link = $this->route('internet_link');

        return [
            // Un opérateur masqué du catalogue reste accepté s'il est déjà celui du lien.
            'isp_id' => ['nullable', 'integer', 'required_without:operator_name', Rule::exists('isps', 'id')->where(
                fn ($query) => $query->where('is_active', true)->when($link?->isp_id, fn ($query, int $current) => $query->orWhere('id', $current)),
            )],
            'operator_name' => ['nullable', 'string', 'max:120', 'required_without:isp_id'],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('client_id', $client->id)],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(InternetLinkRole::class)],
            'technology' => ['nullable', Rule::enum(InternetTechnology::class)],
            'status' => ['required', Rule::enum(InternetLinkStatus::class)],
            'download_mbps' => ['nullable', 'integer', 'between:1,4000000'],
            'upload_mbps' => ['nullable', 'integer', 'between:1,4000000'],
            'guaranteed_mbps' => ['nullable', 'integer', 'between:1,4000000'],
            'customer_number' => ['nullable', 'string', 'max:120'],
            'contract_reference' => ['nullable', 'string', 'max:150'],
            'line_reference' => ['nullable', 'string', 'max:150'],
            'connection_mode' => ['nullable', Rule::enum(InternetConnectionMode::class)],
            'connection_username' => ['nullable', 'string', 'max:255'],
            'wan_vlan' => ['nullable', 'integer', 'between:1,4094'],
            'public_ips' => ['nullable', 'string', 'max:500'],
            'wan_gateway' => ['nullable', 'ip'],
            'dns_servers' => ['nullable', 'string', 'max:255'],
            'gtr' => ['nullable', 'string', 'max:120'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'commissioned_at' => ['nullable', 'date'],
            'commitment_ends_at' => ['nullable', 'date'],
            'monthly_cost' => ['nullable', 'numeric', 'between:0,99999999'],
            'description' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'equipment_ids' => ['nullable', 'array', 'max:50'],
            'equipment_ids.*' => ['integer', Rule::exists('equipment', 'id')->where('client_id', $client->id)],
            'document_ids' => ['nullable', 'array', 'max:100'],
            'document_ids.*' => ['integer', Rule::exists('documents', 'id')->where('client_id', $client->id)],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            // Champs ajoutés avec le constructeur de fiches (fields[...]).
            ...$this->extensionRules('internet_link', $this->route('internet_link')),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'isp_id.required_without' => 'Choisissez un opérateur du catalogue ou saisissez son nom.',
            'operator_name.required_without' => 'Choisissez un opérateur du catalogue ou saisissez son nom.',
        ];
    }

    /**
     * Attributs du lien, hors relations.
     *
     * @return array<string, mixed>
     */
    public function linkAttributes(): array
    {
        $data = $this->safe()->except(['equipment_ids', 'document_ids', 'tags', 'fields']);

        // Le nom libre n'est conservé que pour un opérateur hors catalogue.
        if (! empty($data['isp_id'])) {
            $data['operator_name'] = null;
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->extensionAttributes('internet_link');
    }
}
