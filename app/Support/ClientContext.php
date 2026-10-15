<?php

namespace App\Support;

use App\Enums\ClientPermission;
use App\Models\Client;
use App\Models\CustomRecord;
use App\Models\RecordType;
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
            ->withCount(['sites', 'contacts', 'equipment', 'internetLinks', 'services', 'licenses', 'certificates', 'credentials', 'documents'])
            ->first();

        // Entreprise mère affichée seulement si elle est attribuée à l'utilisateur.
        $parent = $client->parent_id !== null && $user->hasClientPermission($client->parent_id, ClientPermission::View)
            ? Client::query()->whereKey($client->parent_id)->first(['id', 'name'])
            : null;

        return [
            'id' => $client->id,
            'name' => $client->name,
            'reference' => $client->reference,
            'status' => $client->status->value,
            'group' => [
                'role_label' => $client->group_role?->shortLabel(),
                'parent' => $parent?->only(['id', 'name']),
            ],
            'logo_url' => $client->hasLogo() ? route('clients.logo', ['client' => $client, 'v' => $client->updated_at?->getTimestamp()]) : null,
            'permissions' => $user->permissionsFor($client),
            'counts' => [
                'sites' => $counts?->sites_count ?? 0,
                'contacts' => $counts?->contacts_count ?? 0,
                'equipment' => $counts?->equipment_count ?? 0,
                'internet_links' => $counts?->internet_links_count ?? 0,
                'services' => $counts?->services_count ?? 0,
                'licenses' => $counts?->licenses_count ?? 0,
                'certificates' => $counts?->certificates_count ?? 0,
                'credentials' => $counts?->credentials_count ?? 0,
                'documents' => $counts?->documents_count ?? 0,
            ],
            'custom_tabs' => self::customTabs($client),
        ];
    }

    /**
     * Onglets des fiches créées avec le constructeur, avec leur nombre
     * d'enregistrements actifs pour ce client.
     *
     * @return list<array{key: string, label: string, icon: string, after: string, count: int}>
     */
    private static function customTabs(Client $client): array
    {
        $types = RecordType::query()->custom()->where('is_active', true)->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'key', 'plural_name', 'icon', 'tab_after']);

        if ($types->isEmpty()) {
            return [];
        }

        $counts = CustomRecord::query()->where('client_id', $client->id)->where('status', 'active')
            ->selectRaw('record_type_id, COUNT(*) as total')->groupBy('record_type_id')->pluck('total', 'record_type_id');

        return $types->map(fn (RecordType $type): array => [
            'key' => $type->key,
            'label' => $type->plural_name,
            'icon' => $type->icon,
            'after' => $type->tab_after ?? 'documents',
            'count' => (int) ($counts[$type->id] ?? 0),
        ])->values()->all();
    }
}
