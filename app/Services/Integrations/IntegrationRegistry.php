<?php

namespace App\Services\Integrations;

use App\Services\Integrations\Contracts\IntegrationProvider;
use App\Services\Integrations\Providers\AcronisProvider;
use App\Services\Integrations\Providers\AlsoProvider;
use App\Services\Integrations\Providers\ArrowSphereProvider;
use App\Services\Integrations\Providers\BitdefenderProvider;
use App\Services\Integrations\Providers\CrayonProvider;
use App\Services\Integrations\Providers\EsetProvider;
use App\Services\Integrations\Providers\GoogleWorkspaceProvider;
use App\Services\Integrations\Providers\IngramMicroProvider;
use App\Services\Integrations\Providers\Microsoft365Provider;
use App\Services\Integrations\Providers\PartnerCenterProvider;
use App\Services\Integrations\Providers\Pax8Provider;
use App\Services\Integrations\Providers\SophosProvider;
use App\Services\Integrations\Providers\StreamOneIonProvider;

/**
 * Catalogue des connecteurs disponibles.
 */
class IntegrationRegistry
{
    /** @var list<class-string<IntegrationProvider>> */
    private const PROVIDERS = [
        Microsoft365Provider::class,
        PartnerCenterProvider::class,
        GoogleWorkspaceProvider::class,
        Pax8Provider::class,
        StreamOneIonProvider::class,
        ArrowSphereProvider::class,
        IngramMicroProvider::class,
        AlsoProvider::class,
        CrayonProvider::class,
        SophosProvider::class,
        AcronisProvider::class,
        BitdefenderProvider::class,
        EsetProvider::class,
    ];

    /** @var array<string, IntegrationProvider>|null */
    private ?array $providers = null;

    /**
     * @return array<string, IntegrationProvider>
     */
    public function all(): array
    {
        if ($this->providers === null) {
            $this->providers = [];

            foreach (self::PROVIDERS as $class) {
                $provider = app($class);
                $this->providers[$provider->definition()->key] = $provider;
            }
        }

        return $this->providers;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): IntegrationProvider
    {
        return $this->all()[$key] ?? abort(404);
    }

    public function label(string $key): string
    {
        return $this->has($key) ? $this->get($key)->definition()->label : $key;
    }
}
