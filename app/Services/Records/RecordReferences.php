<?php

namespace App\Services\Records;

use App\Models\Client;
use App\Models\RecordType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Cibles des champs « lien vers une fiche » : fiches par défaut (site,
 * équipement...), accès (métadonnées seulement) ou fiches créées
 * (« record:<id> »). Un lien ne peut viser qu'un élément du même client.
 */
final class RecordReferences
{
    private const ROUTES = [
        'site' => 'clients.sites.show',
        'contact' => 'clients.contacts.index',
        'equipment' => 'clients.equipment.show',
        'subnet' => 'clients.network.index',
        'internet_link' => 'clients.internet-links.show',
        'service' => 'clients.services.show',
        'license' => 'clients.licenses.show',
        'certificate' => 'clients.certificates.show',
        'document' => 'clients.documents.show',
        'credential' => 'clients.credentials.index',
    ];

    /** Pages de liste : l'élément n'a pas de fiche propre. */
    private const LIST_ROUTES = ['contact', 'subnet', 'credential'];

    public static function isValidTarget(string $target): bool
    {
        if (preg_match('/^record:(\d+)$/', $target, $matches) === 1) {
            return RecordType::query()->custom()->whereKey((int) $matches[1])->exists();
        }

        return array_key_exists($target, SystemRecordTypes::referenceTargets());
    }

    public static function customTypeId(string $target): ?int
    {
        return preg_match('/^record:(\d+)$/', $target, $matches) === 1 ? (int) $matches[1] : null;
    }

    public static function tableFor(string $target): ?string
    {
        return self::customTypeId($target) !== null ? 'custom_records' : SystemRecordTypes::tableFor($target);
    }

    /**
     * Cibles proposées dans le constructeur.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function targets(): array
    {
        $targets = [];

        foreach (SystemRecordTypes::referenceTargets() as $value => $label) {
            $targets[] = ['value' => $value, 'label' => $label];
        }

        foreach (RecordType::query()->custom()->orderBy('name')->get(['id', 'name']) as $type) {
            $targets[] = ['value' => 'record:'.$type->id, 'label' => 'Fiche « '.$type->name.' »'];
        }

        return $targets;
    }

    public static function existsRule(string $target, Client $client): Exists
    {
        $customType = self::customTypeId($target);
        $rule = Rule::exists((string) self::tableFor($target), 'id')->where('client_id', $client->id);

        return $customType === null ? $rule : $rule->where('record_type_id', $customType);
    }

    /**
     * Éléments du client proposés dans le formulaire.
     *
     * @return list<array{value: int, label: string}>
     */
    public static function options(string $target, Client $client): array
    {
        return self::query($target, $client->id)
            ->limit(1000)
            ->get()
            ->map(fn (object $row): array => ['value' => (int) $row->id, 'label' => (string) $row->label])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Libellés des éléments désignés, limités au client.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public static function labels(string $target, int $clientId, array $ids): array
    {
        if ($ids === [] || self::tableFor($target) === null) {
            return [];
        }

        return self::query($target, $clientId)
            ->whereIn('id', array_values(array_unique($ids)))
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => (string) $row->label])
            ->all();
    }

    public static function url(string $target, int $clientId, int $id): ?string
    {
        if (self::customTypeId($target) !== null) {
            return route('clients.records.show', [$clientId, $id]);
        }

        $route = self::ROUTES[$target] ?? null;

        if ($route === null) {
            return null;
        }

        return in_array($target, self::LIST_ROUTES, true) ? route($route, $clientId) : route($route, [$clientId, $id]);
    }

    /**
     * Requête « id, label » d'une cible, restreinte au client. Les accès ne
     * renvoient que leur libellé : jamais de secret.
     */
    private static function query(string $target, int $clientId): Builder
    {
        $customType = self::customTypeId($target);

        if ($customType !== null) {
            return DB::table('custom_records')->where('client_id', $clientId)->where('record_type_id', $customType)
                ->select(['id', 'title as label']);
        }

        $query = DB::table((string) SystemRecordTypes::tableFor($target))->where('client_id', $clientId);

        return match ($target) {
            'contact' => $query->selectRaw("id, TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) as label"),
            'subnet' => $query->selectRaw("id, CONCAT(cidr, ' – ', name) as label"),
            'equipment' => $query->selectRaw("id, CASE WHEN hostname IS NULL OR hostname = '' THEN name ELSE CONCAT(name, ' (', hostname, ')') END as label"),
            'document' => $query->select(['id', 'title as label']),
            'credential' => $query->select(['id', 'label']),
            default => $query->select(['id', 'name as label']),
        };
    }
}
