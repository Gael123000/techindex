<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum UserRole: string
{
    use HasOptions;

    case Admin = 'admin';
    case Manager = 'manager';
    case Technician = 'technician';
    case Reader = 'reader';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::Manager => 'Responsable technique',
            self::Technician => 'Technicien',
            self::Reader => 'Lecteur',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Configuration, utilisateurs et ensemble des clients.',
            self::Manager => 'Gestion des clients qui lui sont attribués.',
            self::Technician => 'Consultation et modification des ressources autorisées.',
            self::Reader => 'Consultation des informations autorisées, sans accès aux secrets par défaut.',
        };
    }

    /**
     * Droits proposés par défaut lors de l'affectation d'un utilisateur de ce
     * rôle à un client. L'administrateur peut ensuite les ajuster client par client.
     *
     * @return array<string, bool>
     */
    public function defaultClientPermissions(): array
    {
        return match ($this) {
            self::Admin, self::Manager => [
                'can_edit' => true,
                'can_export' => true,
                'can_view_secrets' => true,
                'can_manage' => true,
            ],
            self::Technician => [
                'can_edit' => true,
                'can_export' => false,
                'can_view_secrets' => true,
                'can_manage' => false,
            ],
            self::Reader => [
                'can_edit' => false,
                'can_export' => false,
                'can_view_secrets' => false,
                'can_manage' => false,
            ],
        };
    }

    public function canCreateClients(): bool
    {
        return $this === self::Admin || $this === self::Manager;
    }
}
