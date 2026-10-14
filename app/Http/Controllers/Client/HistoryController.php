<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Support\AuditPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Historique des événements d'un client (modifications, révélations de
 * secrets, imports...). Aucune valeur de secret n'y figure.
 */
class HistoryController extends Controller
{
    use InteractsWithClient;

    public function __invoke(Request $request, Client $client): Response
    {
        $filters = $request->validate([
            'type' => ['nullable', 'string', 'max:40'],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $user = $request->user();

        $entries = AuditLog::query()
            ->where('client_id', $client->id)
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('subject_type', $type))
            ->when($filters['user'] ?? null, fn (Builder $query, int $id) => $query->where('user_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<', Carbon::parse($to)->addDay()))
            ->with('user:id,name')
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => AuditPresenter::present($log, $user));

        return Inertia::render('History/Index', [
            'context' => $this->context($client, $request),
            'entries' => $entries,
            'filters' => [
                'type' => $filters['type'] ?? null,
                'user' => $filters['user'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'types' => AuditPresenter::typeOptions(),
        ]);
    }
}
