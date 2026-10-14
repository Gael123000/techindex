<?php

namespace App\Http\Controllers;

use App\Enums\CertificateStatus;
use App\Enums\DocumentStatus;
use App\Enums\EquipmentStatus;
use App\Enums\LicenseStatus;
use App\Enums\RecordStatus;
use App\Enums\ServiceStatus;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\License;
use App\Models\Service;
use App\Support\AuditPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord : tous les indicateurs sont calculés sur les données
 * réelles, restreintes dès la requête aux clients accessibles.
 */
class DashboardController extends Controller
{
    private const LIST_LIMIT = 8;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $today = today();
        $alerts = config('techindex.alerts');

        $activeClients = Client::query()->visibleTo($user)->where('status', RecordStatus::Active);

        $warranties = Equipment::query()->visibleTo($user)
            ->where('status', '!=', EquipmentStatus::Archived)
            ->whereBetween('warranty_expires_at', [$today->copy()->subDays(30), $today->copy()->addDays($alerts['warranty_days'])])
            ->orderBy('warranty_expires_at');

        $endOfLife = Equipment::query()->visibleTo($user)
            ->where('status', '!=', EquipmentStatus::Archived)
            ->whereNotNull('end_of_life_at')
            ->where('end_of_life_at', '<=', $today->copy()->addDays($alerts['end_of_life_days']))
            ->orderBy('end_of_life_at');

        $licenses = License::query()->visibleTo($user)
            ->where('status', LicenseStatus::Active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $today->copy()->addDays($alerts['license_days']))
            ->orderBy('expires_at');

        $certificates = Certificate::query()->visibleTo($user)
            ->where('status', CertificateStatus::Active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $today->copy()->addDays($alerts['certificate_days']))
            ->orderBy('expires_at');

        $services = Service::query()->visibleTo($user)
            ->where('status', ServiceStatus::Active)
            ->whereNotNull('end_date')
            ->where('end_date', '<=', $today->copy()->addDays($alerts['service_days']))
            ->orderBy('end_date');

        $documents = Document::query()->visibleTo($user)
            ->needingReview()
            ->orderByRaw('last_verified_at IS NOT NULL, last_verified_at');

        return Inertia::render('Dashboard', [
            'stats' => [
                'clients' => (clone $activeClients)->count(),
                'equipment' => Equipment::query()->visibleTo($user)->where('status', '!=', EquipmentStatus::Archived)->count(),
                'services' => Service::query()->visibleTo($user)->where('status', ServiceStatus::Active)->count(),
                'documents' => Document::query()->visibleTo($user)->where('status', DocumentStatus::Published)->count(),
            ],
            'alertDays' => $alerts,
            'alerts' => [
                'warranties' => $this->alertList($warranties, 'warranty_expires_at', 'equipment'),
                'licenses' => $this->alertList($licenses, 'expires_at', 'licenses'),
                'certificates' => $this->alertList($certificates, 'expires_at', 'certificates'),
                'services' => $this->alertList($services, 'end_date', 'services'),
                'end_of_life' => $this->alertList($endOfLife, 'end_of_life_at', 'equipment'),
                'documents' => $this->alertList($documents, 'last_verified_at', 'documents', titleColumn: 'title'),
            ],
            'clients' => (clone $activeClients)
                ->withCount(['equipment', 'sites', 'documents'])
                ->orderByDesc('updated_at')
                ->limit(6)
                ->get(['id', 'name', 'reference', 'city', 'updated_at']),
            'recentChanges' => AuditLog::query()
                ->visibleTo($user)
                ->whereNotNull('client_id')
                ->where(fn (Builder $query) => $query
                    ->where('event', 'like', '%.created')
                    ->orWhere('event', 'like', '%.updated')
                    ->orWhere('event', 'like', '%.archived')
                    ->orWhere('event', 'like', '%.restored'))
                ->with(['user:id,name', 'client:id,name'])
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (AuditLog $log): array => AuditPresenter::present($log, $user)),
        ]);
    }

    /**
     * @param  Builder<Model>  $query
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function alertList(Builder $query, string $dateColumn, string $route, string $titleColumn = 'name'): array
    {
        $total = (clone $query)->count();

        $items = $query->with('client:id,name')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (Model $model): array => [
                'id' => $model->getKey(),
                'name' => $model->getAttribute($titleColumn),
                'client' => ['id' => $model->client->id, 'name' => $model->client->name],
                'date' => $model->getAttribute($dateColumn) instanceof \DateTimeInterface
                    ? $model->getAttribute($dateColumn)->format('Y-m-d')
                    : null,
                'url' => route("clients.{$route}.show", [$model->getAttribute('client_id'), $model->getKey()]),
            ])
            ->all();

        return ['total' => $total, 'items' => $items];
    }
}
