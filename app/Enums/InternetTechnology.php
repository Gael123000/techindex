<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Technologie d'un lien d'accès à Internet.
 */
enum InternetTechnology: string
{
    use HasOptions;

    case Ftth = 'ftth';
    case Ftto = 'ftto';
    case Sdsl = 'sdsl';
    case Xdsl = 'xdsl';
    case Cable = 'cable';
    case Mobile = 'mobile';
    case Satellite = 'satellite';
    case Radio = 'radio';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Ftth => 'Fibre mutualisée (FTTH)',
            self::Ftto => 'Fibre dédiée (FTTO)',
            self::Sdsl => 'SDSL',
            self::Xdsl => 'ADSL / VDSL',
            self::Cable => 'Câble (FTTLA)',
            self::Mobile => '4G / 5G',
            self::Satellite => 'Satellite',
            self::Radio => 'Radio (THD radio, faisceau hertzien)',
            self::Other => 'Autre',
        };
    }

    /**
     * Correspondance avec les choix de l'ancienne catégorie de service
     * « Connexion Internet ».
     */
    public static function fromLegacyLabel(?string $label): ?self
    {
        return match ($label) {
            'Fibre FTTH' => self::Ftth,
            'Fibre dédiée (FTTO)' => self::Ftto,
            'SDSL' => self::Sdsl,
            'ADSL / VDSL' => self::Xdsl,
            '4G / 5G' => self::Mobile,
            'Satellite' => self::Satellite,
            'Autre' => self::Other,
            default => null,
        };
    }
}
