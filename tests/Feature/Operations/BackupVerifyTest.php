<?php

namespace Tests\Feature\Operations;

use App\Models\Client;
use App\Services\Backup\BackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\CommittedDataTestCase;
use Throwable;

/**
 * Test de restauration dans une base dédiée existante (BACKUP_VERIFY_DATABASE) :
 * cas des hébergements Plesk, où l'utilisateur MariaDB ne peut pas créer de base.
 * Les données sont validées en base, car mariadb-dump lit sa propre session.
 */
class BackupVerifyTest extends CommittedDataTestCase
{
    private string $directory;

    private ?string $verifyDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/techindex-backup-test-'.uniqid();
        config(['techindex.backups.path' => $this->directory, 'techindex.backups.age_recipient' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        if ($this->verifyDatabase !== null) {
            DB::statement("DROP DATABASE IF EXISTS `{$this->verifyDatabase}`");
        }

        parent::tearDown();
    }

    public function test_verification_never_targets_the_application_database(): void
    {
        $database = DB::connection()->getDatabaseName();
        config(['techindex.backups.verify_database' => strtoupper($database)]);
        $tables = Schema::getTableListing($database, false);

        $result = app(BackupService::class)->verify($this->directory.'/techindex-backup-absente.zip');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('base de l\'application', $result['checks'][0]['detail']);
        $this->assertSame($tables, Schema::getTableListing($database, false));
    }

    public function test_verification_refuses_an_invalid_database_name(): void
    {
        config(['techindex.backups.verify_database' => 'verif`; DROP DATABASE x; --']);

        $result = app(BackupService::class)->verify($this->directory.'/techindex-backup-absente.zip');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Nom de base de vérification invalide', $result['checks'][0]['detail']);
    }

    public function test_verification_uses_the_dedicated_database_and_leaves_it_empty(): void
    {
        $database = $this->prepareVerifyDatabase();
        Client::factory()->count(2)->create();
        $archive = app(BackupService::class)->create()['path'];

        // Une table étrangère à TechIndex : la base n'est ni vidée ni utilisée.
        DB::connection('verify_target')->statement('CREATE TABLE wp_posts (id INT PRIMARY KEY)');
        $refused = app(BackupService::class)->verify($archive);

        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('wp_posts', (string) collect($refused['checks'])->last()['detail']);
        $this->assertSame(['wp_posts'], Schema::connection('verify_target')->getTableListing($database, false));

        DB::connection('verify_target')->statement('DROP TABLE wp_posts');
        $result = app(BackupService::class)->verify($archive);

        $this->assertTrue($result['ok'], (string) json_encode($result['checks'], JSON_UNESCAPED_UNICODE));
        $this->assertSame([], Schema::connection('verify_target')->getTableListing($database, false));
    }

    /**
     * Base accordée à l'utilisateur de test par l'installation (Docker ou
     * locale) : <base>_restore_check, sans le suffixe _test.
     */
    private function prepareVerifyDatabase(): string
    {
        foreach (['mariadb_dump', 'mariadb'] as $tool) {
            $binary = (string) config("techindex.backups.binaries.{$tool}");

            try {
                (new Process([$binary, '--version']))->mustRun();
            } catch (Throwable) {
                $this->markTestSkipped("Outil {$binary} indisponible : test de sauvegarde ignoré.");
            }
        }

        $database = preg_replace('/_test$/', '', DB::connection()->getDatabaseName()).'_restore_check';

        try {
            DB::statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Throwable) {
            $this->markTestSkipped("Base {$database} non accordée à l'utilisateur de test.");
        }

        $this->verifyDatabase = $database;
        config([
            'techindex.backups.verify_database' => $database,
            'database.connections.verify_target' => [...config('database.connections.'.config('database.default')), 'database' => $database],
        ]);

        return $database;
    }
}
