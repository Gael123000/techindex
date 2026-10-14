<?php

namespace App\Http\Requests;

use App\Enums\AccessType;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le champ "secret" est en écriture seule : il n'est jamais renvoyé au
 * navigateur, ni réaffiché après une erreur de validation (dontFlash).
 */
class CredentialRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:150'],
            'access_type' => ['required', Rule::enum(AccessType::class)],
            'username' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:10000'],
            'clear_secret' => ['nullable', 'boolean'],
            'url' => ['nullable', 'string', 'max:500', 'url:http,https,ssh,rdp,ftp,sftp'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'equipment_id' => ['nullable', 'integer', Rule::exists('equipment', 'id')->where('client_id', $client->id)],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('client_id', $client->id)],
            // Rangement dans le gestionnaire de mots de passe (dossier du même client).
            'folder_id' => ['nullable', 'integer', Rule::exists('vault_folders', 'id')->where('client_id', $client->id)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->safe()->except(['secret', 'clear_secret']);
    }

    public function wantsSecretChange(): bool
    {
        return $this->filled('secret') || $this->boolean('clear_secret');
    }
}
