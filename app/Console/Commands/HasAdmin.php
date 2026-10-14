<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Code retour 0 si au moins un administrateur actif existe, 1 sinon.
 * Utilisé par les scripts d'installation pour proposer la création du premier compte.
 */
class HasAdmin extends Command
{
    protected $signature = 'techindex:has-admin';

    protected $description = 'Indique (code retour) si un administrateur actif existe';

    public function handle(): int
    {
        $exists = User::query()->where('role', UserRole::Admin)->where('is_active', true)->exists();

        $this->line($exists ? 'Un administrateur actif existe.' : 'Aucun administrateur actif.');

        return $exists ? self::SUCCESS : self::FAILURE;
    }
}
