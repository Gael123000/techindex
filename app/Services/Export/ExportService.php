<?php

namespace App\Services\Export;

use App\Models\Certificate;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\License;
use App\Models\Service;
use App\Models\Site;
use App\Models\Subnet;
use Generator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Jeux de données exportables. Aucun ne contient de secret : la table des
 * accès n'est jamais exportée, et seules des colonnes explicitement listées
 * sont écrites.
 */
class ExportService
{
    public const CLIENT_DATASETS = [
        'equipment' => 'Équipements',
        'sites' => 'Sites',
        'contacts' => 'Contacts',
        'services' => 'Services',
        'licenses' => 'Licences et abonnements',
        'certificates' => 'Certificats',
        'subnets' => 'Plan d\'adressage',
        'documents' => 'Index de la documentation',
    ];

    /**
     * @return list<string>
     */
    public function headings(string $dataset): array
    {
        return match ($dataset) {
            'clients' => ['Nom', 'Référence', 'Statut', 'Adresse', 'Complément d\'adresse', 'Code postal', 'Ville', 'Pays', 'Téléphone', 'E-mail', 'Site web', 'Responsable interne', 'Étiquettes', 'Description', 'Notes', 'Mis à jour le'],
            'equipment' => ['Client', 'Nom', 'Type', 'Nom d\'hôte', 'Fabricant', 'Modèle', 'Numéro de série', 'Référence d\'inventaire', 'Site', 'Emplacement', 'Adresses IP', 'Adresses MAC', 'Système d\'exploitation', 'Version du système', 'Firmware', 'Rôle', 'Statut', 'Date d\'achat', 'Date de mise en service', 'Fin de garantie', 'Fin de vie', 'Fournisseur', 'URL d\'administration', 'Étiquettes', 'Description', 'Notes'],
            'sites' => ['Nom', 'Code', 'Adresse', 'Complément d\'adresse', 'Code postal', 'Ville', 'Pays', 'Téléphone', 'Consignes d\'accès', 'Statut', 'Notes'],
            'contacts' => ['Nom', 'Prénom', 'Fonction', 'Site', 'E-mail', 'Téléphone', 'Mobile', 'Contact technique', 'Contact principal', 'Notes'],
            'services' => ['Nom', 'Catégorie', 'Fournisseur', 'Référence', 'URL', 'Site', 'Statut', 'Début', 'Échéance', 'Équipements liés', 'Description', 'Notes'],
            'licenses' => ['Nom', 'Nature', 'Éditeur', 'Fournisseur', 'Référence du contrat', 'Quantité', 'Périodicité', 'Coût', 'Date d\'achat', 'Début', 'Expiration', 'Renouvellement automatique', 'Préavis (jours)', 'Informations de renouvellement', 'Statut', 'Équipements liés', 'Notes'],
            'certificates' => ['Nom', 'Nom commun (CN)', 'Noms alternatifs', 'Émetteur', 'Type', 'Numéro de série', 'Empreinte SHA-256', 'Émis le', 'Expire le', 'Renouvellement automatique', 'Méthode de renouvellement', 'Statut', 'Service', 'Équipements', 'Notes'],
            'subnets' => ['Nom', 'Réseau (CIDR)', 'VLAN', 'Site', 'Passerelle', 'Serveurs DNS', 'DHCP', 'Plage DHCP', 'Adresses utilisées', 'Description'],
            'documents' => ['Titre', 'Catégorie', 'Statut', 'Responsable', 'Version', 'Dernière vérification', 'Mis à jour le'],
        };
    }

