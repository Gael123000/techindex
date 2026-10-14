<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Relation orientée : "source <verbe> cible".
 */
enum EquipmentLinkType: string
{
    use HasOptions;

    case Hosts = 'hosts';
    case ProtectedBy = 'protected_by';
    case ConnectedTo = 'connected_to';
    case DependsOn = 'depends_on';
    case BackedUpBy = 'backed_up_by';
    case PoweredBy = 'powered_by';

    public function label(): string
    {
        return match ($this) {
            self::Hosts => 'héberge',
            self::ProtectedBy => 'est protégé par',
            self::ConnectedTo => 'est connecté à',
            self::DependsOn => 'dépend de',
            self::BackedUpBy => 'est sauvegardé par',
            self::PoweredBy => 'est alimenté par',
        };
    }

    /**
     * Libellé vu depuis l'équipement cible.
     */
    public function inverseLabel(): string
    {
        return match ($this) {
            self::Hosts => 'est hébergé par',
            self::ProtectedBy => 'protège',
            self::ConnectedTo => 'est connecté à',
            self::DependsOn => 'est requis par',
            self::BackedUpBy => 'sauvegarde',
            self::PoweredBy => 'alimente',
        };
    }
}
