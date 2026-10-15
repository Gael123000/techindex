<?php

namespace App\Http\Requests;

use App\Enums\DocumentStatus;
use App\Http\Requests\Concerns\ValidatesRecordExtension;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentRequest extends FormRequest
{
    use ValidatesRecordExtension;

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
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:2000000'],
            'category_id' => ['nullable', 'integer', Rule::exists('document_categories', 'id')],
            'status' => ['required', Rule::enum(DocumentStatus::class)],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'review_interval_days' => ['nullable', 'integer', 'between:1,3650'],
            'change_summary' => ['nullable', 'string', 'max:255'],
            'equipment_ids' => ['nullable', 'array', 'max:500'],
            'equipment_ids.*' => ['integer', Rule::exists('equipment', 'id')->where('client_id', $client->id)],
            'service_ids' => ['nullable', 'array', 'max:500'],
            'service_ids.*' => ['integer', Rule::exists('services', 'id')->where('client_id', $client->id)],
            'internet_link_ids' => ['nullable', 'array', 'max:100'],
            'internet_link_ids.*' => ['integer', Rule::exists('internet_links', 'id')->where('client_id', $client->id)],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            // Champs ajoutés avec le constructeur de fiches (fields[...]).
            ...$this->extensionRules('document', $this->route('document')),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->extensionAttributes('document');
    }
}
