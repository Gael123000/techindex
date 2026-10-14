<?php

namespace App\Http\Controllers;

use App\Enums\ClientPermission;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Support\AuditPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Journal d'audit global. Un administrateur voit tout (y compris les
 * connexions) ; un responsable voit les événements des clients qu'il gère.
 */
class AuditLogController extends Controller
{
    private const CATEGORIES = [
        'auth' => 'Authentification',
        'secrets' => 'Secrets',
        'access' => 'Droits',
        'data' => 'Données',
        'import_export' => 'Imports et exports',
    ];

    public function __invoke(Request $request): Response
    {
        Gate::authorize('view-audit-log');

        $user = $request->user();
        $filters = $request->validate([
            'client' => ['nullable', 'integer'],
            'user' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'type' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $entries = AuditLog::query()
            ->visibleTo($user, ClientPermission::Manage)
            ->when($filters['client'] ?? null, fn (Builder $query, int $id) => $query->where('client_id', $id))
            ->when($filters['user'] ?? null, fn (Builder $query, int $id) => $query->where('user_id', $id))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('subject_type', $type))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where('subject_label', 'like', "%{$term}%"))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => match ($category) {
                'auth' => $query->where('event', 'like', 'auth.%'),
                'secrets' => $query->where(fn (Builder $query) => $query->where('event', 'like', 'credential.secret_%')->orWhere('event', 'like', 'secrets.%')->orWhere('event', 'like', 'vault.%')),
                'access' => $query->where(fn (Builder $query) => $query->where('event', 'like', 'access.%')->orWhere('event', 'like', 'user.%')),
                'import_export' => $query->where(fn (Builder $query) => $query->where('event', 'like', 'import.%')->orWhere('event', 'like', 'export.%')),
                default => $query->where(fn (Builder $query) => $query->where('event', 'like', '%.created')->orWhere('event', 'like', '%.updated')
                    ->orWhere('event', 'like', '%.archived')->orWhere('event', 'like', '%.restored')->orWhere('event', 'like', '%.deleted')),
            })
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<', Carbon::parse($to)->addDay()))
            ->with(['user:id,name', 'client:id,name'])
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => AuditPresenter::present($log, $user));

        return Inertia::render('Audit/Index', [
            'entries' => $entries,
            'filters' => [
                'client' => $filters['client'] ?? null,
                'user' => $filters['user'] ?? null,
                'category' => $filters['category'] ?? null,
                'type' => $filters['type'] ?? null,
                'q' => $filters['q'] ?? '',
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'options' => [
                'clients' => Client::query()->visibleTo($user, ClientPermission::Manage)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Client $client): array => ['value' => $client->id, 'label' => $client->name]),
                'users' => $user->isAdmin()
                    ? User::query()->orderBy('name')->get(['id', 'name'])->map(fn (User $item): array => ['value' => $item->id, 'label' => $item->name])
                    : [],
                'categories' => collect(self::CATEGORIES)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values(),
                'types' => AuditPresenter::typeOptions(),
            ],
        ]);
    }
}
