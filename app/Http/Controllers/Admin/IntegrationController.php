<?php

namespace App\Http\Controllers\Admin;

use App\Enums\IntegrationFrequency;
use App\Enums\IntegrationRunStatus;
use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SyncIntegration;
use App\Models\Client;
use App\Models\Integration;
use App\Models\IntegrationAccount;
use App\Models\IntegrationRun;
use App\Secrets\IntegrationSecrets;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\AccountMatcher;
use App\Services\Integrations\Contracts\VerifiesAccounts;
use App\Services\Integrations\IntegrationException;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Integrations\IntegrationSync;
use App\Services\Integrations\Providers\Microsoft365Provider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration des intégrations : catalogue, identifiants (chiffrés,
 * jamais réaffichés), test de connexion, synchronisation et rattachement
 * des comptes externes aux clients.
 */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly IntegrationRegistry $registry,
        private readonly IntegrationSecrets $secrets,
    ) {}

    public function index(): Response
    {
        Gate::authorize('manage-settings');

        $configured = Integration::query()->withCount([
            'accounts',
            'accounts as linked_accounts_count' => fn ($query) => $query->whereNotNull('client_id'),
        ])->get()->keyBy('provider');

        return Inertia::render('Admin/Integrations/Index', [
            'providers' => collect($this->registry->all())->map(function ($provider) use ($configured): array {
                $definition = $provider->definition();
                $integration = $configured->get($definition->key);

                return [
                    ...collect($definition->toArray())->only(['key', 'label', 'vendor', 'category', 'category_label', 'description', 'initials', 'color', 'beta', 'account_label'])->all(),
                    'configured' => $integration !== null && $integration->hasCredentials(),
                    'enabled' => (bool) $integration?->is_enabled,
                    'last_synced_at' => $integration?->last_synced_at?->toIso8601String(),
                    'last_status' => $integration?->last_status?->value,
                    'last_status_label' => $integration?->last_status?->label(),
                    'accounts_count' => (int) ($integration?->accounts_count ?? 0),
                    'linked_accounts_count' => (int) ($integration?->linked_accounts_count ?? 0),
                ];
            })->values(),
        ]);
    }

    public function show(string $provider, AccountMatcher $matcher): Response
    {
        Gate::authorize('manage-settings');

        $implementation = $this->registry->get($provider);
        $definition = $implementation->definition();
        $integration = Integration::query()->where('provider', $provider)->first();

        $accounts = $integration?->accounts()->with('client:id,name')->orderBy('is_ignored')->orderBy('name')->get() ?? collect();

        return Inertia::render('Admin/Integrations/Show', [
            'definition' => $definition->toArray(),
            'integration' => [
                'exists' => $integration !== null,
                'is_enabled' => (bool) $integration?->is_enabled,
                'frequency' => $integration?->frequency->value ?? IntegrationFrequency::Daily->value,
                'settings' => (object) ($integration?->settings ?? []),
                // Champs renseignés seulement : les valeurs ne quittent jamais le serveur.
                'configured_credentials' => $integration === null ? [] : $this->secrets->configuredKeys($integration),
                'credentials_updated_at' => $integration?->credentials_updated_at?->toIso8601String(),
                'last_synced_at' => $integration?->last_synced_at?->toIso8601String(),
                'last_status' => $integration?->last_status?->value,
                'last_status_label' => $integration?->last_status?->label(),
                'last_message' => $integration?->last_message,
            ],
            'accounts' => $accounts->map(fn (IntegrationAccount $account): array => [
                ...$account->only(['id', 'external_id', 'name', 'domain', 'is_ignored', 'is_manual', 'last_error', 'licenses_count']),
                'metadata' => (object) ($account->metadata ?? []),
                'client' => $account->client?->only(['id', 'name']),
                'last_synced_at' => $account->last_synced_at?->toIso8601String(),
                'suggested_client_id' => $account->client_id === null && ! $account->is_ignored ? $matcher->suggest($account) : null,
            ]),
            'clients' => Client::query()
                ->where('status', RecordStatus::Active)
                ->orWhereIn('id', $accounts->pluck('client_id')->filter())
                ->orderBy('name')
                ->get(['id', 'name', 'reference'])
                ->map(fn (Client $client): array => ['value' => $client->id, 'label' => $client->name.' ('.$client->reference.')']),
            'runs' => $integration === null ? [] : $integration->runs()->with('user:id,name')->latest('id')->limit(10)->get()
                ->map(fn (IntegrationRun $run): array => [
                    'id' => $run->id,
                    'trigger' => $run->trigger,
                    'status' => $run->status->value,
                    'status_label' => $run->status->label(),
                    'stats' => (object) ($run->stats ?? []),
                    'message' => $run->message,
                    'user' => $run->user?->only(['id', 'name']),
                    'created_at' => $run->created_at?->toIso8601String(),
                    'finished_at' => $run->finished_at?->toIso8601String(),
                ]),
            'running' => $integration !== null && $integration->runs()
                ->whereIn('status', [IntegrationRunStatus::Queued, IntegrationRunStatus::Running])
                ->where('created_at', '>', now()->subHours(2))
                ->exists(),
            'frequencies' => IntegrationFrequency::options(),
            'manualAccounts' => $implementation instanceof VerifiesAccounts ? $implementation->accountInput() : null,
            'consent' => $implementation instanceof Microsoft365Provider ? [
                'redirect_uri' => route('admin.integrations.microsoft.callback'),
                'available' => filled($integration?->setting('client_id')) && (bool) $integration?->hasCredentials(),
            ] : null,
        ]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $definition = $this->registry->get($provider)->definition();
        $integration = Integration::query()->firstOrNew(['provider' => $provider]);
        $configured = $integration->exists ? $this->secrets->configuredKeys($integration) : [];

        $data = $request->validate([
            'is_enabled' => ['boolean'],
            'frequency' => ['required', Rule::enum(IntegrationFrequency::class)],
            'settings' => ['nullable', 'array'],
            'credentials' => ['nullable', 'array'],
            'clear_credentials' => ['nullable', 'array'],
            'clear_credentials.*' => ['string', Rule::in(array_column($definition->credentials, 'key'))],
            ...$definition->settingRules(),
            ...$definition->credentialRules($configured),
        ], [], $definition->attributeNames());

        $settings = [];

        foreach ($definition->settings as $field) {
            $value = $data['settings'][$field['key']] ?? null;
            $settings[$field['key']] = is_string($value) ? trim($value) : null;
        }

        DB::transaction(function () use ($integration, $data, $settings, $definition): void {
            $integration->fill([
                'is_enabled' => (bool) ($data['is_enabled'] ?? false),
                'frequency' => $data['frequency'],
                'settings' => array_filter($settings, fn (?string $value): bool => $value !== null && $value !== ''),
            ]);

            $credentials = array_intersect_key($data['credentials'] ?? [], array_flip(array_column($definition->credentials, 'key')));
            $this->secrets->apply($integration, $credentials, $data['clear_credentials'] ?? []);
            $integration->save();
            $integration->flushPendingAudit();
        });

        if ($integration->is_enabled && ! $integration->hasCredentials()) {
            $integration->forceFill(['is_enabled' => false])->save();

            return back()->with('error', 'Configuration enregistrée, mais l\'intégration reste désactivée : renseignez ses identifiants.');
        }

        return back()->with('success', 'Configuration de l\'intégration enregistrée.');
    }

    public function test(string $provider, IntegrationSync $sync, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $implementation = $this->registry->get($provider);
        $integration = Integration::query()->where('provider', $provider)->firstOrFail();

        try {
            $message = $implementation->testConnection($sync->context($integration));
            $audit->log('integration.tested', $integration, metadata: ['résultat' => 'réussi'], label: $implementation->definition()->label);

            return back()->with('success', $message);
        } catch (IntegrationException $exception) {
            $audit->log('integration.tested', $integration, metadata: ['résultat' => 'échec'], label: $implementation->definition()->label);

            return back()->with('error', $exception->getMessage());
        }
    }

    public function sync(Request $request, string $provider, IntegrationSync $sync): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $this->registry->get($provider);
        $integration = Integration::query()->where('provider', $provider)->firstOrFail();

        if (! $integration->is_enabled || ! $integration->hasCredentials()) {
            return back()->with('error', 'Activez l\'intégration et renseignez ses identifiants avant de la synchroniser.');
        }

        $pending = $integration->runs()
            ->whereIn('status', [IntegrationRunStatus::Queued, IntegrationRunStatus::Running])
            ->where('created_at', '>', now()->subHours(2))
            ->exists();

        if ($pending) {
            return back()->with('error', 'Une synchronisation est déjà en cours.');
        }

        $run = $sync->start($integration, 'manual', $request->user());
        SyncIntegration::dispatch($run->id, $integration->id);

        return back()->with('success', 'Synchronisation lancée : les licences apparaîtront chez les clients rattachés dans quelques instants.');
    }

    public function destroy(string $provider): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $label = $this->registry->get($provider)->definition()->label;
        $integration = Integration::query()->where('provider', $provider)->firstOrFail();

        DB::transaction(function () use ($integration): void {
            // Les licences déjà remontées restent chez les clients, comme des licences saisies à la main.
            DB::table('licenses')
                ->whereIn('integration_account_id', $integration->accounts()->select('id'))
                ->update(['integration_account_id' => null, 'external_id' => null]);

            $integration->delete();
        });

        return redirect()->route('admin.integrations.index')->with('success', "Intégration {$label} supprimée. Les licences déjà importées sont conservées.");
    }
}
