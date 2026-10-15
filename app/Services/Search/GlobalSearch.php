<?php

namespace App\Services\Search;

use App\Models\Client;
use App\Models\CustomRecord;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\InternetLink;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Recherche globale.
 *
 * - Les droits sont appliqués dans chaque requête SQL (scope visibleTo) :
 *   aucun résultat, compteur ou extrait ne provient d'un client inaccessible.
 * - Textes : index FULLTEXT de MariaDB (mode booléen, préfixes) ; identifiants
 *   courts (nom d'hôte, n° de série, IP, MAC) : recherche LIKE.
 * - Les secrets (table credentials) ne sont jamais interrogés.
 */
class GlobalSearch
{
    public const TYPES = [
        'clients' => 'Clients',
        'sites' => 'Sites',
        'equipment' => 'Équipements',
        'internet_links' => 'Accès Internet (FAI)',
        'services' => 'Services',
        'documents' => 'Documentation',
        'records' => 'Fiches personnalisées',
    ];

    private const GROUP_LIMIT = 8;

    private const PAGE_SIZE = 25;

    /**
     * @param  array{client?: int|null, site?: int|null, status?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function search(User $user, string $term, array $filters = [], ?string $type = null, int $page = 1): array
    {
        $term = trim($term);
        $types = $type !== null && isset(self::TYPES[$type]) ? [$type] : array_keys(self::TYPES);
        $groups = [];

        foreach ($types as $key) {
            $query = $this->query($key, $user, $term, $filters);

            if ($query === null) {
                continue;
            }

            if ($type !== null) {
                $paginator = $query->paginate(self::PAGE_SIZE, ['*'], 'page', $page)->withQueryString();

                return [
                    'mode' => 'type',
                    'type' => $key,
                    'label' => self::TYPES[$key],
                    'results' => $paginator->through(fn (Model $model): array => $this->present($key, $model, $term)),
                ];
            }

            $total = (clone $query)->toBase()->getCountForPagination();

            $groups[] = [
                'type' => $key,
                'label' => self::TYPES[$key],
                'total' => $total,
                'items' => $total === 0 ? [] : $query->limit(self::GROUP_LIMIT)->get()
                    ->map(fn (Model $model): array => $this->present($key, $model, $term))
                    ->all(),
            ];
        }

        return ['mode' => 'groups', 'groups' => $groups];
    }

    /**
     * Suggestions de la barre de recherche globale : quelques résultats par
     * type, sans comptage complet (une ligne de plus suffit à savoir s'il en
     * existe d'autres), pour répondre pendant la frappe.
     *
     * @return list<array{type: string, label: string, has_more: bool, items: list<array<string, mixed>>}>
     */
    public function suggest(User $user, string $term, int $limit = 5): array
    {
        $term = trim($term);
        $groups = [];

        foreach (array_keys(self::TYPES) as $key) {
            $query = $this->query($key, $user, $term, []);

            if ($query === null) {
                continue;
            }

            $models = $query->limit($limit + 1)->get();

            if ($models->isEmpty()) {
                continue;
            }

            $groups[] = [
                'type' => $key,
                'label' => self::TYPES[$key],
                'has_more' => $models->count() > $limit,
                'items' => $models->take($limit)
                    ->map(fn (Model $model): array => $this->present($key, $model, $term))
                    ->values()
                    ->all(),
            ];
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>|null
     */
    private function query(string $type, User $user, string $term, array $filters): ?Builder
    {
        $client = $filters['client'] ?? null;
        $site = $filters['site'] ?? null;
        $status = $filters['status'] ?? null;

        // Le filtre "site" ne s'applique qu'aux ressources rattachées à un site.
        if ($site !== null && in_array($type, ['clients', 'documents'], true)) {
            return null;
        }

        return match ($type) {
            'clients' => Client::query()->visibleTo($user)
                ->when($client, fn (Builder $query) => $query->whereKey($client))
                ->when($status, fn (Builder $query) => $this->statusFilter($query, $status, 'archived'))
                ->where(fn (Builder $query) => $this->match($query, ['name', 'reference', 'city', 'description', 'notes'], ['name', 'reference'], $term)
                    ->orWhereHas('tags', fn (Builder $query) => $query->where('name', 'like', $this->like($term))))
                ->with('tags:id,name,color')
                ->tap(fn (Builder $query) => $this->orderByRelevance($query, ['name', 'reference', 'city', 'description', 'notes'], $term, 'name')),

            'sites' => Site::query()->visibleTo($user)
                ->when($client, fn (Builder $query) => $query->where('client_id', $client))
                ->when($site, fn (Builder $query) => $query->whereKey($site))
                ->when($status, fn (Builder $query) => $this->statusFilter($query, $status, 'archived'))
                ->where(fn (Builder $query) => $this->match($query, ['name', 'code', 'city', 'address_line1'], ['name', 'code'], $term))
                ->with('client:id,name')
                ->tap(fn (Builder $query) => $this->orderByRelevance($query, ['name', 'code', 'city', 'address_line1'], $term, 'name')),

            'equipment' => Equipment::query()->visibleTo($user)
                ->when($client, fn (Builder $query) => $query->where('client_id', $client))
                ->when($site, fn (Builder $query) => $query->where('site_id', $site))
                ->when($status, fn (Builder $query) => $this->statusFilter($query, $status, 'archived'))
                ->where(fn (Builder $query) => $this->match(
                    $query,
                    ['name', 'hostname', 'manufacturer', 'model', 'serial_number', 'inventory_number', 'role', 'description'],
                    ['name', 'hostname', 'serial_number', 'inventory_number', 'model'],
                    $term,
                )
                    ->orWhereHas('interfaces', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                        ->where('ip_address', 'like', $this->prefix($term))
                        ->when($this->macPrefix($term), fn (Builder $query, string $mac) => $query->orWhere('mac_address', 'like', $mac))))
                    ->orWhereHas('tags', fn (Builder $query) => $query->where('name', 'like', $this->like($term))))
                ->with(['client:id,name', 'type:id,name,icon', 'site:id,name', 'interfaces:id,equipment_id,ip_address,mac_address,is_primary'])
                ->tap(fn (Builder $query) => $this->orderByRelevance(
                    $query,
                    ['name', 'hostname', 'manufacturer', 'model', 'serial_number', 'inventory_number', 'role', 'description'],
                    $term,
                    'name',
                )),

            'internet_links' => InternetLink::query()->visibleTo($user)
                ->when($client, fn (Builder $query) => $query->where('client_id', $client))
                ->when($site, fn (Builder $query) => $query->where('site_id', $site))
                ->when($status, fn (Builder $query) => $this->statusFilter($query, $status, 'archived'))
                ->where(fn (Builder $query) => $this->match(
                    $query,
                    ['name', 'operator_name', 'description', 'notes'],
                    ['name', 'operator_name', 'line_reference', 'customer_number', 'contract_reference'],
                    $term,
                )
                    ->orWhere('public_ips', 'like', $this->like($term))
                    ->orWhereHas('isp', fn (Builder $query) => $query->where('name', 'like', $this->like($term)))
                    ->orWhereHas('tags', fn (Builder $query) => $query->where('name', 'like', $this->like($term))))
                ->with(['client:id,name', 'isp', 'site:id,name'])
                ->tap(fn (Builder $query) => $this->orderByRelevance($query, ['name', 'operator_name', 'description', 'notes'], $term, 'name')),

            'services' => Service::query()->visibleTo($user)
                ->when($client, fn (Builder $query) => $query->where('client_id', $client))
                ->when($site, fn (Builder $query) => $query->where('site_id', $site))
                ->when($status, fn (Builder $query) => $this->statusFilter($query, $status, 'archived'))
                ->where(fn (Builder $query) => $this->match($query, ['name', 'provider', 'reference', 'description'], ['name', 'reference'], $term)
                    ->orWhereHas('tags', fn (Builder $query) => $query->where('name', 'like', $this->like($term))))
                ->with('client:id,name')
                ->tap(fn (Builder $query) => $this->orderByRelevance($query, ['name', 'provider', 'reference', 'description'], $term, 'name')),

            'documents' => Document::query()->visibleTo($user)
                ->when($client, fn (Builder $query) => $query->where('client_id', $client))
                ->when($status, fn (Builder $query) => $this->statusFilter($query, $status, 'archived'))
                ->where(fn (Builder $query) => $this->match($query, ['title', 'content_text'], ['title'], $term)
                    ->orWhereHas('tags', fn (Builder $query) => $query->where('name', 'like', $this->like($term))))
                ->with(['client:id,name', 'category:id,name'])
                ->select(['id', 'client_id', 'category_id', 'title', 'content_text', 'status', 'last_verified_at', 'review_interval_days', 'updated_at'])
                ->tap(fn (Builder $query) => $this->orderByRelevance($query, ['title', 'content_text'], $term, 'title')),

            'records' => CustomRecord::query()->visibleTo($user)
                ->whereHas('recordType', fn (Builder $query) => $query->where('is_system', false)->where('is_active', true))
                ->when($client, fn (Builder $query) => $query->where('client_id', $client))
                ->when($site, fn (Builder $query) => $query->where('site_id', $site))
                ->when($status, fn (Builder $query) => $this->statusFilter($query, $status, 'archived'))
                ->where(fn (Builder $query) => $this->match($query, ['title', 'search_text'], ['title'], $term)
                    ->orWhereHas('tags', fn (Builder $query) => $query->where('name', 'like', $this->like($term))))
                ->with(['client:id,name', 'recordType:id,name,icon', 'site:id,name'])
                ->select(['id', 'client_id', 'record_type_id', 'site_id', 'title', 'search_text', 'status'])
                ->tap(fn (Builder $query) => $this->orderByRelevance($query, ['title', 'search_text'], $term, 'title')),

            default => null,
        };
    }

    /**
     * "active" = non archivé ; "archived" = archivé.
     *
     * @param  Builder<Model>  $query
     */
    private function statusFilter(Builder $query, string $status, string $archivedValue): void
    {
        match ($status) {
            'archived' => $query->where('status', $archivedValue),
            'active' => $query->where('status', '!=', $archivedValue),
            default => null,
        };
    }

    /**
     * Correspondance plein texte (MariaDB) ou LIKE (autres moteurs), complétée
     * par un LIKE sur les identifiants courts.
     *
     * @param  Builder<Model>  $query
     * @param  list<string>  $fullTextColumns
     * @param  list<string>  $likeColumns
     * @return Builder<Model>
     */
    private function match(Builder $query, array $fullTextColumns, array $likeColumns, string $term): Builder
    {
        $boolean = $this->booleanQuery($term);

        if ($this->supportsFullText($query) && $boolean !== null) {
            $query->whereFullText($fullTextColumns, $boolean, ['mode' => 'boolean']);
        } else {
            foreach ($fullTextColumns as $column) {
                $query->orWhere($column, 'like', $this->like($term));
            }
        }

        foreach ($likeColumns as $column) {
            $query->orWhere($column, 'like', $this->like($term));
        }

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $columns
     */
    private function orderByRelevance(Builder $query, array $columns, string $term, string $fallback): void
    {
        $boolean = $this->booleanQuery($term);

        if ($this->supportsFullText($query) && $boolean !== null) {
            $wrapped = implode(', ', array_map(fn (string $column): string => $query->getQuery()->getGrammar()->wrap($column), $columns));
            $query->orderByRaw("MATCH ({$wrapped}) AGAINST (? IN BOOLEAN MODE) DESC", [$boolean]);
        }

        $query->orderBy($fallback);
    }

    /**
     * Construit une requête booléenne "+mot*" pour chaque mot d'au moins
     * 3 caractères (taille minimale indexée par InnoDB).
     */
    private function booleanQuery(string $term): ?string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn (string $word): bool => mb_strlen($word) >= 3));

        if ($words === []) {
            return null;
        }

        return implode(' ', array_map(fn (string $word): string => '+'.$word.'*', array_slice($words, 0, 8)));
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function supportsFullText(Builder $query): bool
    {
        return in_array($query->getConnection()->getDriverName(), ['mariadb', 'mysql'], true);
    }

    private function like(string $term): string
    {
        return '%'.$this->escapeLike($term).'%';
    }

    private function prefix(string $term): string
    {
        return $this->escapeLike($term).'%';
    }

    /**
     * Début d'adresse MAC au format stocké (AA:BB:CC...), quel que soit le
     * séparateur saisi (aucun, deux-points, tiret ou point).
     */
    private function macPrefix(string $term): ?string
    {
        if (! preg_match('/^[0-9a-fA-F:.\-]{2,17}$/', $term)) {
            return null;
        }

        $hex = strtoupper((string) preg_replace('/[^0-9a-fA-F]/', '', $term));

        return strlen($hex) < 2 ? null : implode(':', str_split($hex, 2)).'%';
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $type, Model $model, string $term): array
    {
        return match ($type) {
            'clients' => [
                'id' => $model->id,
                'title' => $model->name,
                'subtitle' => collect([$model->reference, $model->city])->filter()->implode(' · '),
                'url' => route('clients.show', $model->id),
                'status' => $model->status->value,
                'client' => null,
                'tags' => $model->tags->map->only(['id', 'name', 'color'])->values(),
            ],
            'sites' => [
                'id' => $model->id,
                'title' => $model->name,
                'subtitle' => collect([$model->code, $model->city])->filter()->implode(' · '),
                'url' => route('clients.sites.show', [$model->client_id, $model->id]),
                'status' => $model->status->value,
                'client' => $model->client->only(['id', 'name']),
            ],
            'equipment' => [
                'id' => $model->id,
                'title' => $model->name,
                'subtitle' => collect([
                    $model->type?->name,
                    trim(($model->manufacturer ?? '').' '.($model->model ?? '')),
                    $model->serial_number ? 'S/N '.$model->serial_number : null,
                    $model->interfaces->pluck('ip_address')->filter()->take(3)->implode(', '),
                    $model->site?->name,
                ])->filter()->implode(' · '),
                'url' => route('clients.equipment.show', [$model->client_id, $model->id]),
                'status' => $model->status->value,
                'client' => $model->client->only(['id', 'name']),
                'icon' => $model->type?->icon,
            ],
            'internet_links' => [
                'id' => $model->id,
                'title' => $model->name,
                'subtitle' => collect([$model->operatorLabel(), $model->technology?->label(), $model->bandwidthLabel(), $model->line_reference, $model->site?->name])
                    ->filter()->implode(' · '),
                'url' => route('clients.internet-links.show', [$model->client_id, $model->id]),
                'status' => $model->status->value,
                'client' => $model->client->only(['id', 'name']),
                'logo_url' => $model->isp?->logoUrl(),
            ],
            'services' => [
                'id' => $model->id,
                'title' => $model->name,
                'subtitle' => collect([$model->category->label(), $model->provider, $model->reference])->filter()->implode(' · '),
                'url' => route('clients.services.show', [$model->client_id, $model->id]),
                'status' => $model->status->value,
                'client' => $model->client->only(['id', 'name']),
            ],
            'records' => [
                'id' => $model->id,
                'title' => $model->title,
                'subtitle' => collect([$model->recordType?->name, $model->site?->name])->filter()->implode(' · '),
                'excerpt' => $model->search_text ? $this->excerpt((string) $model->search_text, $term) : null,
                'url' => route('clients.records.show', [$model->client_id, $model->id]),
                'status' => $model->status->value,
                'client' => $model->client->only(['id', 'name']),
                'icon' => $model->recordType?->icon,
            ],
            'documents' => [
                'id' => $model->id,
                'title' => $model->title,
                'subtitle' => $model->category?->name,
                'excerpt' => $this->excerpt((string) $model->content_text, $term),
                'url' => route('clients.documents.show', [$model->client_id, $model->id]),
                'status' => $model->status->value,
                'client' => $model->client->only(['id', 'name']),
            ],
        };
    }

    private function excerpt(string $text, string $term, int $radius = 90): ?string
    {
        if ($text === '') {
            return null;
        }

        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $position = null;

        foreach ($words as $word) {
            $found = mb_stripos($text, $word);

            if ($found !== false) {
                $position = $found;
                break;
            }
        }

        if ($position === null) {
            return Str::limit($text, $radius * 2);
        }

        $start = max(0, $position - $radius);
        $excerpt = mb_substr($text, $start, $radius * 2);

        return ($start > 0 ? '…' : '').trim($excerpt).(mb_strlen($text) > $start + $radius * 2 ? '…' : '');
    }
}
