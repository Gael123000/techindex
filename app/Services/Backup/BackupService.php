<?php

namespace App\Services\Backup;

use App\Secrets\Contracts\SecretVault;
use App\Secrets\Exceptions\SecretVaultException;
use App\Secrets\Keyring;
use App\Secrets\SealedSecret;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Sauvegarde, restauration et vérification de restauration.
 *
 * Contenu d'une archive :
 * - database.sql : export cohérent de MariaDB (--single-transaction) ;
 * - files/ : pièces jointes et logos du disque privé ;
 * - env.redacted : paramètres de fonctionnement SANS clés ni mots de passe ;
 * - manifest.json : versions, nombre de lignes par table, empreintes des
 *   fichiers et des clés (jamais les clés elles-mêmes).
 *
 * Si BACKUP_AGE_RECIPIENT est défini, l'archive est chiffrée avec "age" pour
 * une clé publique dont la clé privée est conservée hors du serveur.
 */
class BackupService
{
    public const FORMAT = 1;

    private const FILE_DIRECTORIES = ['attachments', 'logos'];

    /**
     * Tables techniques dont le contenu évolue en continu : sauvegardées mais
     * exclues de la comparaison du nombre de lignes lors de la vérification.
     */
    private const VOLATILE_TABLES = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'audit_logs', 'password_reset_tokens'];

    public function __construct(
        private readonly DatabaseClient $database,
        private readonly Keyring $keyring,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{path: string, size: int, encrypted: bool, manifest: array<string, mixed>}
     */
    public function create(): array
    {
        $directory = $this->backupDirectory();
        $name = 'techindex-backup-'.now()->format('Ymd-His');
        $work = $this->temporaryDirectory();

        try {
            $sqlFile = $work.'/database.sql';
            $this->database->dump($sqlFile);

            $files = $this->fileInventory();
            $manifest = [
                'format' => self::FORMAT,
                'application' => config('app.name'),
                'created_at' => now()->toIso8601String(),
                'database' => [
                    'driver' => DB::connection()->getDriverName(),
                    'server_version' => (string) DB::selectOne('SELECT VERSION() AS v')->v,
                    'last_migration' => DB::table('migrations')->orderByDesc('id')->value('migration'),
                    'sql_sha256' => hash_file('sha256', $sqlFile),
                    'tables' => $this->rowCounts(),
                ],
                'keys' => $this->keyFingerprints(),
                'files' => $files,
            ];

            $zipPath = $directory.'/'.$name.'.zip';
            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Impossible de créer l\'archive.');
            }

            $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $zip->addFile($sqlFile, 'database.sql');
            $zip->addFromString('env.redacted', $this->redactedEnvironment());

            foreach (array_keys($files) as $relative) {
                $zip->addFile(Storage::disk('local')->path($relative), 'files/'.$relative);
            }

            $zip->close();
            @chmod($zipPath, 0600);

            $finalPath = $zipPath;
            $recipient = config('techindex.backups.age_recipient');

            if (filled($recipient)) {
                $finalPath = $zipPath.'.age';
                (new Process([$this->ageBinary(), '-r', (string) $recipient, '-o', $finalPath, $zipPath]))->setTimeout(3600)->mustRun();
                @chmod($finalPath, 0600);
                @unlink($zipPath);
            }

            $this->pruneOldArchives($directory);

            $this->audit->log('backup.created', metadata: [
                'fichier' => basename($finalPath),
                'taille' => filesize($finalPath),
                'chiffrée' => filled($recipient),
            ]);

            return ['path' => $finalPath, 'size' => (int) filesize($finalPath), 'encrypted' => filled($recipient), 'manifest' => $manifest];
        } finally {
            $this->deleteDirectory($work);
        }
    }

    /**
     * Restaure une archive sur l'installation courante (données ÉCRASÉES).
     *
     * @return array<string, mixed>
     */
    public function restore(string $archive, ?string $identity = null, bool $ignoreKeyMismatch = false): array
    {
        $work = $this->temporaryDirectory();

        try {
            $zip = $this->open($archive, $identity, $work);
            $manifest = $this->manifest($zip);
            $keyCheck = $this->compareKeys($manifest);

            if (! $keyCheck['match'] && ! $ignoreKeyMismatch) {
                throw new RuntimeException('Les clés installées ne correspondent pas à celles de la sauvegarde ('.$keyCheck['detail'].'). '
                    .'Restaurez d\'abord APP_KEY et SECRETS_KEY depuis la sauvegarde des clés, ou relancez avec --ignore-keys '
                    .'(les secrets et la double authentification seront alors inutilisables).');
            }

            $sqlFile = $this->extractDatabase($zip, $manifest, $work);

            // Tout est contrôlé avant de toucher aux données actuelles.
            $this->assertFilesIntact($zip, $manifest);

            Schema::dropAllTables();
            $this->database->import($sqlFile);
            DB::purge();

            $restoredFiles = $this->restoreFiles($zip, $manifest);
            $zip->close();

            Artisan::call('migrate', ['--force' => true]);

            $secrets = $this->checkSecrets();
            $this->audit->log('backup.restored', metadata: [
                'fichier' => basename($archive),
                'sauvegarde_du' => $manifest['created_at'],
                'fichiers' => $restoredFiles,
                'secrets_illisibles' => $secrets['failed'],
            ]);

            return [
                'created_at' => $manifest['created_at'],
                'tables' => $manifest['database']['tables'],
                'files' => $restoredFiles,
                'keys' => $keyCheck,
                'secrets' => $secrets,
            ];
        } finally {
            $this->deleteDirectory($work);
        }
    }

    /**
     * Test de restauration sans toucher aux données : l'archive est restaurée
     * dans une base temporaire (ou dans la base dédiée BACKUP_VERIFY_DATABASE),
     * puis comparée au manifeste.
     *
     * @return array{ok: bool, checks: list<array{label: string, ok: bool, detail: string}>}
     */
    public function verify(string $archive, ?string $identity = null): array
    {
        $work = $this->temporaryDirectory();
        $checks = [];
        $add = function (string $label, bool $ok, string $detail = '') use (&$checks): void {
            $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };

        $connection = config('database.default');
        $main = (string) config("database.connections.{$connection}.database");
        $dedicated = config('techindex.backups.verify_database') !== null;
        $temporary = $dedicated ? (string) config('techindex.backups.verify_database') : $main.'_restore_check';
        $validName = preg_match('/^[\w$-]+$/', $temporary) === 1;
        $prepared = false;

        config(['database.connections.restore_check' => [...config("database.connections.{$connection}"), 'database' => $temporary]]);
        DB::purge('restore_check');

        try {
            if (! $validName) {
                throw new RuntimeException("Nom de base de vérification invalide : {$temporary}");
            }

            if (strcasecmp($temporary, $main) === 0) {
                throw new RuntimeException('BACKUP_VERIFY_DATABASE désigne la base de l\'application : indiquez une base distincte, réservée au test de restauration.');
            }

            $zip = $this->open($archive, $identity, $work);
            $manifest = $this->manifest($zip);
            $add('Archive lisible et manifeste valide', true, 'sauvegarde du '.$manifest['created_at']);

            $keys = $this->compareKeys($manifest);
            $add('Clés installées identiques à celles de la sauvegarde', $keys['match'], $keys['detail']);

            $sqlFile = $this->extractDatabase($zip, $manifest, $work);
            $add('Intégrité de l\'export SQL (SHA-256)', true);

            $this->prepareVerifyDatabase($temporary, $dedicated, $manifest);
            $prepared = true;
            $this->database->import($sqlFile, $temporary);
            DB::purge('restore_check');

            $mismatches = [];

            foreach ($manifest['database']['tables'] as $table => $expected) {
                $actual = DB::connection('restore_check')->table($table)->count();

                if ($actual !== $expected) {
                    $mismatches[] = "{$table} : {$actual}/{$expected}";
                }
            }

            $add('Nombre de lignes de chaque table', $mismatches === [], $mismatches === [] ? count($manifest['database']['tables']).' tables conformes' : implode(', ', $mismatches));

            $badFiles = [];

            foreach ($manifest['files'] as $relative => $meta) {
                $content = $zip->getFromName('files/'.$relative);

                if ($content === false || hash('sha256', $content) !== $meta['sha256']) {
                    $badFiles[] = $relative;
                }
            }

            $add('Pièces jointes présentes et intactes', $badFiles === [], count($manifest['files']).' fichier(s)'.($badFiles === [] ? '' : ', en défaut : '.implode(', ', array_slice($badFiles, 0, 5))));

            $secrets = $this->checkSecrets('restore_check');
            $add('Secrets déchiffrables avec les clés installées', $secrets['failed'] === 0, "{$secrets['total']} secret(s), {$secrets['failed']} illisible(s)");

            $zip->close();
        } catch (\Throwable $exception) {
            $add('Restauration de test', false, $exception->getMessage());
        } finally {
            // Une base dédiée n'est vidée que si elle a passé le contrôle des tables.
            if ($validName && ($prepared || ! $dedicated)) {
                $this->discardVerifyDatabase($temporary, $dedicated);
            }

            DB::purge('restore_check');
            $this->deleteDirectory($work);
        }

        $ok = collect($checks)->every(fn (array $check): bool => $check['ok']);
        $this->audit->log('backup.verified', metadata: ['fichier' => basename($archive), 'résultat' => $ok ? 'succès' : 'échec']);

        return ['ok' => $ok, 'checks' => $checks];
    }

    /**
     * @return array<string, array{size: int, sha256: string}>
     */
    private function fileInventory(): array
    {
        $disk = Storage::disk('local');
        $files = [];

        foreach (self::FILE_DIRECTORIES as $directory) {
            foreach ($disk->allFiles($directory) as $relative) {
                $files[$relative] = ['size' => $disk->size($relative), 'sha256' => hash_file('sha256', $disk->path($relative))];
            }
        }

        return $files;
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (array_diff($this->tableNames(), self::VOLATILE_TABLES) as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Tables de la base de l'application uniquement : l'utilisateur MariaDB
     * peut en voir d'autres (base de vérification, autres bases d'un abonnement Plesk).
     *
     * @return list<string>
     */
    private function tableNames(): array
    {
        return Schema::getTableListing(DB::connection()->getDatabaseName(), false);
    }

    /**
     * Base temporaire créée pour le test, ou base dédiée existante, qui ne
     * doit contenir aucune table étrangère à TechIndex avant d'être vidée.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function prepareVerifyDatabase(string $database, bool $dedicated, array $manifest): void
    {
        if (! $dedicated) {
            $this->database->statement("DROP DATABASE IF EXISTS `{$database}`; CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");

            return;
        }

        $known = [...array_keys($manifest['database']['tables']), ...self::VOLATILE_TABLES, ...$this->tableNames()];
        $foreign = array_diff(Schema::connection('restore_check')->getTableListing($database, false), $known);

        if ($foreign !== []) {
            throw new RuntimeException("La base de vérification {$database} contient des tables étrangères à TechIndex ("
                .implode(', ', array_slice($foreign, 0, 5)).') : elle doit être vide et réservée au test de restauration.');
        }

        Schema::connection('restore_check')->dropAllTables();
    }

    private function discardVerifyDatabase(string $database, bool $dedicated): void
    {
        try {
            if ($dedicated) {
                DB::purge('restore_check');
                Schema::connection('restore_check')->dropAllTables();
            } else {
                $this->database->statement("DROP DATABASE IF EXISTS `{$database}`;");
            }
        } catch (\Throwable) {
            // Base déjà absente ou déjà vide.
        }
    }

    /**
     * @return array{app_key: string|null, secrets_key: string|null}
     */
    private function keyFingerprints(): array
    {
        $appKey = (string) config('app.key');
        $raw = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7)) : $appKey;

        return [
            'app_key' => $appKey === '' ? null : substr(hash('sha256', 'techindex-app-key|'.$raw), 0, 16),
            'secrets_key' => $this->keyring->isConfigured() ? $this->keyring->currentId() : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{match: bool, detail: string}
     */
    private function compareKeys(array $manifest): array
    {
        $current = $this->keyFingerprints();
        $known = $this->keyring->isConfigured() ? $this->keyring->fingerprints() : [];
        $problems = [];

        if (($manifest['keys']['app_key'] ?? null) !== $current['app_key']) {
            $problems[] = 'APP_KEY différente';
        }

        if (($manifest['keys']['secrets_key'] ?? null) !== null && ! in_array($manifest['keys']['secrets_key'], $known, true)) {
            $problems[] = 'clé des secrets '.$manifest['keys']['secrets_key'].' absente';
        }

        return ['match' => $problems === [], 'detail' => $problems === [] ? 'empreintes identiques' : implode(', ', $problems)];
    }

    /**
     * @return array{total: int, failed: int}
     */
    private function checkSecrets(?string $connection = null): array
    {
        $vault = app(SecretVault::class);
        $total = 0;
        $failed = 0;

        DB::connection($connection)->table('credentials')->whereNotNull('secret_ciphertext')->orderBy('id')
            ->select(['id', 'secret_ciphertext', 'secret_key_id'])
            ->chunkById(200, function ($rows) use ($vault, &$total, &$failed): void {
                foreach ($rows as $row) {
                    $total++;

                    try {
                        $vault->unseal(new SealedSecret($row->secret_ciphertext, (string) $row->secret_key_id));
                    } catch (SecretVaultException) {
                        $failed++;
                    }
                }
            });

        return ['total' => $total, 'failed' => $failed];
    }

    private function open(string $archive, ?string $identity, string $work): ZipArchive
    {
        if (! is_file($archive)) {
            throw new RuntimeException("Archive introuvable : {$archive}");
        }

        $zipPath = $archive;

        if (str_ends_with($archive, '.age')) {
            if ($identity === null || ! is_file($identity)) {
                throw new RuntimeException('Archive chiffrée : indiquez le fichier de clé privée age avec --identity.');
            }

            $zipPath = $work.'/archive.zip';
            (new Process([$this->ageBinary(), '-d', '-i', $identity, '-o', $zipPath, $archive]))->setTimeout(3600)->mustRun();
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Archive illisible ou corrompue.');
        }

        return $zip;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(ZipArchive $zip): array
    {
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('Manifeste absent ou format de sauvegarde non pris en charge.');
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function extractDatabase(ZipArchive $zip, array $manifest, string $work): string
    {
        $target = $work.'/database.sql';
        $stream = $zip->getStream('database.sql');

        if ($stream === false) {
            throw new RuntimeException('Export SQL absent de l\'archive.');
        }

        $output = fopen($target, 'wb');
        stream_copy_to_stream($stream, $output);
        fclose($stream);
        fclose($output);

        if (hash_file('sha256', $target) !== $manifest['database']['sql_sha256']) {
            throw new RuntimeException('L\'export SQL est altéré (empreinte SHA-256 différente).');
        }

        return $target;
    }

    /**
     * Vérifie chemins et empreintes de tous les fichiers de l'archive.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function assertFilesIntact(ZipArchive $zip, array $manifest): void
    {
        foreach ($manifest['files'] as $relative => $meta) {
            $this->fileContent($zip, (string) $relative, (array) $meta);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function fileContent(ZipArchive $zip, string $relative, array $meta): string
    {
        // Seuls les chemins attendus sont acceptés (pas de traversée de répertoire).
        if (! preg_match('#^(attachments/\d+/[0-9a-f-]{36}|logos/[0-9a-f-]{36}\.(png|jpe?g|webp))$#', $relative)) {
            throw new RuntimeException("Chemin de fichier inattendu dans l'archive : {$relative}");
        }

        $content = $zip->getFromName('files/'.$relative);

        if ($content === false || hash('sha256', $content) !== ($meta['sha256'] ?? null)) {
            throw new RuntimeException("Fichier manquant ou altéré dans l'archive : {$relative}");
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function restoreFiles(ZipArchive $zip, array $manifest): int
    {
        $disk = Storage::disk('local');

        foreach (self::FILE_DIRECTORIES as $directory) {
            $disk->deleteDirectory($directory);
        }

        $count = 0;

        foreach ($manifest['files'] as $relative => $meta) {
            $disk->put((string) $relative, $this->fileContent($zip, (string) $relative, (array) $meta));
            $count++;
        }

        return $count;
    }

    private function redactedEnvironment(): string
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            return "# Aucun fichier .env (configuration fournie par l'environnement).\n";
        }

        $lines = [
            '# Paramètres de fonctionnement de TechIndex (copie expurgée).',
            '# Les clés et mots de passe sont remplacés : ils se restaurent depuis',
            '# la sauvegarde séparée des clés (docs/secrets-et-cles.md).',
        ];

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $matches) && preg_match('/(KEY|PASSWORD|SECRET|TOKEN|RECIPIENT)/', $matches[1])) {
                $line = $matches[1].'=<sauvegardé séparément>';
            }

            $lines[] = $line;
        }

        return implode("\n", $lines)."\n";
    }

    private function backupDirectory(): string
    {
        $directory = rtrim((string) config('techindex.backups.path'), '/\\');

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Répertoire de sauvegarde inaccessible : {$directory}");
        }

        return $directory;
    }

    private function pruneOldArchives(string $directory): void
    {
        $keep = (int) config('techindex.backups.keep');

        if ($keep <= 0) {
            return;
        }

        $archives = glob($directory.'/techindex-backup-*.zip*') ?: [];
        rsort($archives);

        foreach (array_slice($archives, $keep) as $old) {
            @unlink($old);
        }
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/techindex-'.Str::random(12);
        mkdir($path, 0700, true);

        return $path;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (glob($path.'/*') ?: [] as $file) {
            is_dir($file) ? $this->deleteDirectory($file) : @unlink($file);
        }

        @rmdir($path);
    }

    private function ageBinary(): string
    {
        return (string) config('techindex.backups.binaries.age', 'age');
    }
}
