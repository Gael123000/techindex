<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Libellés français des champs, utilisés pour présenter l'historique.
 */
final class FieldLabels
{
    private const LABELS = [
        'account_manager_id' => 'Responsable interne',
        'parent_id' => 'Entreprise mère',
        'group_role' => 'Rôle dans le groupe',
        'access_instructions' => 'Consignes d\'accès',
        'is_technical' => 'Contact technique',
        'is_primary' => 'Contact principal',
        'interfaces' => 'Adresses réseau',
        'tags' => 'Étiquettes',
        'details' => 'Informations spécifiques',
        'renewal_notes' => 'Informations de renouvellement',
        'renewal_notice_days' => 'Préavis de renouvellement (jours)',
        'auto_renewal' => 'Renouvellement automatique',
        'billing_period' => 'Périodicité',
        'contract_reference' => 'Référence du contrat',
        'subject_alt_names' => 'Noms alternatifs (SAN)',
        'fingerprint_sha256' => 'Empreinte SHA-256',
        'issued_at' => 'Date d\'émission',
        'renewal_method' => 'Méthode de renouvellement',
        'service_id' => 'Service',
        'equipment_id' => 'Équipement',
        'folder_id' => 'Dossier',
        'parent_id' => 'Dossier parent',
        'equipment' => 'Équipements liés',
        'services' => 'Services liés',
        'vlan_id' => 'VLAN',
        'dns_servers' => 'Serveurs DNS',
        'dhcp_enabled' => 'DHCP',
        'dhcp_range' => 'Plage DHCP',
        'secret' => 'Secret',
        'is_active' => 'Compte actif',
        'must_change_password' => 'Changement de mot de passe exigé',
        'type' => 'Type',
        'source_equipment_id' => 'Équipement source',
        'target_equipment_id' => 'Équipement cible',
        'icon' => 'Icône',
        'sort_order' => 'Ordre',
        'options' => 'Options',
        'is_required' => 'Obligatoire',
        'help_text' => 'Aide',
        'key' => 'Clé',
        'color' => 'Couleur',
        'slug' => 'Identifiant',
        'original_name' => 'Nom du fichier',
        'access' => 'Droits',
    ];

    public static function label(string $field): string
    {
        if (isset(self::LABELS[$field])) {
            return self::LABELS[$field];
        }

        $translationKey = 'validation.attributes.'.$field;
        $translated = trans($translationKey);

        if ($translated !== $translationKey && is_string($translated)) {
            return Str::ucfirst($translated);
        }

        return Str::ucfirst(str_replace('_', ' ', $field));
    }
}
