<?php

namespace App\Services\Records;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DocumentStatus;
use App\Enums\EquipmentStatus;
use App\Enums\InternetLinkRole;
use App\Enums\InternetLinkStatus;
use App\Enums\InternetTechnology;
use App\Enums\LicenseKind;
use App\Enums\LicenseStatus;
use App\Enums\RecordStatus;
use App\Enums\ServiceCategory;
use App\Enums\ServiceStatus;
use App\Enums\SubnetPurpose;
use App\Models\DocumentCategory;
use App\Models\EquipmentType;
use App\Models\RecordType;
use Illuminate\Support\Facades\DB;

/**
 * Fiches par défaut de TechIndex vues par le constructeur.
 *
 * Leurs champs d'origine sont affichés verrouillés : ils ne peuvent être ni
 * modifiés ni supprimés. Un administrateur peut seulement leur ajouter des
 * champs et des sections, stockés dans une table d'extension (cx_ext_...).
 */
final class SystemRecordTypes
{
    /**
     * Définitions : alias du modèle => [clé, nom, pluriel, icône, table, ordre, champs d'origine].
     *
     * @return array<string, array{key: string, name: string, plural: string, icon: string, table: string, label_column: string, route: string|null, builtin: list<string>}>
     */
    public static function definitions(): array
    {
        return [
            'client' => [
                'key' => 'clients', 'name' => 'Client', 'plural' => 'Fiche client', 'icon' => 'building-2', 'table' => 'clients',
                'label_column' => 'name', 'route' => null,
                'builtin' => ['Nom', 'Référence', 'Description', 'Responsable interne', 'Adresse', 'Code postal', 'Ville', 'Pays', 'Téléphone', 'E-mail', 'Site web', 'Logo', 'Étiquettes', 'Notes'],
            ],
            'site' => [
                'key' => 'sites', 'name' => 'Site', 'plural' => 'Sites', 'icon' => 'map-pin', 'table' => 'sites',
                'label_column' => 'name', 'route' => 'clients.sites.show',
                'builtin' => ['Nom', 'Code', 'Adresse', 'Code postal', 'Ville', 'Pays', 'Téléphone', 'Consignes d\'accès', 'Statut', 'Notes'],
            ],
            'contact' => [
                'key' => 'contacts', 'name' => 'Contact', 'plural' => 'Contacts', 'icon' => 'contact', 'table' => 'contacts',
                'label_column' => 'last_name', 'route' => null,
                'builtin' => ['Nom', 'Prénom', 'Fonction', 'Site', 'E-mail', 'Téléphone', 'Mobile', 'Contact technique', 'Contact principal', 'Notes'],
            ],
            'equipment' => [
                'key' => 'equipment', 'name' => 'Équipement', 'plural' => 'Équipements', 'icon' => 'server', 'table' => 'equipment',
                'label_column' => 'name', 'route' => 'clients.equipment.show',
                'builtin' => ['Type', 'Nom', 'Nom d\'hôte', 'Fabricant', 'Modèle', 'Numéro de série', 'Référence d\'inventaire', 'Site', 'Emplacement',
                    'Système et version', 'Firmware', 'Rôle', 'Statut', 'Dates d\'achat, de garantie et de fin de vie', 'Fournisseur',
                    'URL d\'administration', 'Adresses IP et MAC', 'Étiquettes', 'Description', 'Notes'],
            ],
            'subnet' => [
                'key' => 'subnets', 'name' => 'Sous-réseau', 'plural' => 'Réseau', 'icon' => 'network', 'table' => 'subnets',
                'label_column' => 'cidr', 'route' => null,
                'builtin' => ['Nom', 'Réseau (CIDR)', 'Usage', 'Lien Internet', 'VLAN', 'Site', 'Passerelle', 'Serveurs DNS', 'DHCP', 'Description'],
            ],
            'internet_link' => [
                'key' => 'internet_links', 'name' => 'Lien Internet', 'plural' => 'FAI', 'icon' => 'wifi', 'table' => 'internet_links',
                'label_column' => 'name', 'route' => 'clients.internet-links.show',
                'builtin' => ['Opérateur', 'Nom', 'Rôle', 'Technologie', 'Statut', 'Site', 'Débits', 'Contrat et référence de ligne',
                    'Raccordement WAN', 'GTR et support', 'Engagement et coût', 'Équipements', 'Documents', 'Étiquettes', 'Description', 'Notes'],
            ],
            'service' => [
                'key' => 'services', 'name' => 'Service', 'plural' => 'Services', 'icon' => 'cloud', 'table' => 'services',
                'label_column' => 'name', 'route' => 'clients.services.show',
                'builtin' => ['Catégorie', 'Nom', 'Fournisseur', 'Référence', 'URL', 'Statut', 'Site', 'Début', 'Échéance',
                    'Champs propres à la catégorie', 'Équipements', 'Étiquettes', 'Description', 'Notes'],
            ],
            'license' => [
                'key' => 'licenses', 'name' => 'Licence', 'plural' => 'Licences', 'icon' => 'key-round', 'table' => 'licenses',
                'label_column' => 'name', 'route' => 'clients.licenses.show',
                'builtin' => ['Nature', 'Nom', 'Éditeur', 'Fournisseur', 'Référence du contrat', 'Quantité', 'Périodicité', 'Coût',
                    'Dates', 'Renouvellement', 'Statut', 'Site', 'Équipements', 'Notes'],
            ],
            'certificate' => [
                'key' => 'certificates', 'name' => 'Certificat', 'plural' => 'Certificats', 'icon' => 'shield', 'table' => 'certificates',
                'label_column' => 'name', 'route' => 'clients.certificates.show',
                'builtin' => ['Nom', 'Nom commun (CN)', 'Noms alternatifs', 'Émetteur', 'Type', 'Numéro de série', 'Empreinte SHA-256',
                    'Dates', 'Renouvellement', 'Statut', 'Service', 'Équipements', 'Notes'],
            ],
            'document' => [
                'key' => 'documents', 'name' => 'Document', 'plural' => 'Documentation', 'icon' => 'file-text', 'table' => 'documents',
                'label_column' => 'title', 'route' => 'clients.documents.show',
                'builtin' => ['Titre', 'Catégorie', 'Statut', 'Responsable', 'Intervalle de vérification', 'Contenu', 'Éléments concernés', 'Étiquettes'],
            ],
        ];
    }

