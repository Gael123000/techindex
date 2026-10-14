<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\LicenseStatus;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\License;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_indicators_come_from_real_data_and_respect_access(): void
    {
        $mine = Client::factory()->create();
        $other = Client::factory()->create();
        $user = $this->userFor($mine);

        Equipment::factory()->for($mine)->create(['name' => 'GARANTIE-PROCHE', 'warranty_expires_at' => today()->addDays(10)]);
        Equipment::factory()->for($mine)->create(['name' => 'GARANTIE-LOINTAINE', 'warranty_expires_at' => today()->addYears(2)]);
        Equipment::factory()->for($mine)->create(['name' => 'FIN-DE-VIE', 'end_of_life_at' => today()->addDays(20)]);
        Equipment::factory()->for($other)->create(['name' => 'AUTRE-CLIENT', 'warranty_expires_at' => today()->addDays(5)]);

        $license = new License(['name' => 'Antivirus', 'kind' => 'subscription', 'expires_at' => today()->addDays(15), 'status' => LicenseStatus::Active]);
        $license->client()->associate($mine);
        $license->save();

        $certificate = new Certificate(['name' => 'TLS intranet', 'type' => 'public', 'expires_at' => today()->subDay(), 'status' => CertificateStatus::Active]);
        $certificate->client()->associate($mine);
        $certificate->save();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('stats.clients', 1)
                ->where('stats.equipment', 3)
                ->where('alerts.warranties.total', 1)
                ->where('alerts.warranties.items.0.name', 'GARANTIE-PROCHE')
                ->where('alerts.end_of_life.total', 1)
                ->where('alerts.licenses.total', 1)
                ->where('alerts.certificates.total', 1));

        $this->assertStringNotContainsString('AUTRE-CLIENT', $this->actingAs($user)->get('/dashboard')->getContent());
    }

    public function test_recently_modified_items_are_listed(): void
    {
        $client = Client::factory()->create();
        $user = $this->userFor($client);

        $this->actingAs($user)->post("/clients/{$client->id}/sites", ['name' => 'Agence Nord'])->assertRedirect();

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('recentChanges.0.subject_label', 'Agence Nord')->where('recentChanges.0.event', 'site.created'));
    }
}
