<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Affectation des clients et droits (modification, export, secrets, gestion).
 * Chaque changement est journalisé avec l'ancien et le nouveau niveau de droits.
 */
class UserAccessController extends Controller
{
    private const FLAGS = ['can_edit', 'can_export', 'can_view_secrets', 'can_manage'];

    public function update(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('update', $user);

        $data = $request->validate([
            'access' => ['present', 'array'],
            'access.*.client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'access.*.enabled' => ['boolean'],
            'access.*.can_edit' => ['boolean'],
            'access.*.can_export' => ['boolean'],
            'access.*.can_view_secrets' => ['boolean'],
            'access.*.can_manage' => ['boolean'],
        ]);

        $existing = $user->assignments()->with('client:id,name')->get()->keyBy('client_id');
        $changes = 0;

        DB::transaction(function () use ($data, $user, $existing, $audit, &$changes): void {
            foreach ($data['access'] as $row) {
                $clientId = (int) $row['client_id'];
                $current = $existing->get($clientId);
                $enabled = (bool) ($row['enabled'] ?? false);
                $flags = collect(self::FLAGS)->mapWithKeys(fn (string $flag): array => [$flag => $enabled && (bool) ($row[$flag] ?? false)])->all();

                if (! $enabled && $current !== null) {
                    $before = $current->summary();
                    $current->delete();
                    $audit->log('access.revoked', $user, ['access' => ['old' => $before, 'new' => null]], clientId: $clientId);
                    $changes++;

                    continue;
                }

                if ($enabled && $current === null) {
                    $assignment = ClientAssignment::query()->create(['client_id' => $clientId, 'user_id' => $user->id, ...$flags]);
                    $audit->log('access.granted', $user, ['access' => ['old' => null, 'new' => $assignment->summary()]], clientId: $clientId);
                    $changes++;

                    continue;
                }

                if ($enabled && $current !== null) {
                    $before = $current->summary();
                    $current->fill($flags);

                    if ($current->isDirty()) {
                        $current->save();
                        $audit->log('access.updated', $user, ['access' => ['old' => $before, 'new' => $current->summary()]], clientId: $clientId);
                        $changes++;
                    }
                }
            }
        });

        $user->flushAccessCache();

        return back()->with('success', $changes > 0 ? "Droits mis à jour ({$changes} modification(s))." : 'Aucune modification des droits.');
    }
}