    /**
     * Cibles possibles d'un champ « lien vers une fiche », hors fiches créées.
     *
     * @return array<string, string> alias => libellé
     */
    public static function referenceTargets(): array
    {
        return [
            'site' => 'Site',
            'contact' => 'Contact',
            'equipment' => 'Équipement',
            'subnet' => 'Sous-réseau',
            'internet_link' => 'Lien Internet (FAI)',
            'service' => 'Service',
            'license' => 'Licence',
            'certificate' => 'Certificat',
            'document' => 'Document',
            'credential' => 'Accès (identifiant chiffré)',
        ];
    }

    /**
     * Table d'une cible de référence (fiche par défaut ou accès).
     */
    public static function tableFor(string $entity): ?string
    {
        return $entity === 'credential' ? 'credentials' : (self::definitions()[$entity]['table'] ?? null);
    }

    /**
     * Champs d'origine utilisables dans une condition d'affichage, avec leurs valeurs.
     *
     * @return array<string, array{label: string, options: list<array{value: string, label: string}>}>
     */
    public static function conditionSources(string $entity): array
    {
        $enum = fn (string $label, array $options): array => ['label' => $label, 'options' => array_map(
            fn (array $option): array => ['value' => (string) $option['value'], 'label' => $option['label']],
            $options,
        )];

        return match ($entity) {
            'equipment' => [
                'equipment_type_id' => $enum('Type d\'équipement', EquipmentType::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                    ->map(fn (EquipmentType $type): array => ['value' => $type->id, 'label' => $type->name])->all()),
                'status' => $enum('Statut', EquipmentStatus::options()),
            ],
            'service' => ['category' => $enum('Catégorie', ServiceCategory::options()), 'status' => $enum('Statut', ServiceStatus::options())],
            'site' => ['status' => $enum('Statut', RecordStatus::options())],
            'internet_link' => [
                'technology' => $enum('Technologie', InternetTechnology::options()),
                'role' => $enum('Rôle', InternetLinkRole::options()),
                'status' => $enum('Statut', InternetLinkStatus::options()),
            ],
            'license' => ['kind' => $enum('Nature', LicenseKind::options()), 'status' => $enum('Statut', LicenseStatus::options())],
            'certificate' => ['type' => $enum('Type', CertificateType::options()), 'status' => $enum('Statut', CertificateStatus::options())],
            'subnet' => ['purpose' => $enum('Usage', SubnetPurpose::options())],
            'document' => [
                'category_id' => $enum('Catégorie', DocumentCategory::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                    ->map(fn (DocumentCategory $category): array => ['value' => $category->id, 'label' => $category->name])->all()),
                'status' => $enum('Statut', DocumentStatus::options()),
            ],
            default => [],
        };
    }

    /**
     * Crée les fiches par défaut manquantes (idempotent, sans historique :
     * données de référence). Les réglages modifiés ne sont jamais écrasés.
     */
    public static function sync(): void
    {
        $now = now();
        $order = 0;

        foreach (self::definitions() as $entity => $definition) {
            $order += 10;

            if (DB::table('record_types')->where('entity', $entity)->exists()) {
                continue;
            }

            DB::table('record_types')->insert([
                'key' => $definition['key'],
                'is_system' => true,
                'entity' => $entity,
                'name' => $definition['name'],
                'plural_name' => $definition['plural'],
                'icon' => $definition['icon'],
                'table_name' => RecordSchema::EXTENSION_PREFIX.$entity,
                'sort_order' => $order,
                'is_active' => true,
                'sections' => json_encode([['key' => RecordType::DEFAULT_SECTION, 'title' => 'Informations complémentaires', 'columns' => 2]]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public static function type(string $entity): ?RecordType
    {
        return RecordType::query()->where('is_system', true)->where('entity', $entity)->first();
    }
}
