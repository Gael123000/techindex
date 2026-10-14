<?php

namespace App\Enums;

/**
 * Droits d'un utilisateur sur un client. La lecture découle de l'affectation ;
 * les autres droits sont des colonnes de la table client_user.
 */
enum ClientPermission: string
{
    case View = 'view';
    case Edit = 'edit';
    case Export = 'export';
    case Secrets = 'secrets';
    case Manage = 'manage';

    /**
     * Colonne correspondante dans client_user (null pour la lecture).
     */
    public function column(): ?string
    {
        return match ($this) {
            self::View => null,
            self::Edit => 'can_edit',
            self::Export => 'can_export',
            self::Secrets => 'can_view_secrets',
            self::Manage => 'can_manage',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::View => 'Lecture',
            self::Edit => 'Modification',
            self::Export => 'Exportation',
            self::Secrets => 'Consultation des secrets',
            self::Manage => 'Gestion du client',
        };
    }
}
