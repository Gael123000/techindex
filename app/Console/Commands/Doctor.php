<?php

namespace App\Console\Commands;

use App\Secrets\Keyring;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Vérifications de l'installation (sans afficher aucune valeur secrète).
 */
class Doctor extends Command
{
    protected $signature = 'techindex:doctor';

    protected $description = 'Contrôle la configuration de l\'installation TechIndex';

    private int $errors = 0;

    public function handle(Keyring $keyring): int
    {
        $production = $this->laravel->isProduction();

        $this->check('Clé de l\'application (APP_KEY)', filled(config('app.key')));
        $this->check('Clé des secrets (SECRETS_KEY / SECRETS_KEY_FILE)', $keyring->isConfigured());
        $this->check('Mode debug désactivé en production', ! $production || ! config('app.debug'), warnOnly: ! $production);
        $this->check('Cookies de session « secure » en production', ! $production || (bool) config('session.secure'), warnOnly: ! $production);
        $this->check('URL de l\'application en HTTPS en production', ! $production || str_starts_with((string) config('app.url'), 'https://'), warnOnly: true);

        try {
            DB::connection()->getPdo();
            $this->check('Connexion à la base de données ('.DB::connection()->getDriverName().')', true);
            $this->check('Migrations appliquées', Schema::hasTable('audit_logs') && Schema::hasTable('credentials'));
            $version = (string) DB::selectOne('SELECT VERSION() AS v')->v;
            $this->components->twoColumnDetail('Version du serveur de base de données', $version);
            // MariaDB 11.8 est la version de référence ; une version plus ancienne
            // est signalée, un autre serveur (MySQL...) n'est pas pris en charge.
            $mariadb = str_contains(strtolower($version), 'mariadb');
            preg_match('/^\d+\.\d+/', $version, $number);
            $this->check('Serveur MariaDB 10.11 ou plus récent', $mariadb && version_compare($number[0] ?? '0', '10.11', '>='), $mariadb ? null : 'autre serveur non pris en charge', warnOnly: $mariadb);
        } catch (Throwable $exception) {
            $this->check('Connexion à la base de données', false, $exception->getMessage());
        }

        try {
            // Témoin écrit chaque minute par le planificateur (routes/console.php).
            $heartbeat = Cache::get('techindex:scheduler-heartbeat');
            $minutes = is_numeric($heartbeat) ? intdiv(now()->getTimestamp() - (int) $heartbeat, 60) : null;
            $this->check('Planificateur actif (tâche cron ou service scheduler)', $minutes !== null && $minutes < 5, $minutes === null ? 'aucune exécution enregistrée' : "dernière exécution il y a {$minutes} min", warnOnly: true);
        } catch (Throwable) {
            $this->check('Planificateur actif (tâche cron ou service scheduler)', false, 'état illisible', warnOnly: true);
        }

        foreach ([storage_path('app/private'), storage_path('logs'), storage_path('framework/cache'), base_path('bootstrap/cache')] as $path) {
            $this->check('Répertoire accessible en écriture : '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $path), is_dir($path) && is_writable($path));
        }

        $this->check('File d\'attente (QUEUE_CONNECTION = '.config('queue.default').')', config('queue.default') !== 'sync' || ! $production, warnOnly: true);
        $this->check('Envoi d\'e-mails configuré (MAIL_MAILER = '.config('mail.default').')', ! in_array(config('mail.default'), ['log', 'array'], true), warnOnly: true);
        $this->check('Extension PHP intl', extension_loaded('intl'));
        $this->check('Extension PHP zip (imports Excel)', extension_loaded('zip'));
        $this->check('Extension PHP fileinfo (contrôle des fichiers)', extension_loaded('fileinfo'));
        $this->check('Extension PHP sodium', extension_loaded('sodium'), warnOnly: true);
        $this->check('Fonction PHP proc_open (sauvegardes, tâches en arrière-plan)', function_exists('proc_open'));

        foreach (['mariadb_dump' => 'mariadb-dump', 'mariadb' => 'mariadb'] as $key => $tool) {
            $binary = (string) config("techindex.backups.binaries.{$key}");
            $this->check("Outil de sauvegarde {$tool}", $this->executable($binary), $binary, warnOnly: true);
        }

        if (filled(config('techindex.backups.age_recipient'))) {
            $age = (string) config('techindex.backups.binaries.age');
            $this->check('Chiffrement des sauvegardes (age)', $this->executable($age), $age);
        }

        $this->newLine();

        if ($this->errors > 0) {
            $this->components->error("{$this->errors} problème(s) bloquant(s) détecté(s).");

            return self::FAILURE;
        }

        $this->components->info('Installation opérationnelle.');

        return self::SUCCESS;
    }

    private function executable(string $binary): bool
    {
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return is_file($binary) && is_executable($binary);
        }

        return (new ExecutableFinder)->find($binary) !== null;
    }

    private function check(string $label, bool $ok, ?string $detail = null, bool $warnOnly = false): void
    {
        $status = $ok ? '<fg=green>OK</>' : ($warnOnly ? '<fg=yellow>À vérifier</>' : '<fg=red>ERREUR</>');
        $this->components->twoColumnDetail($label.($detail ? " ({$detail})" : ''), $status);

        if (! $ok && ! $warnOnly) {
            $this->errors++;
        }
    }
}
