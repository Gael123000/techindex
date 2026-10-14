<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Tests\TestCase;

class HttpSecurityTest extends TestCase
{
    public function test_security_headers_are_sent(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'same-origin');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]+'/", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);

        // Le script de routes (Ziggy) porte le nonce de la requête.
        $this->assertMatchesRegularExpression('/<script[^>]+nonce="[^"]+"/', $response->getContent());
    }

    public function test_errors_do_not_leak_technical_details(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/clients/999999');

        $response->assertNotFound()->assertInertia(fn ($page) => $page->component('Errors/Error')->where('status', 404));
        $this->assertStringNotContainsString('vendor/laravel', $response->getContent());
        $this->assertStringNotContainsString('ModelNotFoundException', $response->getContent());
    }

    public function test_json_errors_do_not_leak_technical_details(): void
    {
        $client = Client::factory()->create();
        $user = $this->userFor($client);

        $this->actingAs($user)->withRecentConfirmation()
            ->postJson("/clients/{$client->id}/credentials/999999/reveal", ['purpose' => 'reveal'])
            ->assertNotFound()
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('exception');
    }

    public function test_state_changing_requests_require_a_csrf_token_outside_tests(): void
    {
        // Le middleware ignore la vérification pendant les tests unitaires :
        // on vérifie ici qu'il est bien présent dans la pile "web".
        $middleware = app(Kernel::class)->getMiddlewareGroups()['web'];

        $this->assertTrue(collect($middleware)->contains(fn (string $class): bool => str_contains($class, 'PreventRequestForgery') || str_contains($class, 'ValidateCsrfToken')));
    }

    public function test_mass_assignment_cannot_change_protected_attributes(): void
    {
        $client = Client::factory()->create();
        $manager = $this->userFor($client, UserRole::Manager);

        $this->actingAs($manager)->put("/clients/{$client->id}", [
            'name' => 'Nom', 'reference' => $client->reference,
            'status' => 'archived', 'created_by' => 999, 'logo_path' => '../../.env',
        ])->assertRedirect();

        $client->refresh();
        $this->assertSame('active', $client->status->value);
        $this->assertNull($client->logo_path);
    }

    public function test_profile_page_is_never_cached(): void
    {
        $this->actingAs(User::factory()->create())->get('/profile')
            ->assertOk()
            ->assertHeader('Pragma', 'no-cache');
    }
}
