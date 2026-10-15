<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesRecordExtension;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
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
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('client_id', $client->id)],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'is_technical' => ['boolean'],
            'is_primary' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Champs ajoutés avec le constructeur de fiches (fields[...]).
            ...$this->extensionRules('contact', $this->route('contact')),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->extensionAttributes('contact');
    }
}