    /**
     * @param  list<int>|null  $clientIds  null = tous les clients (administrateur)
     * @return Generator<int, array<int, mixed>>
     */
    public function rows(string $dataset, ?array $clientIds, ?Client $client = null): Generator
    {
        $scope = function (Builder $query) use ($clientIds, $client): Builder {
            if ($client !== null) {
                return $query->where('client_id', $client->id);
            }

            return $clientIds === null ? $query : $query->whereIn('client_id', $clientIds);
        };

        switch ($dataset) {
            case 'clients':
                $query = Client::query()->with(['accountManager:id,name', 'tags:id,name'])->orderBy('name');

                if ($clientIds !== null) {
                    $query->whereIn('id', $clientIds);
                }

                foreach ($query->lazyById(200) as $item) {
                    yield [
                        $item->name, $item->reference, $item->status->label(), $item->address_line1, $item->address_line2,
                        $item->postal_code, $item->city, $item->country, $item->phone, $item->email, $item->website,
                        $item->accountManager?->name, $item->tags->pluck('name')->implode(', '), $item->description,
                        $item->notes, $item->updated_at?->timezone(config('app.display_timezone'))->format('Y-m-d H:i'),
                    ];
                }
                break;

            case 'equipment':
                $query = $scope(Equipment::query())->with(['client:id,name', 'type:id,name', 'site:id,name', 'interfaces', 'tags:id,name']);

                foreach ($query->lazyById(200) as $item) {
                    yield [
                        $item->client->name, $item->name, $item->type?->name, $item->hostname, $item->manufacturer, $item->model,
                        $item->serial_number, $item->inventory_number, $item->site?->name, $item->location,
                        $item->interfaces->pluck('ip_address')->filter()->implode(', '),
                        $item->interfaces->pluck('mac_address')->filter()->implode(', '),
                        $item->os_name, $item->os_version, $item->firmware_version, $item->role, $item->status->label(),
                        $item->purchase_date?->format('Y-m-d'), $item->commissioning_date?->format('Y-m-d'),
                        $item->warranty_expires_at?->format('Y-m-d'), $item->end_of_life_at?->format('Y-m-d'),
                        $item->supplier, $item->admin_url, $item->tags->pluck('name')->implode(', '), $item->description, $item->notes,
                    ];
                }
                break;

            case 'sites':
                foreach ($scope(Site::query())->lazyById(200) as $item) {
                    yield [
                        $item->name, $item->code, $item->address_line1, $item->address_line2, $item->postal_code, $item->city,
                        $item->country, $item->phone, $item->access_instructions, $item->status->label(), $item->notes,
                    ];
                }
                break;

            case 'contacts':
                foreach ($scope(Contact::query())->with('site:id,name')->lazyById(200) as $item) {
                    yield [
                        $item->last_name, $item->first_name, $item->job_title, $item->site?->name, $item->email, $item->phone,
                        $item->mobile, $item->is_technical, $item->is_primary, $item->notes,
                    ];
                }
                break;

            case 'services':
                foreach ($scope(Service::query())->with(['site:id,name', 'equipment:id,name'])->lazyById(200) as $item) {
                    yield [
                        $item->name, $item->category->label(), $item->provider, $item->reference, $item->url, $item->site?->name,
                        $item->status->label(), $item->start_date?->format('Y-m-d'), $item->end_date?->format('Y-m-d'),
                        $item->equipment->pluck('name')->implode(', '), $item->description, $item->notes,
                    ];
                }
                break;

            case 'licenses':
                foreach ($scope(License::query())->with('equipment:id,name')->lazyById(200) as $item) {
                    yield [
                        $item->name, $item->kind->label(), $item->publisher, $item->supplier, $item->contract_reference,
                        $item->quantity, $item->billing_period?->label(), $item->cost === null ? null : (float) $item->cost,
                        $item->purchase_date?->format('Y-m-d'), $item->starts_at?->format('Y-m-d'), $item->expires_at?->format('Y-m-d'),
                        $item->auto_renewal, $item->renewal_notice_days, $item->renewal_notes, $item->status->label(),
                        $item->equipment->pluck('name')->implode(', '), $item->notes,
                    ];
                }
                break;

            case 'certificates':
                foreach ($scope(Certificate::query())->with(['service:id,name', 'equipment:id,name'])->lazyById(200) as $item) {
                    yield [
                        $item->name, $item->common_name, $item->subject_alt_names, $item->issuer, $item->type->label(),
                        $item->serial_number, $item->fingerprint_sha256, $item->issued_at?->format('Y-m-d'),
                        $item->expires_at?->format('Y-m-d'), $item->auto_renewal, $item->renewal_method, $item->status->label(),
                        $item->service?->name, $item->equipment->pluck('name')->implode(', '), $item->notes,
                    ];
                }
                break;

            case 'subnets':
                foreach ($scope(Subnet::query())->with(['vlan:id,number,name', 'site:id,name'])->withCount('interfaces')->lazyById(200) as $item) {
                    yield [
                        $item->name, $item->cidr, $item->vlan ? $item->vlan->number.' – '.$item->vlan->name : null, $item->site?->name,
                        $item->gateway, $item->dns_servers, $item->dhcp_enabled, $item->dhcp_range, $item->interfaces_count, $item->description,
                    ];
                }
                break;

            case 'documents':
                $query = $scope(Document::query())->with(['category:id,name', 'owner:id,name'])
                    ->select(['id', 'client_id', 'category_id', 'owner_id', 'title', 'status', 'current_version', 'last_verified_at', 'review_interval_days', 'updated_at']);

                foreach ($query->lazyById(200) as $item) {
                    yield [
                        $item->title, $item->category?->name, $item->status->label(), $item->owner?->name, $item->current_version,
                        $item->last_verified_at?->format('Y-m-d'), $item->updated_at?->format('Y-m-d'),
                    ];
                }
                break;
        }
    }

    public function count(string $dataset, ?array $clientIds, ?Client $client = null): int
    {
        $model = match ($dataset) {
            'clients' => Client::class,
            'equipment' => Equipment::class,
            'sites' => Site::class,
            'contacts' => Contact::class,
            'services' => Service::class,
            'licenses' => License::class,
            'certificates' => Certificate::class,
            'subnets' => Subnet::class,
            'documents' => Document::class,
        };

        $query = $model::query();
        $column = $dataset === 'clients' ? 'id' : 'client_id';

        if ($client !== null) {
            return $query->where($column, $client->id)->count();
        }

        return $clientIds === null ? $query->count() : $query->whereIn($column, $clientIds)->count();
    }
}
