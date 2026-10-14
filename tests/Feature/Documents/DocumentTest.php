<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\User;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    private Client $client;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        $this->technician = $this->userFor($this->client);
    }

    private function create(array $overrides = []): Document
    {
        $this->actingAs($this->technician)->post("/clients/{$this->client->id}/documents", [
            'title' => 'Procédure de restauration',
            'content' => '<h2>Étapes</h2><p>Restaurer la VM.</p>',
            'status' => DocumentStatus::Published->value,
            ...$overrides,
        ])->assertSessionHasNoErrors();

        return Document::query()->latest('id')->firstOrFail();
    }

    public function test_dangerous_html_is_removed(): void
    {
        $document = $this->create(['content' => implode('', [
            '<h1>Titre interdit</h1>',
            '<p onclick="alert(1)" style="color:red">Texte <strong>gras</strong></p>',
            '<script>alert("xss")</script>',
            '<a href="javascript:alert(1)">piège</a>',
            '<a href="https://exemple.test/doc">lien</a>',
            '<img src="x" onerror="alert(1)">',
            '<iframe src="https://evil.test"></iframe>',
            '<table><tr><td>cellule</td></tr></table>',
        ])]);

        $html = $document->content_html;

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringContainsString('<strong>gras</strong>', $html);
        $this->assertStringContainsString('href="https://exemple.test/doc"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
        $this->assertStringContainsString('<td>cellule</td>', $html);
        $this->assertStringContainsString('Texte gras', $document->content_text);
    }

    public function test_each_content_change_creates_a_version(): void
    {
        $document = $this->create();
        $url = "/clients/{$this->client->id}/documents/{$document->id}";

        $this->actingAs($this->technician)->put($url, [
            'title' => 'Procédure de restauration', 'content' => '<p>Version 2</p>', 'status' => 'published', 'change_summary' => 'Mise à jour',
        ])->assertSessionHasNoErrors();

        // Modification sans changement de contenu : pas de nouvelle version.
        $this->actingAs($this->technician)->put($url, [
            'title' => 'Procédure de restauration', 'content' => '<p>Version 2</p>', 'status' => 'draft',
        ])->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame(2, $document->current_version);
        $this->assertSame([2, 1], $document->versions()->pluck('version')->all());
        $this->assertSame('Mise à jour', $document->versions()->first()->change_summary);

        $version = $document->versions()->where('version', 1)->first();
        $this->actingAs($this->technician)->get("{$url}/versions/{$version->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Documents/Version')->where('version.version', 1));
    }

    public function test_links_to_equipment_must_belong_to_the_client(): void
    {
        $foreign = Equipment::factory()->create();

        $this->actingAs($this->technician)->post("/clients/{$this->client->id}/documents", [
            'title' => 'Fiche', 'status' => 'published', 'equipment_ids' => [$foreign->id],
        ])->assertSessionHasErrors('equipment_ids.0');
    }

    public function test_verification_updates_review_state(): void
    {
        $document = $this->create(['review_interval_days' => 30]);

        $this->assertTrue($document->needs_review);
        $this->assertSame(1, Document::query()->needingReview()->count());

        $this->actingAs($this->technician)->post("/clients/{$this->client->id}/documents/{$document->id}/verify")->assertRedirect();

        $document->refresh();
        $this->assertFalse($document->needs_review);
        $this->assertSame(0, Document::query()->needingReview()->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'document.verified', 'subject_id' => $document->id]);

        $this->travel(31)->days();
        $this->assertSame(1, Document::query()->needingReview()->count());
    }

    public function test_reader_cannot_edit_documents(): void
    {
        $document = $this->create();
        $reader = $this->userFor($this->client, UserRole::Reader);

        $this->actingAs($reader)->get("/clients/{$this->client->id}/documents/{$document->id}")->assertOk();
        $this->actingAs($reader)->put("/clients/{$this->client->id}/documents/{$document->id}", ['title' => 'X', 'status' => 'published'])->assertForbidden();
        $this->actingAs($reader)->post("/clients/{$this->client->id}/documents/{$document->id}/verify")->assertForbidden();
    }
}
