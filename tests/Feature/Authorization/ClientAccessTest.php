<?php

namespace Tests\Feature\Authorization;

use App\Enums\ClientPermission;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\Site;
use App\Models\User;
use Tests\TestCase;

class ClientAccessTest extends TestCase
{
    public function test_client_list_only_contains_assigned_clients(): void
    {
        $mine = Client::factory()->create(['name' => 'Client Attribué']);
        $other = Client::factory()->create(['name' => 'Client Étranger']);
        $user = $this->userFor($mine);

        $this->actingAs($user)->get('/clients')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clients/Index')
                ->where('clients.total', 1)
                ->where('clients.data.0.name', 'Client Attribué'));

        $this->assertStringNotContainsString($other->name, $this->actingAs($user)->get('/clients')->getContent());
    }

    public function test_unassigned_client_is_not_found_even_by_direct_url(): void
    {
        $mine = Client::factory()->create();
        $other = Client::factory()->create();
        $user = $this->userFor($mine);

        foreach ([
            "/clients/{$other->id}",
            "/clients/{$other->id}/edit",
            "/clients/{$other->id}/equipment",
            "/clients/{$other->id}/credentials",
            "/clients/{$other->id}/documents",
            "/clients/{$other->id}/history",
            "/clients/{$other->id}/network",
            "/clients/{$other->id}/logo",
            "/clients/{$other->id}/export/equipment/csv",
        ] as $url) {
            $this->actingAs($user)->get($url)->assertNotFound();
        }

        $this->actingAs($user)->put("/clients/{$other->id}", ['name' => 'Piraté', 'reference' => 'X'])->assertNotFound();
        $this->actingAs($user)->post("/clients/{$other->id}/archive")->assertNotFound();
        $this->assertSame('active', $other->fresh()->status->value);
    }

    public function test_resource_of_another_client_cannot_be_reached_through_an_authorized_client_url(): void
    {
        $mine = Client::factory()->create();
        $other = Client::factory()->create();
        $foreignEquipment = Equipment::factory()->for($other)->create();
        $user = $this->userFor($mine);

        // Liaison restreinte : l'équipement 42 n'appartient pas au client de l'URL.
        $this->actingAs($user)->get("/clients/{$mine->id}/equipment/{$foreignEquipment->id}")->assertNotFound();
        $this->actingAs($user)->put("/clients/{$mine->id}/equipment/{$foreignEquipment->id}", ['name' => 'X'])->assertNotFound();
        $this->actingAs($user)->get("/clients/{$other->id}/equipment/{$foreignEquipment->id}")->assertNotFound();
    }

    public function test_admin_sees_all_clients(): void
    {
        Client::factory()->count(3)->create();

        $this->actingAs($this->admin())->get('/clients')
            ->assertInertia(fn ($page) => $page->where('clients.total', 3));
    }

    public function test_reader_can_view_but_not_modify(): void
    {
        $client = Client::factory()->create();
        $equipment = Equipment::factory()->for($client)->create();
        $reader = $this->userFor($client, UserRole::Reader);

        $this->actingAs($reader)->get("/clients/{$client->id}/equipment/{$equipment->id}")->assertOk();
        $this->actingAs($reader)->get("/clients/{$client->id}/equipment/{$equipment->id}/edit")->assertForbidden();
        $this->actingAs($reader)->get("/clients/{$client->id}/equipment/create")->assertForbidden();
        $this->actingAs($reader)->post("/clients/{$client->id}/sites", ['name' => 'Nouveau site'])->assertForbidden();
        $this->actingAs($reader)->put("/clients/{$client->id}", ['name' => 'Renommé', 'reference' => $client->reference])->assertForbidden();

        $this->assertDatabaseMissing('sites', ['name' => 'Nouveau site']);
    }

    public function test_edit_permission_is_separate_from_view(): void
    {
        $client = Client::factory()->create();
        $technician = $this->userFor($client, UserRole::Technician, ['can_edit' => false]);

        $this->actingAs($technician)->post("/clients/{$client->id}/sites", ['name' => 'Site interdit'])->assertForbidden();

        $technician->assignments()->update(['can_edit' => true]);
        $technician->flushAccessCache();

        $this->actingAs($technician)->post("/clients/{$client->id}/sites", ['name' => 'Site autorisé'])->assertRedirect();
        $this->assertDatabaseHas('sites', ['name' => 'Site autorisé', 'client_id' => $client->id]);
    }

    public function test_technician_cannot_create_clients_but_manager_can(): void
    {
        $technician = User::factory()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($technician)->get('/clients/create')->assertForbidden();
        $this->actingAs($technician)->post('/clients', ['name' => 'Interdit', 'reference' => 'INT-1'])->assertForbidden();

        $this->actingAs($manager)->post('/clients', ['name' => 'Nouveau client', 'reference' => 'NEW-1'])->assertRedirect();

        $client = Client::query()->where('reference', 'NEW-1')->sole();
        // Le responsable obtient automatiquement la gestion du client créé.
        $this->assertTrue($manager->fresh()->hasClientPermission($client, ClientPermission::Manage));
        $this->assertDatabaseHas('audit_logs', ['event' => 'access.granted', 'client_id' => $client->id]);
    }

    public function test_archived_client_is_read_only(): void
    {
        $client = Client::factory()->archived()->create();
        $site = Site::factory()->for($client)->create();
        $manager = $this->userFor($client, UserRole::Manager);

        $this->actingAs($manager)->get("/clients/{$client->id}")->assertOk();
        $this->actingAs($manager)->put("/clients/{$client->id}/sites/{$site->id}", ['name' => 'Modifié'])->assertForbidden();
        $this->actingAs($manager)->post("/clients/{$client->id}/restore")->assertRedirect();
        $this->assertSame('active', $client->fresh()->status->value);
    }

    public function test_hard_delete_requires_admin_archived_client_and_confirmation(): void
    {
        $client = Client::factory()->create(['name' => 'À supprimer']);
        $manager = $this->userFor($client, UserRole::Manager);
        $admin = $this->admin();

        $this->actingAs($manager)->delete("/clients/{$client->id}", ['confirmation' => 'À supprimer'])->assertForbidden();
        $this->actingAs($admin)->delete("/clients/{$client->id}", ['confirmation' => 'À supprimer'])->assertForbidden();

        $client->forceFill(['status' => 'archived'])->save();

        $this->actingAs($admin)->delete("/clients/{$client->id}", ['confirmation' => 'mauvais nom'])->assertSessionHasErrors('confirmation');
        $this->assertDatabaseHas('clients', ['id' => $client->id]);

        $this->actingAs($admin)->delete("/clients/{$client->id}", ['confirmation' => 'À supprimer'])->assertRedirect();
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'client.deleted', 'subject_id' => $client->id]);
    }

    public function test_administration_is_reserved_to_admins(): void
    {
        $manager = User::factory()->manager()->create();

        foreach (['/admin/users', '/admin/users/create', '/admin/equipment-types', '/admin/custom-fields', '/admin/document-categories', '/admin/tags'] as $url) {
            $this->actingAs($manager)->get($url)->assertForbidden();
        }

        $this->actingAs($manager)->post('/admin/users', ['name' => 'X', 'email' => 'x@example.test', 'role' => 'admin'])->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/users')->assertOk();
    }

    public function test_audit_log_is_restricted(): void
    {
        $client = Client::factory()->create();
        $technician = $this->userFor($client);
        $manager = $this->userFor($client, UserRole::Manager);

        $this->actingAs($technician)->get('/audit')->assertForbidden();
        $this->actingAs($manager)->get('/audit')->assertOk();
    }

    public function test_access_changes_are_audited_and_applied(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->put("/admin/users/{$user->id}/access", [
            'access' => [['client_id' => $client->id, 'enabled' => true, 'can_edit' => true, 'can_export' => false, 'can_view_secrets' => false, 'can_manage' => false]],
        ])->assertRedirect();

        $this->assertTrue($user->fresh()->hasClientPermission($client, ClientPermission::Edit));
        $this->assertFalse($user->fresh()->hasClientPermission($client, ClientPermission::Secrets));
        $this->assertDatabaseHas('audit_logs', ['event' => 'access.granted', 'client_id' => $client->id, 'subject_id' => $user->id]);

        $this->actingAs($admin)->put("/admin/users/{$user->id}/access", [
            'access' => [['client_id' => $client->id, 'enabled' => false]],
        ]);

        $this->assertSame([], $user->fresh()->clientIdsWith());
        $this->assertDatabaseHas('audit_logs', ['event' => 'access.revoked', 'client_id' => $client->id]);
    }

    public function test_role_change_and_deactivation_are_audited_and_sessions_revoked(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)->put("/admin/users/{$user->id}", ['name' => $user->name, 'email' => $user->email, 'role' => 'manager'])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.role_changed', 'subject_id' => $user->id]);

        $this->actingAs($admin)->post("/admin/users/{$user->id}/deactivate")->assertRedirect();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.deactivated', 'subject_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.sessions_revoked', 'subject_id' => $user->id]);
    }

    public function test_admin_cannot_deactivate_or_demote_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/users/{$admin->id}/deactivate")->assertForbidden();
        $this->actingAs($admin)->put("/admin/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'role' => 'reader'])->assertForbidden();
        $this->assertTrue($admin->fresh()->isAdmin());
    }
}
