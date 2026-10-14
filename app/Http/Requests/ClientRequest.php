<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Contrôle effectué dans le contrôleur (politique ClientPolicy).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Client|null $client */
        $client = $this->route('client');

        return [
            'name' => ['required', 'string', 'max:255'],
            'reference' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('clients', 'reference')->ignore($client?->id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'account_manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:'.config('techindex.logos.max_size_kb'), 'dimensions:max_width=4000,max_height=4000'],
            'remove_logo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference.regex' => 'La référence ne peut contenir que des lettres, chiffres, points, tirets et tirets bas.',
        ];
    }
}
