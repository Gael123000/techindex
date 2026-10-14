<?php

namespace Tests\Feature\Operations;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Updates\Release;
use App\Services\Updates\ReleaseChannel;
use App\Services\Updates\UpdateException;
use App\Services\Updates\UpdateLauncher;
use App\Services\Updates\UpdateManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Mises à jour : seules les versions dont le manifeste est signé par la clé
 * du canal officiel sont proposées ; l'installation depuis l'interface est
 * réservée aux administrateurs, confirmée, auditée et confiée au script Plesk.
 */
class UpdateTest extends TestCase
{
    private const BASE = 'https://github.test/acme/techindex/releases/latest/download';

    private string $secretKey;

    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->script = sys_get_temp_dir().'/techindex-plesk-test-'.uniqid().'.sh';
        File::put($this->script, "#!/usr/bin/env bash\n");

        config([
            'techindex.version' => '1.0.0',
            'techindex.updates.enabled' => true,
            'techindex.updates.repository' => 'acme/techindex',
            'techindex.updates.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'techindex.updates.base_url' => 'https://github.test',
            'techindex.updates.deployment' => 'plesk',
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::delete($this->script);

        parent::tearDown();
    }

    public function test_a_newer_signed_release_is_offered(): void
    {
        $this->publish('1.1.0', migrations: true);

        $result = app(UpdateManager::class)->check();

        $this->assertNull($result['error']);
        $this->assertTrue($result['available']);
        $this->assertSame('1.1.0', $result['latest']['version']);
        $this->assertTrue($result['latest']['migrations']);
        $this->assertTrue(app(UpdateManager::class)->updateAvailable());
    }

    public function test_an_identical_or_older_release_is_not_offered(): void
    {
        $this->publish('1.0.0');
        $updates = app(UpdateManager::class);

        $this->assertFalse($updates->check()['available']);
        $this->assertFalse($updates->updateAvailable());
        $this->assertFalse($updates->isNewer('0.9.4'));
        $this->assertFalse($updates->isNewer('1.0.0-rc.1'));
        $this->assertTrue($updates->isNewer('1.0.1-dev.202610150900'));
    }

    public function test_a_release_signed_with_another_key_is_ignored(): void
    {
        $this->publish('9.0.0', secretKey: sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));

        $result = app(UpdateManager::class)->check();

        $this->assertFalse($result['available']);
        $this->assertNull($result['latest']);
        $this->assertStringContainsString('Signature invalide', (string) $result['error']);
    }

    public function test_a_modified_manifest_is_rejected(): void
    {
        $manifest = $this->manifest('1.1.0');
        $signature = base64_encode(sodium_crypto_sign_detached($manifest, $this->secretKey));
        Http::fake([
            self::BASE.'/release.json' => Http::response(str_replace('1.1.0', '1.2.0', $manifest)),
            self::BASE.'/release.json.sig' => Http::response($signature),
        ]);

        $this->assertStringContainsString('Signature invalide', (string) app(UpdateManager::class)->check()['error']);
    }

    public function test_an_unconfigured_channel_never_contacts_the_network(): void
    {
        config(['techindex.updates.repository' => '']);

        $this->assertStringContainsString('non configuré', (string) app(UpdateManager::class)->check()['error']);
        Http::assertNothingSent();
    }

    public function test_the_updates_page_is_reserved_to_administrators(): void
    {
        $this->actingAs($this->admin())->get('/admin/updates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Updates/Index')->where('installed', '1.0.0')->where('deployment', 'plesk'));

