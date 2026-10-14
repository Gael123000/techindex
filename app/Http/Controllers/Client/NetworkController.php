<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plan d'adressage d'un client : VLAN et sous-réseaux.
 */
class NetworkController extends Controller
{
    use InteractsWithClient;

    public function __invoke(Request $request, Client $client): Response
    {
        return Inertia::render('Network/Index', [
            'context' => $this->context($client, $request),
            'vlans' => $client->vlans()->with('site:id,name')->withCount('subnets')->orderBy('number')->get(),
            'subnets' => $client->subnets()->with(['site:id,name', 'vlan:id,number,name'])->withCount('interfaces')->orderBy('cidr')->get(),
            'sites' => $this->siteOptions($client),
            'can' => ['edit' => Gate::allows('contribute', $client)],
        ]);
    }
}
