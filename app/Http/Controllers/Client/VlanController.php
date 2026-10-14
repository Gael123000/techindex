<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Vlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class VlanController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $vlan = new Vlan($this->validated($request, $client));
        $vlan->client()->associate($client);
        $vlan->save();

        return back()->with('success', 'VLAN ajouté.');
    }

    public function update(Request $request, Client $client, Vlan $vlan): RedirectResponse
    {
        Gate::authorize('update', $vlan);

        $vlan->update($this->validated($request, $client, $vlan));

        return back()->with('success', 'VLAN mis à jour.');
    }

    public function destroy(Client $client, Vlan $vlan): RedirectResponse
    {
        Gate::authorize('update', $vlan);

        $vlan->delete();

        return back()->with('success', 'VLAN supprimé.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Client $client, ?Vlan $vlan = null): array
    {
        return $request->validate([
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('client_id', $client->id)],
            'number' => [
                'required', 'integer', 'between:1,4094',
                Rule::unique('vlans', 'number')
                    ->where('client_id', $client->id)
                    ->where(fn ($query) => $request->input('site_id') ? $query->where('site_id', $request->input('site_id')) : $query->whereNull('site_id'))
                    ->ignore($vlan?->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'number.unique' => 'Ce numéro de VLAN existe déjà pour ce site.',
        ]);
    }
}
