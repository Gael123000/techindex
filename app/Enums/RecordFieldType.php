<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Types de champs du constructeur de fiches. Chaque type détermine la
 * colonne SQL créée dans la table de la fiche, la saisie et la validation.
 *
 * Aucun type « secret » : un mot de passe ou une clé se conserve dans un
 * accès chiffré (App\Secrets\CredentialSecrets), qu'un champ « lien vers
 * une fiche » peut désigner.
 */
enum RecordFieldType: string
{
    use HasOptions;

    case Text = 'text';
    case Textarea = 'textarea';
    case RichText = 'richtext';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Currency = 'currency';
    case Date = 'date';
    case DateTime = 'datetime';
    case Boolean = 'boolean';
    case Select = 'select';
    case MultiSelect = 'multiselect';
    case Email = 'email';
    case Url = 'url';
    case Phone = 'phone';
    case Ip = 'ip';
    case Cidr = 'cidr';
    case Reference = 'reference';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texte court',
            self::Textarea => 'Texte long',
            self::RichText => 'Texte mis en forme',
            self::Integer => 'Nombre entier',
            self::Decimal => 'Nombre décimal',
            self::Currency => 'Montant (€)',
            self::Date => 'Date',
            self::DateTime => 'Date et heure',
            self::Boolean => 'Oui / non',
            self::Select => 'Liste de choix',
            self::MultiSelect => 'Choix multiples',
            self::Email => 'Adresse e-mail',
            self::Url => 'Adresse web',
            self::Phone => 'Téléphone',
            self::Ip => 'Adresse IP',
            self::Cidr => 'Réseau (CIDR)',
            self::Reference => 'Lien vers une fiche',
            self::User => 'Utilisateur TechIndex',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Text => 'Une ligne de texte (255 caractères au plus).',
            self::Textarea => 'Plusieurs lignes de texte brut.',
            self::RichText => 'Texte avec titres, listes, gras, liens et tableaux.',
            self::Integer => 'Nombre sans décimale, avec minimum, maximum et unité facultatifs.',
            self::Decimal => 'Nombre avec décimales.',
            self::Currency => 'Montant en euros, deux décimales.',
            self::Date => 'Une date ; peut servir d\'échéance.',
            self::DateTime => 'Une date et une heure.',
            self::Boolean => 'Case à cocher oui / non.',
            self::Select => 'Un choix parmi une liste définie.',
            self::MultiSelect => 'Plusieurs choix parmi une liste définie.',
            self::Email => 'Adresse e-mail vérifiée.',
            self::Url => 'Lien http ou https, cliquable sur la fiche.',
            self::Phone => 'Numéro de téléphone, cliquable sur la fiche.',
            self::Ip => 'Adresse IPv4 ou IPv6 vérifiée.',
            self::Cidr => 'Réseau au format CIDR (192.168.10.0/24).',
            self::Reference => 'Lien vers un site, un équipement, un contact, une autre fiche… du même client.',
            self::User => 'Un utilisateur de TechIndex (technicien référent...).',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Text => 'type',
            self::Textarea => 'align-left',
            self::RichText => 'file-text',
            self::Integer, self::Decimal => 'hash',
            self::Currency => 'euro',
            self::Date, self::DateTime => 'calendar',
            self::Boolean => 'square-check',
            self::Select => 'list',
            self::MultiSelect => 'list-checks',
            self::Email => 'at-sign',
            self::Url => 'link',
            self::Phone => 'phone',
            self::Ip, self::Cidr => 'network',
            self::Reference => 'link-2',
            self::User => 'user-round',
        };
    }

    /**
     * Type SQL de la colonne (affiché dans l'onglet « Base de données »).
     */
    public function sqlType(): string
    {
        return match ($this) {
            self::Text => 'VARCHAR(255)',
            self::Textarea => 'TEXT',
            self::RichText => 'MEDIUMTEXT',
            self::Integer => 'BIGINT',
            self::Decimal => 'DECIMAL(20,4)',
            self::Currency => 'DECIMAL(14,2)',
            self::Date => 'DATE',
            self::DateTime => 'DATETIME',
            self::Boolean => 'TINYINT(1)',
            self::Select => 'VARCHAR(150)',
            self::MultiSelect => 'JSON',
            self::Email => 'VARCHAR(255)',
            self::Url => 'VARCHAR(500)',
            self::Phone => 'VARCHAR(50)',
            self::Ip => 'VARCHAR(45)',
            self::Cidr => 'VARCHAR(50)',
            self::Reference, self::User => 'BIGINT UNSIGNED',
        };
    }

    /**
     * Famille de stockage : un changement de type n'est possible sans
     * vider la colonne qu'à l'intérieur d'une même famille (ou vers une
     * famille plus large, voir canConvertTo()).
     */
    public function storage(): string
    {
        return match ($this) {
            self::Text, self::Select, self::Email, self::Url, self::Phone, self::Ip, self::Cidr => 'string',
            self::Textarea, self::RichText => 'text',
            self::Integer => 'integer',
            self::Decimal, self::Currency => 'decimal',
            self::Date => 'date',
            self::DateTime => 'datetime',
            self::Boolean => 'boolean',
            self::MultiSelect => 'json',
            self::Reference, self::User => 'id',
        };
    }

    /**
     * Conversion sans perte d'une colonne déjà remplie.
     */
    public function canConvertTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        if (in_array($this->storage(), ['id'], true) || in_array($target->storage(), ['id'], true)) {
            return false;
        }

        return match ($this->storage()) {
            'string' => in_array($target->storage(), ['string', 'text'], true),
            'text' => $target->storage() === 'text',
            'integer' => in_array($target->storage(), ['integer', 'decimal'], true),
            'decimal' => $target->storage() === 'decimal' && $target === self::Decimal,
            'date' => $target === self::DateTime,
            default => false,
        };
    }

    public function hasChoices(): bool
    {
        return $this === self::Select || $this === self::MultiSelect;
    }

    public function isNumeric(): bool
    {
        return in_array($this, [self::Integer, self::Decimal, self::Currency], true);
    }

    /**
     * Les champs longs ne sont pas proposés comme colonnes de liste.
     */
    public function canBeListed(): bool
    {
        return ! in_array($this, [self::Textarea, self::RichText], true);
    }

    /**
     * Description complète pour le constructeur.
     *
     * @return list<array{value: string, label: string, description: string, icon: string, sql: string, storage: string}>
     */
    public static function definitions(): array
    {
        return array_map(static fn (self $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'description' => $type->description(),
            'icon' => $type->icon(),
            'sql' => $type->sqlType(),
            'storage' => $type->storage(),
        ], self::cases());
    }
}
