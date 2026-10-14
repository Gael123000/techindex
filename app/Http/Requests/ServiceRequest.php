<?php

namespace App\Http\Requests;

use App\Enums\ServiceCategory;
use App\Enums\ServiceStatus;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceRequest extends FormRequest
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
        $category = ServiceCategory::tryFrom((string) $this->input('category'));

        return [
            'category' => ['required', Rule::enum(ServiceCategory::class)],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('client_id', $client->id)],
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:150'],
            'url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'status' => ['required', Rule::enum(ServiceStatus::class)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'equipment_ids' => ['nullable', 'array', 'max:200'],
            'equipment_ids.*' => ['integer', Rule::exists('equipment', 'id')->where('client_id', $client->id)],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            ...($category?->detailRules() ?? ['details' => ['nullable', 'array']]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $category = ServiceCategory::tryFrom((string) $this->input('category'));

        return collect($category?->fields() ?? [])
            ->mapWithKeys(fn (array $field): array => ['details.'.$field['key'] => mb_strtolower($field['label'])])
            ->all();
    }
}
