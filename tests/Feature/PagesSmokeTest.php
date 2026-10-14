<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\License;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Tests\TestCase;

/**
 * Parcourt toutes les pages de l'application sur les données de
 * démonstration : chaque page doit s'afficher sans erreur et désigner un
 * composant Vue existant.
 */
class PagesSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
    }

    /**
     * @return array<string, string>
     */
    private function pages(): array
    {
        $client = Client::query()->where('reference', 'DEMO-001')->sole();
        $site = Site::query()->where('client_id', $client->id)->firstOrFail();
        $equipment = Equipment::query()->where('client_id', $client->id)->where('name', 'HYP-01')->firstOrFail();
        $service = Service::query()->where('client_id', $client->id)->where('category', 'backup')->firstOrFail();
        $license = License::query()->where('client_id', $client->id)->firstOrFail();
        $certificate = Certificate::query()->where('client_id', $client->id)->firstOrFail();
        $document = Document::query()->where('client_id', $client->id)->firstOrFail();
        $version = $document->versions()->firstOrFail();
        $user = User::query()->where('email', 'technicien.demo@techindex.test')->sole();
        $c = "/clients/{$client->id}";

        return [
            '/dashboard' => 'Dashboard',
            '/clients' => 'Clients/Index',
            '/clients/create' => 'Clients/Form',
            $c => 'Clients/Show',
            "{$c}/edit" => 'Clients/Form',
            "{$c}/sites" => 'Sites/Index',
            "{$c}/sites/create" => 'Sites/Form',
            "{$c}/sites/{$site->id}" => 'Sites/Show',
            "{$c}/sites/{$site->id}/edit" => 'Sites/Form',
            "{$c}/contacts" => 'Contacts/Index',
            "{$c}/equipment" => 'Equipment/Index',
            "{$c}/equipment?q=10.10&status=all&sort=warranty_expires_at&direction=desc" => 'Equipment/Index',
            "{$c}/equipment/create" => 'Equipment/Form',
            "{$c}/equipment/{$equipment->id}" => 'Equipment/Show',
            "{$c}/equipment/{$equipment->id}/edit" => 'Equipment/Form',
            '/equipment' => 'Equipment/Index',
            '/equipment?alert=warranty' => 'Equipment/Index',
            "{$c}/network" => 'Network/Index',
            "{$c}/services" => 'Services/Index',
            "{$c}/services/create?category=vpn" => 'Services/Form',
            "{$c}/services/{$service->id}" => 'Services/Show',
            "{$c}/services/{$service->id}/edit" => 'Services/Form',
            "{$c}/licenses" => 'Licenses/Index',
            "{$c}/licenses/create" => 'Licenses/Form',
            "{$c}/licenses/{$license->id}" => 'Licenses/Show',
            "{$c}/licenses/{$license->id}/edit" => 'Licenses/Form',
            "{$c}/certificates" => 'Certificates/Index',
            "{$c}/certificates/create" => 'Certificates/Form',
            "{$c}/certificates/{$certificate->id}" => 'Certificates/Show',
            "{$c}/certificates/{$certificate->id}/edit" => 'Certificates/Form',
            "{$c}/credentials" => 'Credentials/Index',
            "{$c}/documents" => 'Documents/Index',
            "{$c}/documents?review=1" => 'Documents/Index',
            "{$c}/documents/create" => 'Documents/Form',
            "{$c}/documents/{$document->id}" => 'Documents/Show',
            "{$c}/documents/{$document->id}/edit" => 'Documents/Form',
            "{$c}/documents/{$document->id}/versions/{$version->id}" => 'Documents/Version',
            "{$c}/history" => 'History/Index',
            '/search?q=SRV' => 'Search/Index',
            '/search?q=SRV&type=equipment' => 'Search/Index',
            '/imports' => 'Imports/Index',
            '/imports/create' => 'Imports/Create',
            '/audit' => 'Audit/Index',
            '/audit?category=secrets' => 'Audit/Index',
            '/profile' => 'Profile/Show',
            // Gestionnaire de mots de passe : verrouillé tant qu'aucune clé d'accès n'est vérifiée.
            '/vault' => 'Vault/Locked',
            "/vault/clients/{$client->id}" => 'Vault/Locked',
            '/files' => 'Files/Index',
            '/files?q=plan' => 'Files/Index',
            "/files/clients/{$client->id}" => 'Files/Index',
            '/admin/users' => 'Admin/Users/Index',
            '/admin/users/create' => 'Admin/Users/Form',
            "/admin/users/{$user->id}/edit" => 'Admin/Users/Form',
            '/admin/equipment-types' => 'Admin/EquipmentTypes/Index',
            '/admin/custom-fields' => 'Admin/CustomFields/Index',
            '/admin/document-categories' => 'Admin/DocumentCategories/Index',
            '/admin/tags' => 'Admin/Tags/Index',
            '/admin/updates' => 'Admin/Updates/Index',
        ];
    }

    public function test_every_page_renders_for_an_administrator(): void
    {
        $admin = $this->admin();

        foreach ($this->pages() as $url => $component) {
            $this->actingAs($admin)->get($url)
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component($component));
        }
    }

    public function test_client_pages_render_for_a_reader(): void
    {
        $reader = User::query()->where('email', 'lecteur.demo@techindex.test')->sole();
        $reader->forceFill(['must_change_password' => false])->save();

        foreach ($this->pages() as $url => $component) {
            if (str_contains($url, '/create') || str_contains($url, '/edit') || str_starts_with($url, '/admin') || str_starts_with($url, '/imports') || $url === '/audit' || str_starts_with($url, '/audit?')) {
                continue;
            }

            $this->actingAs($reader)->get($url)->assertOk();
        }
    }

    public function test_demo_data_is_complete_and_fictitious(): void
    {
        $this->assertSame(3, Client::query()->where('reference', 'like', 'DEMO-%')->count());
        $this->assertTrue(User::query()->where('email', 'like', '%.demo@techindex.test')->get()->every(fn (User $user) => $user->must_change_password));
        $this->assertSame(0, User::query()->where('email', 'like', '%.demo@techindex.test')->whereIn('role', [UserRole::Admin->value])->count());

        // Relancer le chargement ne duplique rien.
        $this->artisan('techindex:demo')->assertSuccessful();
        $this->assertSame(3, Client::query()->where('reference', 'like', 'DEMO-%')->count());
    }
}
