<?php

namespace App\Http\Requests;

use App\Enums\ClientGroupRole;
use App\Enums\ClientPermission;
use App\Http\Requests\Concerns\ValidatesRecordExtension;
use App\Models\Client;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ClientRequest extends FormRequest
{
    use ValidatesRecordExtension;

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
            'parent_id' => ['nullable', 'integer', Rule::exists('clients', 'id'), $this->parentRule($client)],
            'group_role' => ['nullable', Rule::enum(ClientGroupRole::class)],
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
            // Champs ajoutés avec le constructeur de fiches (fields[...]).
            ...$this->extensionRules('client', $this->route('client')),
        ];
    }

    /**
     * Entreprise mère : visible par l'utilisateur (sauf si elle reste
     * inchangée), sans boucle et dans la limite des niveaux d'un groupe.
     */
    private function parentRule(?Client $client): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($client): void {
            $parentId = (int) $value;

            if ($client !== null && $parentId === $client->id) {
                $fail('Un client ne peut pas être rattaché à lui-même.');

                return;
            }

            $unchanged = $client !== null && $client->parent_id === $parentId;

            if (! $unchanged && ! $this->user()->hasClientPermission($parentId, ClientPermission::View)) {
                // Même message qu'une entreprise inexistante : un client non attribué reste invisible.
                $fail('L\'entreprise mère choisie est introuvable.');

                return;
            }

            $parent = Client::query()->find($parentId, ['id', 'parent_id']);

            if ($parent === null) {
                return;
            }

            if ($client !== null && in_array($parentId, $client->descendantIds(), true)) {
                $fail('Cette entreprise est déjà rattachée à ce client : elle ne peut pas devenir son entreprise mère.');

                return;
            }

            if ($parent->groupDepth() + 1 + ($client?->groupHeight() ?? 0) > Client::MAX_GROUP_DEPTH) {
                $fail('Un groupe compte au maximum '.Client::MAX_GROUP_DEPTH.' niveaux (tête de groupe, filiale, établissement).');
            }
        };
    }

    /**
     * Le rôle dépend du rattachement : membre (filiale...) avec une entreprise
     * mère, tête de groupe ou indépendant sans.
     *
     * @return list<Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['parent_id', 'group_role'])) {
                    return;
                }

                $role = ClientGroupRole::tryFrom((string) $this->input('group_role'));

                if ($this->filled('parent_id') && ($role === null || $role->isHead())) {
                    $validator->errors()->add('group_role', 'Choisissez le rôle de l\'entreprise dans le groupe : filiale, établissement ou franchisé.');
                } elseif (! $this->filled('parent_id') && $role !== null && ! $role->isHead()) {
                    $validator->errors()->add('group_role', 'Une filiale, un établissement ou un franchisé doit être rattaché à une entreprise mère.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'reference.regex' => 'La référence ne peut contenir que des lettres, chiffres, points, tirets et tirets bas.',
            'parent_id.exists' => 'L\'entreprise mère choisie est introuvable.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'parent_id' => 'entreprise mère',
            'group_role' => 'rôle dans le groupe',
            ...$this->extensionAttributes('client'),
        ];
    }
}
