<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Updates\Release;
use App\Services\Updates\UpdateException;
use App\Services\Updates\UpdateManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration > Mises à jour : version installée, recherche d'une
 * nouvelle version sur le canal officiel et, sur un hébergement Plesk,
 * installation confiée à la tâche planifiée.
 */
class UpdateController extends Controller
{
    public function index(UpdateManager $updates): Response
    {
        Gate::authorize('manage-updates');

        return Inertia::render('Admin/Updates/Index', $this->state($updates));
    }

    public function check(UpdateManager $updates): RedirectResponse
    {
        Gate::authorize('manage-updates');

        if (! config('techindex.updates.enabled')) {
            return back()->with('error', 'La recherche de mises à jour est désactivée (UPDATE_CHECK_ENABLED).');
        }

        $result = $updates->check();

        if ($result['error'] !== null) {
            return back()->with('error', $result['error']);
        }

        return back()->with('success', $result['available']
            ? 'La version '.$result['latest']['version'].' est disponible.'
            : 'TechIndex est à jour.');
    }

    public function status(UpdateManager $updates): JsonResponse
    {
        Gate::authorize('manage-updates');

        return response()->json($this->state($updates))->header('Cache-Control', 'no-store, private');
    }

    public function apply(Request $request, UpdateManager $updates): JsonResponse
    {
        Gate::authorize('manage-updates');

        $data = $request->validate([
            'version' => ['required', 'string', 'max:40', 'regex:'.Release::VERSION_PATTERN],
        ]);

        try {
            $updates->request($data['version'], $request->user());
        } catch (UpdateException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['message' => 'Mise à jour programmée : elle démarre dans la minute.']);
    }

    /**
     * Annule une demande que la tâche planifiée n'a pas encore prise en charge.
     */
    public function cancel(Request $request, UpdateManager $updates): JsonResponse
    {
        Gate::authorize('manage-updates');

        if (! $updates->cancel($request->user())) {
            return response()->json(['message' => 'Aucune demande en attente : la mise à jour a peut-être déjà démarré.'], 409);
        }

        return response()->json(['message' => 'Demande de mise à jour annulée.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(UpdateManager $updates): array
    {
        // Demande jamais prise en charge ou script jamais démarré : échec affiché, pas d'attente sans fin.
        $updates->expireStalled();

        $status = $updates->status();
        $schedulerLastRun = $updates->schedulerLastRun();
        $pending = $updates->pendingRequest();
        $userIds = array_filter([(int) ($status['requested_by_id'] ?? 0), (int) ($pending['requested_by_id'] ?? 0)]);
        $names = $userIds === [] ? collect() : User::query()->whereKey($userIds)->pluck('name', 'id');

        return [
            'installed' => $updates->installedVersion(),
            'deployment' => $updates->deployment(),
            'channel' => [
                'enabled' => (bool) config('techindex.updates.enabled'),
                'configured' => $updates->channel()->isConfigured(),
                'repository' => $updates->channel()->repository(),
            ],
            'check' => $updates->lastCheck(),
            'can_apply' => $updates->canApplyFromInterface(),
            // La tâche planifiée lance les mises à jour demandées : son absence explique une attente.
            'scheduler' => [
                'last_run' => $schedulerLastRun === null ? null : date(DATE_ATOM, $schedulerLastRun),
                'running' => $updates->schedulerRunning(),
            ],
            'stale_after' => UpdateManager::STALE_REQUEST_SECONDS,
            'pending' => $pending === null ? null : [
                'version' => (string) ($pending['version'] ?? ''),
                'requested_at' => $pending['requested_at'] ?? null,
                'requested_by' => $names[(int) ($pending['requested_by_id'] ?? 0)] ?? null,
            ],
            'status' => [
                'state' => (string) $status['state'],
                'version' => $status['version'] ?? null,
                'from' => $status['from'] ?? null,
                'step' => $status['step'] ?? null,
                'message' => $status['message'] ?? null,
                'source' => $status['source'] ?? null,
                'started_at' => $status['started_at'] ?? null,
                'finished_at' => $status['finished_at'] ?? null,
                'requested_by' => $names[(int) ($status['requested_by_id'] ?? 0)] ?? null,
            ],
            'log' => $updates->logTail(),
        ];
    }
}
