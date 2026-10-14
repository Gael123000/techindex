<?php

namespace App\Http\Controllers\Client;

use App\Enums\EquipmentLinkType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\EquipmentLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Relations entre équipements d'un même client (hôte / VM, pare-feu...).
 */
class EquipmentLinkController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $sameClient = Rule::exists('equipment', 'id')->where('client_id', $client->id);

        $data = $request->validate([
            'source_equipment_id' => ['required', 'integer', $sameClient],
            'target_equipment_id' => ['required', 'integer', 'different:source_equipment_id', $sameClient],
            'type' => ['required', Rule::enum(EquipmentLinkType::class)],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'target_equipment_id.different' => 'Un équipement ne peut pas être relié à lui-même.',
        ]);

        $exists = $client->equipmentLinks()
            ->where('source_equipment_id', $data['source_equipment_id'])
            ->where('target_equipment_id', $data['target_equipment_id'])
            ->where('type', $data['type'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['target_equipment_id' => 'Cette relation existe déjà.']);
        }

        $link = new EquipmentLink($data);
        $link->client()->associate($client);
        $link->save();

        return back()->with('success', 'Relation ajoutée.');
    }

    public function destroy(Client $client, EquipmentLink $equipmentLink): RedirectResponse
    {
        Gate::authorize('update', $equipmentLink);

        $equipmentLink->delete();

        return back()->with('success', 'Relation supprimée.');
    }
}
