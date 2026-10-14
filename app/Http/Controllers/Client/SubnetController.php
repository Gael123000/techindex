<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Subnet;
use App\Rules\Cidr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SubnetController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $subnet = new Subnet($this->validated($request, $client));
        $subnet->client()->associate($client);
        $subnet->save();

        return back()->with('success', 'Sous-réseau ajouté.');
    }

    public function update(Request $request, Client $client, Subnet $subnet): RedirectResponse
    {
        Gate::authorize('update', $subnet);

        $subnet->update($this->validated($request, $client));

        return back()->with('success', 'Sous-réseau mis à jour.');
    }

    public function destroy(Client $client, Subnet $subnet): RedirectResponse
    {
        Gate::authorize('update', $subnet);

        $subnet->delete();

        return back()->with('success', 'Sous-réseau supprimé. Les adresses des équipements ont été conservées.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Client $client): array
    {
        $data = $request->validate([
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('client_id', $client->id)],
            'vlan_id' => ['nullable', 'integer', Rule::exists('vlans', 'id')->where('client_id', $client->id)],
            'name' => ['required', 'string', 'max:120'],
            'cidr' => ['required', 'string', 'max:50', new Cidr],
            'gateway' => ['nullable', 'ip'],
            'dns_servers' => ['nullable', 'string', 'max:255'],
            'dhcp_enabled' => ['boolean'],
            'dhcp_range' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['cidr'] = trim($data['cidr']);

        return $data;
    }
}
