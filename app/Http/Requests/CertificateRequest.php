<?php

namespace App\Http\Requests;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Client $client */
        $client = $this->route('client');

        return [
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('client_id', $client->id)],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('client_id', $client->id)],
            'name' => ['required', 'string', 'max:255'],
            'common_name' => ['nullable', 'string', 'max:255'],
            'subject_alt_names' => ['nullable', 'string', 'max:5000'],
            'issuer' => ['nullable', 'string', 'max:150'],
            'type' => ['required', Rule::enum(CertificateType::class)],
            'serial_number' => ['nullable', 'string', 'max:150'],
            'fingerprint_sha256' => ['nullable', 'string', 'max:120'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'auto_renewal' => ['boolean'],
            'renewal_method' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(CertificateStatus::class)],
            'notes' => ['nullable', 'string', 'max:20000'],
            'equipment_ids' => ['nullable', 'array', 'max:500'],
            'equipment_ids.*' => ['integer', Rule::exists('equipment', 'id')->where('client_id', $client->id)],
        ];
    }
}
