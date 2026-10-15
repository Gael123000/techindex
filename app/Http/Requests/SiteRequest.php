<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesRecordExtension;
use Illuminate\Foundation\Http\FormRequest;

class SiteRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:50'],
            'access_instructions' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:20000'],
            // Champs ajoutés avec le constructeur de fiches (fields[...]).
            ...$this->extensionRules('site', $this->route('site')),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->extensionAttributes('site');
    }
}
