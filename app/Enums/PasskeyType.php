<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Type de clé d'accès, choisi dans une liste à l'ajout : il sert de nom
 * à la clé, sauf si l'utilisateur ajoute une précision (« PC du bureau »).
 */
enum PasskeyType: string
{
    use HasOptions;

    case WindowsHello = 'windows_hello';
    case Apple = 'apple';
    case Android = 'android';
    case SecurityKey = 'security_key';
    case PasswordManager = 'password_manager';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WindowsHello => 'Windows Hello',
            self::Apple => 'Touch ID / Face ID (Mac, iPhone, iPad)',
            self::Android => 'Téléphone ou tablette Android',
            self::SecurityKey => 'Clé de sécurité USB / NFC (YubiKey…)',
            self::PasswordManager => 'Gestionnaire de mots de passe (Google, Bitwarden, 1Password…)',
            self::Other => 'Autre',
        };
    }

    /**
     * Nom court, affiché dans la liste des clés et le journal d'audit.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::WindowsHello => 'Windows Hello',
            self::Apple => 'Touch ID / Face ID',
            self::Android => 'Android',
            self::SecurityKey => 'Clé de sécurité',
            self::PasswordManager => 'Gestionnaire de mots de passe',
            self::Other => 'Clé d\'accès',
        };
    }
}
