<?php

namespace Tests\Feature\Management;

use App\Enums\ClientGroupRole;
use App\Enums\UserRole;
use App\Models\Client;
use Tests\TestCase;

class ClientGroupTest extends TestCase
{
    public function test_subsidiary_is_created_under_its_parent_and_listed_below_it(): void
    {
        $admin = $this->admin();
        $group = Client::factory()->create(['name' => 'Playify', 'group_role' => ClientGroupRole::Headquarters]);
        Client::factory()->create(['name' => 'Autre client']);

        $this->actingAs($admin)->get("/clients/create?parent={$group->id}")
            ->assertInertia(fn ($page) => $page
                ->where('defaults.parent_id', $group->id)
                ->where('defaults.group_role', 'subsidiary'));

        $this->actingAs($admin)->post('/clients', [
            'name' => 'Playify France', 'reference' => 'PLY-FR', 'parent_id' => $group->id, 'group_role' => 'subsidiary',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $member = Client::query()->where('reference', 'PLY-FR')->sole();
        $this->assertSame($group->id, $member->parent_id);
        $this->assertSame(ClientGroupRole::Subsidiary, $member->group_role);

        // La filiale n'apparaît pas au premier niveau : elle est sous son entreprise mère.
        $this->actingAs($admin)->get('/clients')
            ->assertInertia(fn ($page) => $page
                ->where('tree', true)
                ->where('clients.total', 2)
                ->where('clients.data.0.name', 'Autre client')
                ->where('clients.data.1.name', 'Playify')
                ->where('clients.data.1.children.0.name', 'Playify France')
                ->where('clients.data.1.children.0.group_role_label', 'Filiale'));

        $this->actingAs($admin)->get("/clients/{$group->id}")
            ->assertInertia(fn ($page) => $page
                ->where('group.members.0.name', 'Playify France')
                ->where('group.role_label', 'Siège social'));

        $this->actingAs($admin)->get("/clients/{$member->id}")
            ->assertInertia(fn ($page) => $page
                ->where('context.group.parent.name', 'Playify')
                ->where('group.parent.name', 'Playify'));
    }

    public function test_filtered_list_is_flat_and_names_the_parent(): void
    {
        $group = Client::factory()->create(['name' => 'Groupe Alpha']);
        Client::factory()->create(['name' => 'Alpha Services', 'parent_id' => $group->id, 'group_role' => ClientGroupRole::Branch]);

        $this->actingAs($this->admin())->get('/clients?q=Services')
            ->assertInertia(fn ($page) => $page
                ->where('tree', false)
                ->where('clients.total', 1)
                ->where('clients.data.0.name', 'Alpha Services')
                ->where('clients.data.0.parent.name', 'Groupe Alpha'));
    }

    public function test_group_role_must_match_the_attachment(): void
    {
        $admin = $this->admin();
        $group = Client::factory()->create();

        $this->actingAs($admin)->post('/clients', [
            'name' => 'Filiale', 'reference' => 'FIL-1', 'parent_id' => $group->id, 'group_role' => 'headquarters',
        ])->assertSessionHasErrors('group_role');

        $this->actingAs($admin)->post('/clients', [
            'name' => 'Orpheline', 'reference' => 'FIL-2', 'group_role' => 'subsidiary',
        ])->assertSessionHasErrors('group_role');

        $this->actingAs($admin)->post('/clients', [
            'name' => 'Holding', 'reference' => 'HOL-1', 'group_role' => 'holding',
        ])->assertSessionHasNoErrors();
    }

    public function test_cycles_and_too_deep_groups_are_refused(): void
    {
        $admin = $this->admin();
        $top = Client::factory()->create();
        $middle = Client::factory()->create(['parent_id' => $top->id, 'group_role' => ClientGroupRole::Subsidiary]);
        $bottom = Client::factory()->create(['parent_id' => $middle->id, 'group_role' => ClientGroupRole::Branch]);

        // Une entreprise ne peut pas devenir la mère de son propre groupe.
        $this->actingAs($admin)->put("/clients/{$top->id}", [
            'name' => $top->name, 'reference' => $top->reference, 'parent_id' => $bottom->id, 'group_role' => 'subsidiary',
        ])->assertSessionHasErrors('parent_id');

        $this->actingAs($admin)->put("/clients/{$top->id}", [
            'name' => $top->name, 'reference' => $top->reference, 'parent_id' => $top->id, 'group_role' => 'subsidiary',
        ])->assertSessionHasErrors('parent_id');

        // Quatrième niveau refusé.
        $this->actingAs($admin)->post('/clients', [
            'name' => 'Trop profond', 'reference' => 'DEEP-1', 'parent_id' => $bottom->id, 'group_role' => 'branch',
        ])->assertSessionHasErrors('parent_id');

        // Un groupe de deux niveaux ne peut pas être placé sous une filiale.
        $other = Client::factory()->create();
        $otherMember = Client::factory()->create(['parent_id' => $other->id, 'group_role' => ClientGroupRole::Subsidiary]);
        $this->actingAs($admin)->put("/clients/{$other->id}", [
            'name' => $other->name, 'reference' => $other->reference, 'parent_id' => $middle->id, 'group_role' => 'subsidiary',
        ])->assertSessionHasErrors('parent_id');

        $this->assertNull($other->fresh()->parent_id);
        $this->assertSame($other->id, $otherMember->fresh()->parent_id);
    }

    public function test_unassigned_parent_cannot_be_chosen_nor_revealed(): void
    {
        $hiddenGroup = Client::factory()->create(['name' => 'Groupe Confidentiel']);
        $member = Client::factory()->create(['name' => 'Filiale Visible', 'parent_id' => $hiddenGroup->id, 'group_role' => ClientGroupRole::Subsidiary]);
        $other = Client::factory()->create(['name' => 'Autre Société']);
        $manager = $this->userFor($member, UserRole::Manager);

        $this->actingAs($manager)->post('/clients', [
            'name' => 'Tentative', 'reference' => 'TRY-1', 'parent_id' => $other->id, 'group_role' => 'subsidiary',
        ])->assertSessionHasErrors(['parent_id' => 'L\'entreprise mère choisie est introuvable.']);

        // L'entreprise dont la mère est invisible s'affiche au premier niveau, sans le nom de la mère.
        $response = $this->actingAs($manager)->get('/clients')
            ->assertInertia(fn ($page) => $page->where('clients.total', 1)->where('clients.data.0.name', 'Filiale Visible'));
        $this->assertStringNotContainsString('Groupe Confidentiel', $response->getContent());

        $show = $this->actingAs($manager)->get("/clients/{$member->id}")
            ->assertInertia(fn ($page) => $page
                ->where('group.parent', null)
                ->where('group.parent_hidden', true)
                ->where('context.group.parent', null));
        $this->assertStringNotContainsString('Groupe Confidentiel', $show->getContent());

        $edit = $this->actingAs($manager)->get("/clients/{$member->id}/edit")
            ->assertInertia(fn ($page) => $page->where('group.parent_hidden', true));
        $this->assertStringNotContainsString('Groupe Confidentiel', $edit->getContent());

        // Le rattachement existant, même invisible, peut être conservé lors d'une modification.
        $this->actingAs($manager)->put("/clients/{$member->id}", [
            'name' => 'Filiale Renommée', 'reference' => $member->reference, 'parent_id' => $hiddenGroup->id, 'group_role' => 'branch',
        ])->assertSessionHasNoErrors();

        $this->assertSame($hiddenGroup->id, $member->fresh()->parent_id);
        $this->assertSame(ClientGroupRole::Branch, $member->fresh()->group_role);
    }

    public function test_attachment_changes_are_audited_and_deletion_detaches_members(): void
    {
        $admin = $this->admin();
        $group = Client::factory()->create(['name' => 'Ancien Groupe']);
        $member = Client::factory()->create();

        $this->actingAs($admin)->put("/clients/{$member->id}", [
            'name' => $member->name, 'reference' => $member->reference, 'parent_id' => $group->id, 'group_role' => 'franchise',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audit_logs', ['event' => 'client.updated', 'subject_id' => $member->id]);

        $group->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
        $this->actingAs($admin)->delete("/clients/{$group->id}", ['confirmation' => 'Ancien Groupe'])->assertRedirect();

        $this->assertModelMissing($group);
        $member->refresh();
        $this->assertNull($member->parent_id);
        $this->assertNull($member->group_role);
    }

    public function test_export_includes_group_columns_without_unexportable_parents(): void
    {
        $visibleGroup = Client::factory()->create(['name' => 'Groupe Exportable']);
        $hiddenGroup = Client::factory()->create(['name' => 'Groupe Secret']);
        $a = Client::factory()->create(['name' => 'Filiale A', 'parent_id' => $visibleGroup->id, 'group_role' => ClientGroupRole::Subsidiary]);
        $b = Client::factory()->create(['name' => 'Filiale B', 'parent_id' => $hiddenGroup->id, 'group_role' => ClientGroupRole::Subsidiary]);

        $user = $this->userFor($visibleGroup, UserRole::Manager);
        $this->assign($user, $a);
        $this->assign($user, $b);

        $content = $this->actingAs($user)->get('/exports/clients/csv')->assertOk()->streamedContent();

        $this->assertStringContainsString('Entreprise mère', $content);
        $this->assertStringContainsString('Groupe Exportable', $content);
        $this->assertStringContainsString('Filiale B', $content);
        $this->assertStringNotContainsString('Groupe Secret', $content);
    }
}