        $technician = User::factory()->role(UserRole::Technician)->create();
        $this->actingAs($technician)->get('/admin/updates')->assertForbidden();
        $this->actingAs($technician)->post('/admin/updates/check')->assertForbidden();
        $this->actingAs($technician)->withRecentConfirmation()->postJson('/admin/updates/apply', ['version' => '1.1.0'])->assertForbidden();
    }

    public function test_an_administrator_checks_for_updates_from_the_interface(): void
    {
        $this->publish('1.1.0');

        $this->actingAs($this->admin())->from('/admin/updates')->post('/admin/updates/check')
            ->assertRedirect('/admin/updates')
            ->assertSessionHas('success', 'La version 1.1.0 est disponible.');
    }

    public function test_installing_requires_a_recent_confirmation_of_identity(): void
    {
        $this->publish('1.1.0');
        app(UpdateManager::class)->check();

        $this->actingAs($this->admin())->postJson('/admin/updates/apply', ['version' => '1.1.0'])->assertStatus(423);
        $this->assertFalse(Storage::disk('local')->exists('updates/request.json'));
    }

    public function test_installing_requests_the_verified_release_and_is_audited(): void
    {
        $this->usePleskScript();
        $this->publish('1.1.0');
        app(UpdateManager::class)->check();
        $admin = $this->admin();

        // Seule la dernière version vérifiée peut être demandée.
        $this->actingAs($admin)->withRecentConfirmation()->postJson('/admin/updates/apply', ['version' => '1.2.0'])->assertStatus(409);
        $this->actingAs($admin)->withRecentConfirmation()->postJson('/admin/updates/apply', ['version' => '1.1.0; rm -rf /'])->assertStatus(422);

        $this->actingAs($admin)->withRecentConfirmation()->postJson('/admin/updates/apply', ['version' => '1.1.0'])->assertOk();

        $request = json_decode((string) Storage::disk('local')->get('updates/request.json'), true);
        $this->assertSame(['version' => '1.1.0', 'requested_by_id' => $admin->id], array_intersect_key($request, ['version' => 1, 'requested_by_id' => 1]));
        $this->assertTrue(AuditLog::query()->where('event', 'update.requested')->where('user_id', $admin->id)->exists());

        // Une seule demande à la fois.
        $this->actingAs($admin)->withRecentConfirmation()->postJson('/admin/updates/apply', ['version' => '1.1.0'])->assertStatus(409);
    }

    public function test_only_plesk_installations_install_from_the_interface(): void
    {
        config(['techindex.updates.deployment' => 'docker']);
        $this->publish('1.1.0');
        app(UpdateManager::class)->check();

        $this->actingAs($this->admin())->withRecentConfirmation()->postJson('/admin/updates/apply', ['version' => '1.1.0'])->assertStatus(409);
        $this->assertFalse(Storage::disk('local')->exists('updates/request.json'));
    }

    public function test_the_scheduler_launches_a_requested_update_once(): void
    {
        $launcher = Mockery::mock(UpdateLauncher::class);
        $this->usePleskScript($launcher);
        $this->publish('1.1.0');
        $updates = app(UpdateManager::class);
        $updates->check();
        $admin = $this->admin();
        $updates->request('1.1.0', $admin);

        $launcher->shouldReceive('launch')->once()->with($this->script, '1.1.0', $admin->id);

        $this->assertSame('1.1.0', $updates->dispatch());
        $this->assertSame('queued', $updates->status()['state']);
        $this->assertNull($updates->dispatch());
        $this->assertTrue($updates->inProgress());
    }

    public function test_the_outcome_of_an_update_is_audited_once(): void
    {
        Storage::disk('local')->put('updates/status.json', (string) json_encode([
            'state' => 'succeeded', 'version' => '1.1.0', 'from' => '1.0.0', 'message' => 'Version 1.1.0 en service.',
        ]));
        $updates = app(UpdateManager::class);

        $updates->syncStatus();
        $updates->syncStatus();

        $this->assertSame(1, AuditLog::query()->where('event', 'update.completed')->count());
        $this->assertTrue($updates->status()['audited']);
    }

    public function test_the_release_tool_and_the_application_agree_on_signatures(): void
    {
        $directory = sys_get_temp_dir().'/techindex-release-test-'.uniqid();
        File::makeDirectory($directory);
        File::put("{$directory}/techindex-plesk-2.0.0.tar.gz", 'archive');
        File::put("{$directory}/techindex-plesk.sh", 'script');
        File::put("{$directory}/notes.md", "- Nouveauté\n");
        $tool = base_path('scripts/release-tool.php');

        try {
            $keys = $this->runTool([$tool, 'keygen']);
            preg_match('/^SECRET_KEY=(\S+)$/m', $keys, $secret);
            preg_match('/^PUBLIC_KEY=(\S+)$/m', $keys, $public);

            $this->runTool([$tool, 'manifest', $directory, '2.0.0', 'v2.0.0', str_repeat('a', 40), '1', "{$directory}/notes.md"]);
            $this->runTool([$tool, 'sign', "{$directory}/release.json", $public[1]], ['TECHINDEX_SIGNING_KEY' => $secret[1]]);
            $verified = $this->runTool([$tool, 'verify', "{$directory}/release.json", "{$directory}/release.json.sig", $public[1]]);

            $release = Release::fromSignedManifest((string) File::get("{$directory}/release.json"), (string) File::get("{$directory}/release.json.sig"), $public[1]);

            $this->assertStringContainsString('VERSION=2.0.0', $verified);
            $this->assertSame('2.0.0', $release->version);
            $this->assertTrue($release->migrations);
            $this->assertSame(hash('sha256', 'archive'), $release->sha256);
            $this->assertSame('- Nouveauté', $release->notes);

            // Clé privée différente de la clé publique attendue : signature refusée.
            $other = $this->runTool([$tool, 'keygen']);
            preg_match('/^SECRET_KEY=(\S+)$/m', $other, $otherSecret);
            $process = new Process([PHP_BINARY, $tool, 'sign', "{$directory}/release.json", $public[1]], null, ['TECHINDEX_SIGNING_KEY' => $otherSecret[1]]);
            $this->assertSame(1, $process->run());
        } finally {
            File::deleteDirectory($directory);
        }

        $this->expectException(UpdateException::class);
        Release::fromSignedManifest('{"format":1}', base64_encode(str_repeat('x', 64)), $public[1]);
    }

    /**
     * Publie (simule) une version sur le canal, signée par la clé du test.
     */
    private function publish(string $version, bool $migrations = false, ?string $secretKey = null): void
    {
        $manifest = $this->manifest($version, $migrations);

        Http::fake([
            self::BASE.'/release.json' => Http::response($manifest),
            self::BASE.'/release.json.sig' => Http::response(base64_encode(sodium_crypto_sign_detached($manifest, $secretKey ?? $this->secretKey))),
        ]);
    }

    private function manifest(string $version, bool $migrations = false): string
    {
        return (string) json_encode([
            'format' => 1,
            'application' => 'TechIndex',
            'version' => $version,
            'tag' => 'v'.$version,
            'commit' => str_repeat('b', 40),
            'published_at' => '2026-10-15T08:00:00Z',
            'migrations' => $migrations,
            'notes' => "- Correctifs\n- Nouveautés",
            'assets' => ['plesk' => ['name' => "techindex-plesk-{$version}.tar.gz", 'sha256' => str_repeat('c', 64), 'size' => 1024]],
        ]);
    }

    /**
     * Script Plesk factice (le dossier de déploiement n'existe pas en test).
     */
    private function usePleskScript(?UpdateLauncher $launcher = null): void
    {
        $manager = Mockery::mock(UpdateManager::class.'[pleskScript]', [
            app(ReleaseChannel::class),
            app(AuditLogger::class),
            $launcher ?? app(UpdateLauncher::class),
        ]);
        $manager->shouldReceive('pleskScript')->andReturn($this->script);
        $this->app->instance(UpdateManager::class, $manager);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     */
    private function runTool(array $arguments, array $env = []): string
    {
        $process = new Process([PHP_BINARY, ...$arguments], null, $env);
        $process->mustRun();

        return $process->getOutput();
    }
}
