<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AccessType: string
{
    use HasOptions;

    case Web = 'web';
    case Ssh = 'ssh';
    case Rdp = 'rdp';
    case Api = 'api';
    case Vpn = 'vpn';
    case Database = 'database';
    case Snmp = 'snmp';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Administration web',
            self::Ssh => 'SSH',
            self::Rdp => 'RDP',
            self::Api => 'API',
            self::Vpn => 'VPN',
            self::Database => 'Base de données',
            self::Snmp => 'SNMP',
            self::Other => 'Autre',
        };
    }

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Ssh => 22,
            self::Rdp => 3389,
            self::Web => 443,
            self::Snmp => 161,
            default => null,
        };
    }
}
