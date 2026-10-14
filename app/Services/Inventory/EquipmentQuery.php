<?php

namespace App\Services\Inventory;

use App\Enums\EquipmentStatus;
use App\Models\Equipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Filtres, tri et mise en forme des listes d'équipements (liste d'un
 * client et liste globale). Les droits sont appliqués par l'appelant via
 * le scope visibleTo() ou la relation du client.
 */
final class EquipmentQuery
{
    public const SORTS = ['name', 'hostname', 'model', 'serial_number', 'warranty_expires_at', 'updated_at'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'integer'],
            'site' => ['nullable', 'integer'],
            'client' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([...EquipmentStatus::values(), 'current', 'all'])],
            'tag' => ['nullable', 'integer'],
            'alert' => ['nullable', Rule::in(['warranty', 'end_of_life'])],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * @param  Builder<Equipment>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Equipment>
     */
    public static function apply(Builder $query, array $filters): Builder
    {
        $status = $filters['status'] ?? 'current';
        $alerts = config('techindex.alerts');

        return $query
            ->when($status === 'current', fn (Builder $query) => $query->where('status', '!=', EquipmentStatus::Archived))
            ->when(! in_array($status, ['current', 'all'], true), fn (Builder $query) => $query->where('status', $status))
            ->when($filters['q'] ?? null, function (Builder $query, string $term): void {
                $like = '%'.$term.'%';
                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('hostname', 'like', $like)
                    ->orWhere('serial_number', 'like', $like)
                    ->orWhere('inventory_number', 'like', $like)
                    ->orWhere('model', 'like', $like)
                    ->orWhere('manufacturer', 'like', $like)
                    ->orWhereHas('interfaces', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                        ->where('ip_address', 'like', $term.'%')
                        ->orWhere('mac_address', 'like', strtoupper($term).'%'))));
            })
            ->when($filters['type'] ?? null, fn (Builder $query, int $type) => $query->where('equipment_type_id', $type))
            ->when($filters['site'] ?? null, fn (Builder $query, int $site) => $query->where('site_id', $site))
            ->when($filters['client'] ?? null, fn (Builder $query, int $client) => $query->where('client_id', $client))
            ->when($filters['tag'] ?? null, fn (Builder $query, int $tag) => $query->whereHas('tags', fn (Builder $query) => $query->whereKey($tag)))
            ->when(($filters['alert'] ?? null) === 'warranty', fn (Builder $query) => $query
                ->whereNotNull('warranty_expires_at')
                ->where('warranty_expires_at', '<=', today()->addDays($alerts['warranty_days'])))
            ->when(($filters['alert'] ?? null) === 'end_of_life', fn (Builder $query) => $query
                ->whereNotNull('end_of_life_at')
                ->where('end_of_life_at', '<=', today()->addDays($alerts['end_of_life_days'])))
            ->with([
                'type:id,name,icon',
                'site:id,name',
                'client:id,name',
                'tags:id,name,color',
                'interfaces' => fn ($query) => $query->select(['id', 'equipment_id', 'ip_address', 'is_primary']),
            ])
            ->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Equipment $equipment): array
    {
        $primary = $equipment->interfaces->firstWhere('is_primary', true) ?? $equipment->interfaces->first();

        return [
            'id' => $equipment->id,
            'client' => ['id' => $equipment->client->id, 'name' => $equipment->client->name],
            'name' => $equipment->name,
            'hostname' => $equipment->hostname,
            'manufacturer' => $equipment->manufacturer,
            'model' => $equipment->model,
            'serial_number' => $equipment->serial_number,
            'status' => $equipment->status->value,
            'status_label' => $equipment->status->label(),
            'warranty_expires_at' => $equipment->warranty_expires_at?->format('Y-m-d'),
            'type' => $equipment->type?->only(['id', 'name', 'icon']),
            'site' => $equipment->site?->only(['id', 'name']),
            'primary_ip' => $primary?->ip_address,
            'ip_count' => $equipment->interfaces->whereNotNull('ip_address')->count(),
            'tags' => $equipment->tags->map->only(['id', 'name', 'color'])->values(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function normalizedFilters(array $filters): array
    {
        return [
            'q' => $filters['q'] ?? '',
            'type' => $filters['type'] ?? null,
            'site' => $filters['site'] ?? null,
            'client' => $filters['client'] ?? null,
            'status' => $filters['status'] ?? 'current',
            'tag' => $filters['tag'] ?? null,
            'alert' => $filters['alert'] ?? null,
            'sort' => $filters['sort'] ?? 'name',
            'direction' => $filters['direction'] ?? 'asc',
        ];
    }
}
