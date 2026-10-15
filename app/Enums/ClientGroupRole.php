<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Place d'un client dans un groupe d'entreprises.
 *
 * Une tête de groupe (siège, holding) n'a pas d'entreprise mère ; un membre
 * (filiale, établissement, franchisé) est toujours rattaché à une autre.
 */
enum ClientGroupRole: string
{
    use HasOptions;

    case Headquarters = 'headquarters';
    case Holding = 'holding';
    case Subsidiary = 'subsidiary';
    case Branch = 'branch';
    case Franchise = 'franchise';

    public function label(): string
    {
        return match ($this) {
            self::Headquarters => 'Siège social / maison mère',
            self::Holding => 'Holding',
            self::Subsidiary => 'Filiale',
            self::Branch => 'Établissement / agence',
            self::Franchise => 'Franchisé',
        };
    }

    /** Libellé court pour les listes. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Headquarters => 'Siège social',
            self::Holding => 'Holding',
            self::Subsidiary => 'Filiale',
            self::Branch => 'Établissement',
            self::Franchise => 'Franchisé',
        };
    }

    public function isHead(): bool
    {
        return in_array($this, [self::Headquarters, self::Holding], true);
    }

    /**
     * Rôles possibles sans entreprise mère.
     *
     * @return list<self>
     */
    public static function heads(): array
    {
        return [self::Headquarters, self::Holding];
    }

    /**
     * Rôles possibles pour une entreprise rattachée.
     *
     * @return list<self>
     */
    public static function members(): array
    {
        return [self::Subsidiary, self::Branch, self::Franchise];
    }
}
