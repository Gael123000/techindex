<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationCategory;
use App\Services\Integrations\Support\ServiceUrl;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Fiche d'un connecteur dans le catalogue : présentation, champs à
 * renseigner (identifiants chiffrés et réglages) et étapes de mise en place.
 *
 * Format d'un champ : key, label, type (text | secret | select | textarea),
 * required, help, placeholder, options (select), default.
 */
final readonly class ProviderDefinition
{
    /**
     * @param  list<array<string, mixed>>  $credentials  champs chiffrés, jamais renvoyés à l'interface
     * @param  list<array<string, mixed>>  $settings  réglages sans caractère secret
     * @param  list<string>  $setup  étapes de création des identifiants chez le fournisseur
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $vendor,
        public IntegrationCategory $category,
        public string $description,
        /** Ce que représente un compte : « client », « locataire »... */
        public string $accountLabel,
        public array $credentials,
        public array $settings = [],
        public array $setup = [],
        public ?string $docsUrl = null,
        /** Pastille du catalogue (initiales sur la couleur de la marque). */
        public string $initials = '',
        public string $color = '#10203f',
        /** API non vérifiée sur un compte réel : signalée « bêta » dans l'interface. */
        public bool $beta = false,
    ) {}

    /**
     * @param  list<string>|null  $allowedHosts  adresse HTTPS limitée à ces domaines (adresse d'API configurable)
     * @return array<string, mixed>
     */
    public static function text(string $key, string $label, bool $required = true, ?string $help = null, ?string $placeholder = null, ?string $default = null, ?array $allowedHosts = null): array
    {
        return [
            'key' => $key, 'label' => $label, 'type' => 'text', 'required' => $required, 'help' => $help,
            'placeholder' => $placeholder, 'default' => $default, 'allowed_hosts' => $allowedHosts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function secret(string $key, string $label, bool $required = true, ?string $help = null, bool $multiline = false): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $multiline ? 'secret_textarea' : 'secret', 'required' => $required, 'help' => $help];
    }

    /**
     * @param  array<string, string>  $options  valeur => libellé
     * @return array<string, mixed>
     */
    public static function select(string $key, string $label, array $options, string $default, ?string $help = null): array
    {
        return [
            'key' => $key, 'label' => $label, 'type' => 'select', 'required' => true, 'help' => $help, 'default' => $default,
            'options' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($options), $options),
        ];
    }

    /**
     * Règles de validation des réglages.
     *
     * @return array<string, mixed>
     */
    public function settingRules(): array
    {
        $rules = [];

        foreach ($this->settings as $field) {
            $rules['settings.'.$field['key']] = [
                $field['required'] ? 'required' : 'nullable',
                'string',
                'max:255',
                ...($field['type'] === 'select' ? [Rule::in(array_column($field['options'], 'value'))] : []),
                // Adresse d'API : HTTPS et domaine du fournisseur uniquement (pas d'appel vers un autre serveur).
                ...(! empty($field['allowed_hosts']) ? [function (string $attribute, mixed $value, Closure $fail) use ($field): void {
                    try {
                        ServiceUrl::https((string) $value, $field['allowed_hosts'], 'Adresse');
                    } catch (IntegrationException) {
                        $fail('L\'adresse doit commencer par https:// et appartenir au domaine '.implode(' ou ', $field['allowed_hosts']).'.');
                    }
                }] : []),
            ];
        }

        return $rules;
    }

    /**
     * Règles des identifiants : un champ vide conserve la valeur enregistrée,
     * il n'est donc obligatoire qu'à la première configuration.
     *
     * @param  list<string>  $configured
     * @return array<string, mixed>
     */
    public function credentialRules(array $configured): array
    {
        $rules = [];

        foreach ($this->credentials as $field) {
            $required = $field['required'] && ! in_array($field['key'], $configured, true);
            $rules['credentials.'.$field['key']] = [$required ? 'required' : 'nullable', 'string', 'max:'.($field['type'] === 'secret_textarea' ? 20000 : 2000)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributeNames(): array
    {
        $names = [];

        foreach ($this->settings as $field) {
            $names['settings.'.$field['key']] = mb_strtolower($field['label']);
        }

        foreach ($this->credentials as $field) {
            $names['credentials.'.$field['key']] = mb_strtolower($field['label']);
        }

        return $names;
    }

    /**
     * Présentation pour l'interface (aucune valeur d'identifiant).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'vendor' => $this->vendor,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'description' => $this->description,
            'account_label' => $this->accountLabel,
            'credentials' => $this->credentials,
            'settings' => $this->settings,
            'setup' => $this->setup,
            'docs_url' => $this->docsUrl,
            'initials' => $this->initials,
            'color' => $this->color,
            'beta' => $this->beta,
        ];
    }
}
