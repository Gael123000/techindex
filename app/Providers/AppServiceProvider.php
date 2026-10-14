<?php

namespace App\Providers;

use App\Actions\Passkeys\GenerateRegistrationOptions;
use App\Auth\ActiveUserProvider;
use App\Enums\ClientPermission;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Credential;
use App\Models\CustomField;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Equipment;
use App\Models\EquipmentLink;
use App\Models\EquipmentType;
use App\Models\FileFolder;
use App\Models\Import;
use App\Models\License;
use App\Models\Passkey;
use App\Models\Service;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Tag;
use App\Models\User;
use App\Models\VaultFolder;
use App\Models\Vlan;
use App\Policies\ClientPolicy;
use App\Policies\ClientResourcePolicy;
use App\Policies\CredentialPolicy;
use App\Policies\ImportPolicy;
use App\Policies\UserPolicy;
use App\Secrets\Contracts\SecretVault;
use App\Secrets\Keyring;
use App\Secrets\LocalSecretVault;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuthEventSubscriber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions as BaseGenerateRegistrationOptions;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AuditLogger::class);

        // Clés d'accès : tout type d'authentificateur, vérification de l'utilisateur exigée.
        $this->app->bind(BaseGenerateRegistrationOptions::class, GenerateRegistrationOptions::class);

        $this->app->singleton(Keyring::class, fn (): Keyring => Keyring::fromConfig());

        $this->app->singleton(SecretVault::class, function ($app): SecretVault {
            return match (config('techindex.secrets.driver')) {
                'local' => new LocalSecretVault($app->make(Keyring::class), config('techindex.secrets.cipher')),
                default => throw new InvalidArgumentException('Pilote de coffre inconnu : '.config('techindex.secrets.driver')),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureModels();
        $this->configureAuthorization();
        $this->configureRateLimiting();
        $this->configurePasswords();

        Auth::provider('active-eloquent', fn ($app, array $config): ActiveUserProvider => new ActiveUserProvider($app['hash'], $config['model']));

        // Clés d'accès : modèle applicatif (clé publique jamais sérialisée).
        Passkeys::usePasskeyModel(Passkey::class);

        Event::subscribe(AuthEventSubscriber::class);

        // Reverse proxy situé devant Nginx (répartiteur de charge, terminaison TLS externe).
        if (($proxies = config('techindex.trusted_proxies')) !== []) {
            TrustProxies::at($proxies);
        }

        Vite::useCspNonce();
    }

    private function configureModels(): void
    {
        // Les alias stockés dans les colonnes polymorphes (tags, pièces
        // jointes, audit) sont indépendants des noms de classes PHP.
        Relation::enforceMorphMap([
            'user' => User::class,
            'client' => Client::class,
            'site' => Site::class,
            'contact' => Contact::class,
            'equipment' => Equipment::class,
            'equipment_link' => EquipmentLink::class,
            'equipment_type' => EquipmentType::class,
            'custom_field' => CustomField::class,
            'vlan' => Vlan::class,
            'subnet' => Subnet::class,
            'service' => Service::class,
            'license' => License::class,
            'certificate' => Certificate::class,
            'credential' => Credential::class,
            'document' => Document::class,
            'document_category' => DocumentCategory::class,
            'attachment' => Attachment::class,
            'vault_folder' => VaultFolder::class,
            'file_folder' => FileFolder::class,
            'passkey' => Passkey::class,
            'tag' => Tag::class,
            'import' => Import::class,
            'audit_log' => AuditLog::class,
        ]);

        // Signale les chargements paresseux et attributs inexistants en développement.
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    private function configureAuthorization(): void
    {
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Credential::class, CredentialPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Import::class, ImportPolicy::class);

        foreach ([Site::class, Contact::class, Equipment::class, EquipmentLink::class, Vlan::class, Subnet::class, Service::class, License::class, Certificate::class, Document::class, Attachment::class, VaultFolder::class, FileFolder::class] as $model) {
            Gate::policy($model, ClientResourcePolicy::class);
        }

        Gate::define('manage-users', fn (User $user): bool => $user->is_active && $user->isAdmin());
        Gate::define('manage-settings', fn (User $user): bool => $user->is_active && $user->isAdmin());
        Gate::define('manage-updates', fn (User $user): bool => $user->is_active && $user->isAdmin());
        Gate::define('create-clients', fn (User $user): bool => $user->canCreateClients());

        // Journal global : administrateur, ou responsable ayant le droit de
        // gestion sur au moins un client (filtré ensuite par client).
        Gate::define('view-audit-log', fn (User $user): bool => $user->is_active
            && ($user->isAdmin() || $user->clientIdsWith(ClientPermission::Manage) !== []));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('login|'.$email.'|'.$request->ip())->response(
                    fn (Request $request, array $headers) => $this->lockoutResponse($request, $headers),
                ),
                Limit::perMinute(30)->by('login-ip|'.$request->ip())->response(
                    fn (Request $request, array $headers) => $this->lockoutResponse($request, $headers),
                ),
            ];
        });

        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('two-factor|'.$request->session()->get('login.id').'|'.$request->ip()));

        RateLimiter::for('two-factor-setup', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('two-factor-setup|'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('password-reset', fn (Request $request): Limit => Limit::perMinute(3)->by('reset|'.$request->ip()));

        RateLimiter::for('password-confirmation', fn (Request $request): Limit => Limit::perMinute(
            (int) config('techindex.security.password_confirmation_per_minute', 5),
        )->by('confirm|'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('secrets', fn (Request $request): Limit => Limit::perMinute(
            (int) config('techindex.secrets.reveal_per_minute', 20),
        )->by('secrets|'.$request->user()?->getAuthIdentifier()));

        // Cérémonies WebAuthn (déverrouillage du coffre, confirmation, enregistrement).
        RateLimiter::for('passkeys', fn (Request $request): Limit => Limit::perMinute(
            (int) config('techindex.vault.unlock_per_minute', 10),
        )->by('passkeys|'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('uploads', fn (Request $request): Limit => Limit::perMinute(
            (int) config('techindex.attachments.uploads_per_minute', 120),
        )->by('uploads|'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('exports', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('exports|'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('update-checks', fn (Request $request): Limit => Limit::perMinute(6)
            ->by('updates|'.$request->user()?->getAuthIdentifier()));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function lockoutResponse(Request $request, array $headers): mixed
    {
        app(AuditLogger::class)->log('auth.lockout', metadata: [
            'email' => mb_strtolower((string) $request->input('email')),
        ]);

        $seconds = (int) ($headers['Retry-After'] ?? 60);

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)])]);
    }

    private function configurePasswords(): void
    {
        // Mots de passe de connexion à TechIndex (hachés). Sans rapport avec
        // les secrets clients, qui sont chiffrés de façon réversible.
        Password::defaults(function (): Password {
            $rule = Password::min(12)->letters()->mixedCase()->numbers()->max(255);

            return config('techindex.security.check_pwned_passwords') ? $rule->uncompromised() : $rule;
        });
    }
}
