<?php

namespace Tests\Feature\Files;

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    private Client $client;

    private Equipment $equipment;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        $this->equipment = Equipment::factory()->for($this->client)->create();
        $this->technician = $this->userFor($this->client);
    }

    private function pdf(string $name = 'schema reseau.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    private function upload(UploadedFile $file, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->technician)->post("/clients/{$this->client->id}/attachments", [
            'file' => $file,
            'attachable_type' => 'equipment',
            'attachable_id' => $this->equipment->id,
            'description' => 'Plan de câblage',
        ]);
    }

    public function test_upload_is_stored_privately_under_a_generated_name(): void
    {
        $this->upload($this->pdf('../../etc/passwd schéma.pdf'))->assertSessionHasNoErrors();

        $attachment = Attachment::query()->sole();
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertSame('pdf', $attachment->extension);
        $this->assertMatchesRegularExpression('#^attachments/\d+/[0-9a-f-]{36}$#', $attachment->stored_path);
        $this->assertStringNotContainsString('..', $attachment->stored_path);
        $this->assertStringNotContainsString('/', $attachment->original_name);
        Storage::disk('local')->assertExists($attachment->stored_path);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($attachment->stored_path)), $attachment->checksum_sha256);
        $this->assertFalse(is_dir(public_path('storage')) && is_file(public_path('storage/'.$attachment->stored_path)));
    }

    public function test_forbidden_extensions_are_rejected(): void
    {
        foreach (['shell.php', 'outil.exe', 'page.html', 'script.js'] as $name) {
            $this->upload(UploadedFile::fake()->createWithContent($name, '<?php echo "x";'))->assertSessionHasErrors('file');
        }

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_content_must_match_the_extension(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('faux.pdf', '<?php system($_GET["c"]); ?>'))->assertSessionHasErrors('file');
        $this->upload(UploadedFile::fake()->createWithContent('image.png', 'pas une image'))->assertSessionHasErrors('file');

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_size_limit_is_enforced(): void
    {
        config(['techindex.attachments.max_size_kb' => 1]);

        $this->upload(UploadedFile::fake()->createWithContent('gros.txt', str_repeat('a', 3000)))->assertSessionHasErrors('file');
    }

    public function test_attachable_must_belong_to_the_client(): void
    {
        $foreign = Equipment::factory()->create();

        $this->actingAs($this->technician)->post("/clients/{$this->client->id}/attachments", [
            'file' => $this->pdf(), 'attachable_type' => 'equipment', 'attachable_id' => $foreign->id,
        ])->assertStatus(422);
    }

    public function test_download_is_controlled_and_forced(): void
    {
        $this->upload($this->pdf());
        $attachment = Attachment::query()->sole();

        $response = $this->actingAs($this->technician)->get("/clients/{$this->client->id}/attachments/{$attachment->id}");
        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'attachment.downloaded', 'subject_id' => $attachment->id]);

        $inline = $this->actingAs($this->technician)->get("/clients/{$this->client->id}/attachments/{$attachment->id}?inline=1");
        $this->assertSame('application/pdf', $inline->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', $inline->headers->get('Content-Disposition'));
    }

    public function test_download_from_another_client_is_not_found(): void
    {
        $this->upload($this->pdf());
        $attachment = Attachment::query()->sole();
        $otherClient = Client::factory()->create();
        $stranger = $this->userFor($otherClient, UserRole::Manager);

        $this->actingAs($stranger)->get("/clients/{$this->client->id}/attachments/{$attachment->id}")->assertNotFound();
        $this->actingAs($stranger)->get("/clients/{$otherClient->id}/attachments/{$attachment->id}")->assertNotFound();
    }

    public function test_guest_cannot_download(): void
    {
        $this->upload($this->pdf());
        $attachment = Attachment::query()->sole();
        auth()->forgetGuards();

        $this->get("/clients/{$this->client->id}/attachments/{$attachment->id}")->assertRedirect('/login');
    }

    public function test_reader_cannot_upload_or_delete(): void
    {
        $this->upload($this->pdf());
        $attachment = Attachment::query()->sole();
        $reader = $this->userFor($this->client, UserRole::Reader);

        $this->upload($this->pdf(), $reader)->assertForbidden();
        $this->actingAs($reader)->delete("/clients/{$this->client->id}/attachments/{$attachment->id}")->assertForbidden();
        $this->actingAs($reader)->get("/clients/{$this->client->id}/attachments/{$attachment->id}")->assertOk();
    }

    public function test_uploader_can_delete_and_file_is_removed(): void
    {
        $this->upload($this->pdf());
        $attachment = Attachment::query()->sole();

        $this->actingAs($this->technician)->delete("/clients/{$this->client->id}/attachments/{$attachment->id}")->assertRedirect();

        $this->assertSame(0, Attachment::query()->count());
        Storage::disk('local')->assertMissing($attachment->stored_path);
    }

    public function test_svg_is_never_served_inline(): void
    {
        $svg = UploadedFile::fake()->createWithContent('schema.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->upload($svg)->assertSessionHasNoErrors();
        $attachment = Attachment::query()->sole();

        $response = $this->actingAs($this->technician)->get("/clients/{$this->client->id}/attachments/{$attachment->id}?inline=1");
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }
}
