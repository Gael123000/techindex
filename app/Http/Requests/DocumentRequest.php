<?php

namespace App\Http\Requests;

use App\Enums\DocumentStatus;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentRequest extends FormRequest
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
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
        ];
    }
}
