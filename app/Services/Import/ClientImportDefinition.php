<?php

namespace App\Services\Import;

use App\Enums\ClientPermission;
use App\Enums\RecordStatus;
use App\Models\Client;
use App\Models\ClientAssignment;
use App\Models\User;
use App\Services\Tags\TagResolver;
use Illuminate\Database\Eloquent\Model;

class ClientImportDefinition implements ImportDefinition
{
    private const ATTRIBUTES = [
        'name', 'reference', 'description', 'address_line1', 'address_line2', 'postal_code', 'city',
        'country', 'phone', 'email', 'website', 'notes',
    ];

    /** @var array<string, int|null> */
    private array $managers = [];

    public function __construct(private readonly TagResolver $tags) {}

    public function columns(): array
    {
        return [
            'name' => ['label' => 'Nom', 'required' => true, 'example' => 'Exemple Industries', 'aliases' => ['client', 'raison sociale', 'entreprise']],
            'reference' => ['label' => 'Référence', 'required' => true, 'example' => 'CLI-0100', 'help' => 'Référence interne unique : sert à détecter les doublons.', 'aliases' => ['ref', 'code client']],
            'description' => ['label' => 'Description', 'required' => false, 'example' => 'PME de 40 postes'],
            'address_line1' => ['label' => 'Adresse', 'required' => false, 'example' => '12 rue des Exemples', 'aliases' => ['adresse 1']],
            'address_line2' => ['label' => 'Complément d\'adresse', 'required' => false, 'example' => 'Bâtiment B', 'aliases' => ['adresse 2']],
            'postal_code' => ['label' => 'Code postal', 'required' => false, 'example' => '69000', 'aliases' => ['cp']],
            'city' => ['label' => 'Ville', 'required' => false, 'example' => 'Lyon'],
            'country' => ['label' => 'Pays', 'required' => false, 'example' => 'France'],
            'phone' => ['label' => 'Téléphone', 'required' => false, 'example' => '04 00 00 00 00', 'aliases' => ['tel']],
            'email' => ['label' => 'E-mail', 'required' => false, 'example' => 'contact@exemple.test', 'aliases' => ['mail', 'courriel']],
            'website' => ['label' => 'Site web', 'required' => false, 'example' => 'https://www.exemple.test'],
            'account_manager' => ['label' => 'Responsable interne (e-mail)', 'required' => false, 'example' => '', 'help' => 'Adresse e-mail d\'un utilisateur TechIndex actif.', 'aliases' => ['responsable', 'technicien']],
            'tags' => ['label' => 'Étiquettes', 'required' => false, 'example' => 'infogérance, PME', 'help' => 'Séparées par des virgules.'],
            'notes' => ['label' => 'Notes', 'required' => false, 'example' => ''],
        ];
    }

    public function duplicateRule(): string
    {
        return 'Un client est considéré comme existant lorsqu\'un client possède la même référence (sans tenir compte des majuscules).';
    }

    public function normalize(array $row): array
    {
        $data = [];

        foreach (self::ATTRIBUTES as $attribute) {
            $data[$attribute] = Normalizer::text($row[$attribute] ?? null);
        }

        $email = Normalizer::text($row['account_manager'] ?? null);
        $data['account_manager_email'] = $email;
        $data['account_manager_id'] = $email === null ? null : $this->managerId($email);
        $data['tags'] = Normalizer::list($row['tags'] ?? null);

        return $data;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'reference' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'description' => ['nullable', 'string', 'max:5000'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'account_manager_id' => ['required_with:account_manager_email'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference.regex' => 'La référence ne peut contenir que des lettres, chiffres, points, tirets et tirets bas.',
            'account_manager_id.required_with' => 'Aucun utilisateur actif ne correspond à l\'adresse du responsable.',
        ];
    }

    public function duplicateKey(array $data): ?string
    {
        return $data['reference'] === null ? null : 'ref:'.mb_strtolower($data['reference']);
    }

    public function findExisting(array $data): ?Model
    {
        if ($data['reference'] === null) {
            return null;
        }

        return Client::query()->whereRaw('LOWER(reference) = ?', [mb_strtolower($data['reference'])])->first();
    }

    public function canUpdate(Model $existing, User $user): bool
    {
        /** @var Client $existing */
        return ! $existing->isArchived() && $user->hasClientPermission($existing, ClientPermission::Edit);
    }

    public function create(array $data, User $user): Model
    {
        $client = new Client($this->attributes($data, onlyFilled: false));
        $client->status = RecordStatus::Active;
        $client->account_manager_id = $data['account_manager_id'];
        $client->save();
        $client->tags()->sync($this->tags->idsFor($data['tags']));

        if (! $user->isAdmin()) {
            ClientAssignment::query()->create([
                'client_id' => $client->id,
                'user_id' => $user->id,
                ...$user->role->defaultClientPermissions(),
            ]);
            $user->flushAccessCache();
        }

        return $client;
    }

    public function update(Model $existing, array $data, User $user): void
    {
        /** @var Client $existing */
        $existing->fill($this->attributes($data, onlyFilled: true));

        if ($data['account_manager_id'] !== null) {
            $existing->account_manager_id = $data['account_manager_id'];
        }

        $existing->save();

        if ($data['tags'] !== []) {
            $existing->tags()->sync($this->tags->idsFor($data['tags']));
        }
    }

    public function rowLabel(array $data): string
    {
        return trim(($data['name'] ?? '?').' ('.($data['reference'] ?? '?').')');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, bool $onlyFilled): array
    {
        $attributes = array_intersect_key($data, array_flip(self::ATTRIBUTES));

        return $onlyFilled ? array_filter($attributes, fn ($value): bool => $value !== null) : $attributes;
    }

    private function managerId(string $email): ?int
    {
        $key = mb_strtolower($email);

        if (! array_key_exists($key, $this->managers)) {
            $this->managers[$key] = User::query()->where('email', $key)->where('is_active', true)->value('id');
        }

        return $this->managers[$key];
    }
}
