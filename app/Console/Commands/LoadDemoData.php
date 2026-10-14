<?php

namespace App\Console\Commands;

use App\Models\Client;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

/**
 * Charge, sur demande, des données de démonstration entièrement fictives.
 * Refusé en production sauf option --force explicite.
 */
class LoadDemoData extends Command
{
    protected $signature = 'techindex:demo {--force : Autorise le chargement en environnement de production}';

    protected $description = 'Charge des données de démonstration fictives (clients DEMO-xxx)';

    public function handle(): int
    {
        if ($this->laravel->isProduction() && ! $this->option('force')) {
            $this->components->error('Environnement de production : relancez avec --force si vous voulez vraiment charger des données fictives.');

            return self::FAILURE;
        }

        if (Client::query()->where('reference', 'like', DemoSeeder::REFERENCE_PREFIX.'%')->exists()) {
            $this->components->info('Les données de démonstration sont déjà présentes : rien à faire.');

            return self::SUCCESS;
        }

        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        $this->components->info('Données de démonstration chargées (clients DEMO-001 à DEMO-003).');
        $this->line('  Comptes de démonstration (mot de passe aléatoire inconnu) : responsable.demo@, technicien.demo@ et lecteur.demo@techindex.test.');
        $this->line('  Pour les utiliser : Administration > Utilisateurs > « Envoyer un lien de réinitialisation » (visible dans Mailpit en local).');

        return self::SUCCESS;
    }
}
