<?php

namespace App\Http\Requests;

use App\Enums\BillingPeriod;
use App\Enums\LicenseKind;
use App\Enums\LicenseStatus;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LicenseRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(LicenseKind::class)],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('client_id', $client->id)],
            'name' => ['required', 'string', 'max:255'],
            'publisher' => ['nullable', 'string', 'max:150'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'contract_reference' => ['nullable', 'string', 'max:150'],
            'quantity' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'billing_period' => ['nullable', Rule::enum(BillingPeriod::class)],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'purchase_date' => ['nullable', 'date'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'auto_renewal' => ['boolean'],
            'renewal_notice_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'renewal_notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(LicenseStatus::class)],
            'notes' => ['nullable', 'string', 'max:20000'],
            'equipment_ids' => ['nullable', 'array', 'max:500'],
            'equipment_ids.*' => ['integer', Rule::exists('equipment', 'id')->where('client_id', $client->id)],
        ];
    }
}
