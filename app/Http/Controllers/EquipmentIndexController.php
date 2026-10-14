<?php

namespace App\Http\Controllers;

use App\Enums\EquipmentStatus;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Services\Inventory\EquipmentQuery;
use App\Services\Tags\TagResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inventaire transversal : équipements de tous les clients accessibles.
 */
class EquipmentIndexController extends Controller
{
    public function __invoke(Request $request, TagResolver $tags): Response
    {
        $user = $request->user();
        $filters = $request->validate(EquipmentQuery::rules());

        $equipment = EquipmentQuery::apply(Equipment::query()->visibleTo($user), $filters)
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Equipment $item): array => EquipmentQuery::present($item));

        return Inertia::render('Equipment/Index', [
            'context' => null,
            'equipment' => $equipment,
            'filters' => EquipmentQuery::normalizedFilters($filters),
            'options' => [
                'types' => EquipmentType::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                    ->map(fn (EquipmentType $type): array => ['value' => $type->id, 'label' => $type->name]),
                'clients' => Client::query()->visibleTo($user)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Client $client): array => ['value' => $client->id, 'label' => $client->name]),
                'sites' => [],
                'statuses' => EquipmentStatus::options(),
                'tags' => $tags->options(),
            ],
        ]);
    }
}
