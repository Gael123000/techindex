<?php

namespace App\Http\Controllers\Client;

use App\Enums\SubnetPurpose;
use App\Http\Controllers\Concerns\HasRecordExtensions;
use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Subnet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plan d'adressage d'un client : VLAN et sous-réseaux, avec le lien Internet
 * (FAI) qui dessert chaque réseau WAN.
 */
class NetworkController extends Controller
{
    use HasRecordExtensions, InteractsWithClient;

    public function __invoke(Request $request, Client $client): Response
    {
        $subnets = $client->subnets()
            ->with(['site:id,name', 'vlan:id,number,name', 'internetLink:id,name,isp_id,operator_name', 'internetLink.isp'])
            ->withCount('interfaces')
            ->orderBy('cidr')
            ->get();

        // Champs ajoutés avec le constructeur de fiches (fiche « Réseau »).
        $extension = $this->recordExtensions()->definition('subnet', $client);
        $values = $extension === null ? [] : $this->recordExtensions()->valuesFor('subnet', $subnets->pluck('id')->all());
        $displays = $extension === null ? [] : $this->recordExtensions()->presentMany('subnet', $client->id, $subnets, fn (Subnet $subnet): array => ['purpose' => $subnet->purpose?->value]);

        return Inertia::render('Network/Index', [
            'context' => $this->context($client, $request),
            'extension' => $extension,
            'vlans' => $client->vlans()->with('site:id,name')->withCount('subnets')->orderBy('number')->get(),
            'subnets' => $subnets->map(fn (Subnet $subnet): array => [
                ...$subnet->toArray(),
                'purpose' => $subnet->purpose?->value,
                'purpose_label' => $subnet->purpose?->label(),
                'internet_link' => $subnet->internetLink === null ? null : [
                    'id' => $subnet->internetLink->id,
                    'name' => $subnet->internetLink->name,
                    'operator' => $subnet->internetLink->operatorLabel(),
                    'logo_url' => $subnet->internetLink->isp?->logoUrl(),
                ],
                'fields' => (object) ($values[$subnet->id] ?? []),
                'extra' => $displays[$subnet->id] ?? [],
            ]),
            'sites' => $this->siteOptions($client),
            'purposes' => SubnetPurpose::options(),
            'internetLinks' => $this->internetLinkOptions($client),
            'can' => ['edit' => Gate::allows('contribute', $client)],
        ]);
    }
}
