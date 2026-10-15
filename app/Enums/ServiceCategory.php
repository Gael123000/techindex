<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Illuminate\Validation\Rule;

/**
 * Catégories de services. Chaque catégorie déclare ses champs structurés
 * (stockés dans services.details), typés et validés côté serveur.
 */
enum ServiceCategory: string
{
    use HasOptions;

    case Internet = 'internet';
    case Vpn = 'vpn';
    case Domain = 'domain';
    case Hosting = 'hosting';
    case Cloud = 'cloud';
    case Email = 'email';
    case BusinessApp = 'business_app';
    case Backup = 'backup';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Internet => 'Connexion Internet',
            self::Vpn => 'VPN',
            self::Domain => 'Domaine et DNS',
            self::Hosting => 'Hébergement',
            self::Cloud => 'Service cloud',
            self::Email => 'Messagerie',
            self::BusinessApp => 'Application métier',
            self::Backup => 'Sauvegarde',
            self::Other => 'Autre service',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Internet => 'globe',
            self::Vpn => 'shield',
            self::Domain => 'at-sign',
            self::Hosting => 'server',
            self::Cloud => 'cloud',
            self::Email => 'mail',
            self::BusinessApp => 'app-window',
            self::Backup => 'archive',
            self::Other => 'box',
        };
    }

    /**
     * Libellé du champ "fournisseur" selon la catégorie.
     */
    public function providerLabel(): string
    {
        return match ($this) {
            self::Internet => 'Opérateur',
            self::Domain => 'Registrar',
            self::BusinessApp => 'Éditeur',
            default => 'Fournisseur',
        };
    }

    /**
     * Champs structurés de la catégorie.
     *
     * @return list<array{key: string, label: string, type: string, options?: list<string>, help?: string}>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Internet => [
                ['key' => 'technology', 'label' => 'Technologie', 'type' => 'select', 'options' => ['Fibre FTTH', 'Fibre dédiée (FTTO)', 'SDSL', 'ADSL / VDSL', '4G / 5G', 'Satellite', 'Autre']],
                ['key' => 'bandwidth_down', 'label' => 'Débit descendant', 'type' => 'text', 'help' => 'Exemple : 1 Gbit/s'],
                ['key' => 'bandwidth_up', 'label' => 'Débit montant', 'type' => 'text'],
                ['key' => 'public_ips', 'label' => 'Adresses IP publiques', 'type' => 'text'],
                ['key' => 'line_number', 'label' => 'Numéro de ligne / référence d\'accès', 'type' => 'text'],
                ['key' => 'sla', 'label' => 'Garantie de temps de rétablissement', 'type' => 'text'],
                ['key' => 'support_phone', 'label' => 'Support opérateur', 'type' => 'text'],
            ],
            self::Vpn => [
                ['key' => 'vpn_type', 'label' => 'Type de VPN', 'type' => 'select', 'options' => ['IPsec site à site', 'SSL / TLS nomade', 'WireGuard', 'OpenVPN', 'Autre']],
                ['key' => 'remote_endpoint', 'label' => 'Extrémité distante', 'type' => 'text'],
                ['key' => 'local_networks', 'label' => 'Réseaux locaux', 'type' => 'text'],
                ['key' => 'remote_networks', 'label' => 'Réseaux distants', 'type' => 'text'],
                ['key' => 'authentication', 'label' => 'Authentification', 'type' => 'select', 'options' => ['Clé partagée', 'Certificat', 'Identifiants', 'Autre'], 'help' => 'Enregistrez la clé partagée dans un accès sécurisé, jamais dans ce champ.'],
            ],
            self::Domain => [
                ['key' => 'domain_name', 'label' => 'Nom de domaine', 'type' => 'text'],
                ['key' => 'dns_hosting', 'label' => 'Hébergement DNS', 'type' => 'text'],
                ['key' => 'nameservers', 'label' => 'Serveurs de noms', 'type' => 'textarea'],
                ['key' => 'auto_renewal', 'label' => 'Renouvellement automatique', 'type' => 'boolean'],
                ['key' => 'dnssec', 'label' => 'DNSSEC activé', 'type' => 'boolean'],
            ],
            self::Hosting => [
                ['key' => 'hosting_type', 'label' => 'Type d\'hébergement', 'type' => 'select', 'options' => ['Mutualisé', 'Serveur virtuel (VPS)', 'Serveur dédié', 'Colocation', 'Autre']],
                ['key' => 'region', 'label' => 'Localisation / région', 'type' => 'text'],
                ['key' => 'plan', 'label' => 'Offre', 'type' => 'text'],
                ['key' => 'ip_address', 'label' => 'Adresse IP', 'type' => 'text'],
            ],
            self::Cloud => [
                ['key' => 'platform', 'label' => 'Plateforme', 'type' => 'select', 'options' => ['Microsoft 365 / Azure', 'Google Workspace / Cloud', 'AWS', 'OVHcloud', 'Scaleway', 'Autre']],
                ['key' => 'tenant', 'label' => 'Tenant / organisation', 'type' => 'text'],
                ['key' => 'subscription', 'label' => 'Abonnement', 'type' => 'text'],
                ['key' => 'region', 'label' => 'Région', 'type' => 'text'],
            ],
            self::Email => [
                ['key' => 'platform', 'label' => 'Plateforme', 'type' => 'select', 'options' => ['Exchange Online (Microsoft 365)', 'Google Workspace', 'Exchange sur site', 'Zimbra', 'Autre']],
                ['key' => 'domains', 'label' => 'Domaines de messagerie', 'type' => 'text'],
                ['key' => 'mailbox_count', 'label' => 'Nombre de boîtes', 'type' => 'number'],
                ['key' => 'mx_records', 'label' => 'Enregistrements MX', 'type' => 'textarea'],
                ['key' => 'email_security', 'label' => 'SPF / DKIM / DMARC', 'type' => 'textarea'],
            ],
            self::BusinessApp => [
                ['key' => 'version', 'label' => 'Version', 'type' => 'text'],
                ['key' => 'deployment', 'label' => 'Mode de déploiement', 'type' => 'select', 'options' => ['Sur site', 'SaaS', 'Hybride']],
                ['key' => 'users_count', 'label' => 'Nombre d\'utilisateurs', 'type' => 'number'],
                ['key' => 'database_engine', 'label' => 'Base de données', 'type' => 'text'],
                ['key' => 'support_contact', 'label' => 'Support éditeur', 'type' => 'text'],
            ],
            self::Backup => [
                ['key' => 'solution', 'label' => 'Solution', 'type' => 'text'],
                ['key' => 'backup_type', 'label' => 'Type de sauvegarde', 'type' => 'select', 'options' => ['Image système', 'Fichiers', 'Bases de données', 'Microsoft 365', 'Machines virtuelles', 'Autre']],
                ['key' => 'schedule', 'label' => 'Planification', 'type' => 'text'],
                ['key' => 'retention', 'label' => 'Rétention', 'type' => 'text'],
                ['key' => 'destination', 'label' => 'Destination', 'type' => 'text'],
                ['key' => 'offsite_copy', 'label' => 'Copie externalisée', 'type' => 'boolean'],
                ['key' => 'encrypted', 'label' => 'Sauvegarde chiffrée', 'type' => 'boolean'],
                ['key' => 'last_restore_test', 'label' => 'Dernier test de restauration', 'type' => 'date'],
                ['key' => 'restore_procedure', 'label' => 'Procédure de restauration (résumé)', 'type' => 'textarea', 'help' => 'Liez la procédure détaillée depuis la documentation.'],
            ],
            self::Other => [],
        };
    }

    /**
     * Règles de validation des champs structurés (préfixées par "details.").
     *
     * @return array<string, list<mixed>>
     */
    public function detailRules(): array
    {
        $rules = ['details' => ['nullable', 'array']];

        foreach ($this->fields() as $field) {
            $rules['details.'.$field['key']] = match ($field['type']) {
                'number' => ['nullable', 'integer', 'min:0', 'max:1000000'],
                'date' => ['nullable', 'date'],
                'boolean' => ['nullable', 'boolean'],
                'select' => ['nullable', 'string', Rule::in($field['options'] ?? [])],
                'textarea' => ['nullable', 'string', 'max:5000'],
                default => ['nullable', 'string', 'max:255'],
            };
        }

        return $rules;
    }

    /**
     * Ne conserve que les clés connues de la catégorie et normalise les types.
     *
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>
     */
    public function normalizeDetails(?array $details): array
    {
        $normalized = [];

        foreach ($this->fields() as $field) {
            $value = $details[$field['key']] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $normalized[$field['key']] = match ($field['type']) {
                'number' => (int) $value,
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
                default => trim((string) $value),
            };
        }

        return $normalized;
    }

    /**
     * Description complète pour l'interface.
     *
     * @return list<array{value: string, label: string, icon: string, provider_label: string, fields: list<array<string, mixed>>}>
     */
    public static function definitions(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'icon' => $case->icon(),
            'provider_label' => $case->providerLabel(),
            'fields' => $case->fields(),
        ], self::cases());
    }

    /**
     * Catégories proposées à la saisie. Les accès Internet se gèrent dans
     * l'onglet FAI : « Connexion Internet » ne reste proposée que pour un
     * service qui l'a déjà.
     *
     * @return list<array{value: string, label: string, icon: string, provider_label: string, fields: list<array<string, mixed>>}>
     */
    public static function selectableDefinitions(?self $current = null): array
    {
        return array_values(array_filter(
            self::definitions(),
            static fn (array $definition): bool => $definition['value'] !== self::Internet->value || $current === self::Internet,
        ));
    }
}
