<?php

namespace App\Http\Controllers\Client;

use App\Enums\SubnetPurpose;
use App\Http\Controllers\Concerns\HasRecordExtensions;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Subnet;
use App\Rules\Cidr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SubnetController extends Controller
{
    use HasRecordExtensions;

    public function store(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $data = $this->validated($request, $client, null);

        DB::transaction(function () use ($data, $client): void {
            $subnet = new Subnet(array_diff_key($data, ['fields' => true]));
            $subnet->client()->associate($client);
            $subnet->save();
            $this->saveExtension('subnet', $subnet, $client, $data, true);
        });

        return back()->with('success', 'Sous-réseau ajouté.');
    }

    public function update(Request $request, Client $client, Subnet $subnet): RedirectResponse
    {
        Gate::authorize('update', $subnet);

        $data = $this->validated($request, $client, $subnet);

        DB::transaction(function () use ($data, $client, $subnet): void {
            $subnet->fill(array_diff_key($data, ['fields' => true]));
            $this->saveExtension('subnet', $subnet, $client, $data, false);
            $subnet->save();
            $subnet->flushPendingAudit();
        });

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
    private function validated(Request $request, Client $client, ?Subnet $subnet): array
    {
        $data = $request->validate([
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('client_id', $client->id)],
            'vlan_id' => ['nullable', 'integer', Rule::exists('vlans', 'id')->where('client_id', $client->id)],
            'purpose' => ['nullable', Rule::enum(SubnetPurpose::class)],
            'internet_link_id' => ['nullable', 'integer', Rule::exists('internet_links', 'id')->where('client_id', $client->id)],
            'name' => ['required', 'string', 'max:120'],
            'cidr' => ['required', 'string', 'max:50', new Cidr],
            'gateway' => ['nullable', 'ip'],
            'dns_servers' => ['nullable', 'string', 'max:255'],
            'dhcp_enabled' => ['boolean'],
            'dhcp_range' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Champs ajoutés avec le constructeur de fiches (fields[...]).
            ...$this->recordExtensions()->rules('subnet', $client, $request->all(), $subnet),
        ], [], $this->recordExtensions()->attributes('subnet'));

        $data['cidr'] = trim($data['cidr']);

        return $data;
    }
}
