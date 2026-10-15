<?php

namespace Tests\Feature\Internet;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\InternetLink;
use App\Models\Isp;
use App\Support\IspCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Catalogue des fournisseurs d'accès (administration) et logos.
 */
class IspCatalogTest extends TestCase
{
    /**
     * Image PNG valide construite sans l'extension GD.
     */
    private function pngImage(string $name, int $width, int $height): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $row = "\0".str_repeat("\x2c\x6e\x9e", $width);

        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress(str_repeat($row, $height)))
            .$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent($name, $png);
    }

    public function test_only_administrators_manage_the_catalog(): void
    {
        $client = Client::factory()->create();
        $manager = $this->userFor($client, UserRole::Manager);

        $this->actingAs($manager)->get('/admin/isps')->assertForbidden();
        $this->actingAs($manager)->post('/admin/isps', ['name' => 'Opérateur X'])->assertForbidden();

        $this->actingAs($this->admin())->get('/admin/isps')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Isps/Index')->where('isps', fn ($isps) => count($isps) > 50));
    }

    public function test_administrator_adds_an_operator_with_an_uploaded_logo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/isps', [
            'name' => 'Fibre Locale', 'country' => 'FR', 'website' => 'https://fibre-locale.example', 'support_phone' => '01 00 00 00 00',
            'is_active' => true, 'logo' => $this->pngImage('logo.png', 64, 64),
        ])->assertSessionHasNoErrors();

        $isp = Isp::query()->where('name', 'Fibre Locale')->sole();
        $this->assertSame('fibre-locale', $isp->slug);
        $this->assertMatchesRegularExpression('#^logos/[0-9a-f-]{36}\.png$#', $isp->logo_path);
        Storage::disk('local')->assertExists($isp->logo_path);

        // Tout utilisateur connecté voit les logos du catalogue ; jamais un script.
        $user = $this->userFor(Client::factory()->create(), UserRole::Reader);
        $this->actingAs($user)->get("/isps/{$isp->id}/logo")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', (string) $this->actingAs($user)->get("/isps/{$isp->id}/logo")->headers->get('Content-Security-Policy'));
    }

    public function test_guests_cannot_load_logos(): void
    {
        $isp = Isp::query()->where('slug', 'orange')->sole();

        $this->get("/isps/{$isp->id}/logo")->assertRedirect('/login');
    }

    public function test_svg_and_duplicate_names_are_refused(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($this->admin())->post('/admin/isps', ['name' => 'Orange', 'logo' => $svg])
            ->assertSessionHasErrors(['name', 'logo']);
    }

    public function test_bundled_logo_is_served_as_an_inert_image(): void
    {
        $isp = Isp::query()->where('slug', 'orange')->sole();

        $response = $this->actingAs($this->admin())->get("/isps/{$isp->id}/logo")->assertOk();

        $this->assertContains($response->headers->get('Content-Type'), ['image/svg+xml', 'image/png']);
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_an_operator_in_use_cannot_be_deleted_but_can_be_hidden(): void
    {
        $admin = $this->admin();
        $isp = Isp::query()->where('slug', 'free')->sole();
        $link = new InternetLink(['name' => 'Freebox', 'role' => 'primary', 'status' => 'active', 'isp_id' => $isp->id]);
        $link->client()->associate(Client::factory()->create());
        $link->save();

        $this->actingAs($admin)->delete("/admin/isps/{$isp->id}")->assertSessionHas('error');
        $this->assertNotNull($isp->fresh());

        $this->actingAs($admin)->put("/admin/isps/{$isp->id}", ['name' => 'Free', 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($isp->fresh()->is_active);

        $unused = Isp::query()->where('slug', 'salt')->sole();
        $this->actingAs($admin)->delete("/admin/isps/{$unused->id}")->assertRedirect();
        $this->assertNull($unused->fresh());
    }

    public function test_catalog_sync_is_idempotent_and_keeps_administrator_changes(): void
    {
        $isp = Isp::query()->where('slug', 'sfr')->sole();
        $isp->update(['support_phone' => '09 99 99 99 99', 'name' => 'SFR (contrat cadre)']);
        $count = Isp::query()->count();

        $result = IspCatalog::sync();

        $this->assertSame(0, $result['created']);
        $this->assertSame($count, Isp::query()->count());
        $this->assertSame('09 99 99 99 99', $isp->fresh()->support_phone);
        $this->assertSame('SFR (contrat cadre)', $isp->fresh()->name);
    }

    public function test_free_text_operator_names_are_matched_to_the_catalog(): void
    {
        $this->assertSame('orange-business', IspCatalog::match('Orange Business Services')?->slug);
        $this->assertSame('orange', IspCatalog::match('orange')?->slug);
        $this->assertSame('bouygues-telecom', IspCatalog::match('Bouygues Télécom')?->slug);
        $this->assertNull(IspCatalog::match('Opérateur Fictif Télécom'));
        $this->assertNull(IspCatalog::match(''));
    }

    public function test_bundled_logo_files_are_inert_images(): void
    {
        $directory = database_path(Isp::BUNDLED_LOGO_DIRECTORY);
        $referenced = array_filter(array_column(IspCatalog::entries(), 7));

        foreach (array_unique($referenced) as $file) {
            $path = $directory.'/'.$file;
            $this->assertFileExists($path, "Logo manquant : {$file}");
            $content = (string) file_get_contents($path);

            match (pathinfo($file, PATHINFO_EXTENSION)) {
                'png' => $this->assertStringStartsWith("\x89PNG", $content, $file),
                'jpg' => $this->assertStringStartsWith("\xFF\xD8\xFF", $content, $file),
                'svg' => $this->assertInertSvg($content, $file),
            };
        }
    }

    private function assertInertSvg(string $content, string $file): void
    {
        $this->assertStringContainsString('<svg', $content, $file);

        foreach (['/<script/i', '/\son[a-z]+\s*=/i', '/javascript:/i', '/<foreignObject/i', '/(href|src)\s*=\s*["\']\s*(https?:|data:text|\/\/)/i'] as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $content, "Contenu actif dans {$file}");
        }
    }
}
