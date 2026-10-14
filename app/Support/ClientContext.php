<?php

namespace App\Support;

use App\Models\Client;
use App\Models\User;

/**
 * Contexte client affiché en permanence sur les pages d'un client :
 * identité, statut, droits de l'utilisateur et compteurs des onglets.
 */
final class ClientContext
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Client $client, User $user): array
    {
        $counts = Client::query()
            ->whereKey($client->getKey())
            ->withCount(['sites', 'contacts', 'equipment', 'services', 'licenses', 'certificates', 'credentials', 'documents'])
            ->first();

        return [
            'id' => $client->id,
            'name' => $client->name,
            'reference' => $client->reference,
            'status' => $client->status->value,
            'logo_url' => $client->hasLogo() ? route('clients.logo', ['client' => $client, 'v' => $client->updated_at?->getTimestamp()]) : null,
            'permissions' => $user->permissionsFor($client),
            'counts' => [
                'sites' => $counts?->sites_count ?? 0,
                'contacts' => $counts?->contacts_count ?? 0,
                'equipment' => $counts?->equipment_count ?? 0,
                'services' => $counts?->services_count ?? 0,
                'licenses' => $counts?->licenses_count ?? 0,
                'certificates' => $counts?->certificates_count ?? 0,
                'credentials' => $counts?->credentials_count ?? 0,
                'documents' => $counts?->documents_count ?? 0,
            ],
        ];
    }
}
