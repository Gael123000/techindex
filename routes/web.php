<?php

use App\Http\Controllers\Admin\CustomFieldController;
use App\Http\Controllers\Admin\DocumentCategoryController;
use App\Http\Controllers\Admin\EquipmentTypeController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\Admin\UserAccessController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Client\AttachmentController;
use App\Http\Controllers\Client\CertificateController;
use App\Http\Controllers\Client\ContactController;
use App\Http\Controllers\Client\CredentialController;
use App\Http\Controllers\Client\DocumentController;
use App\Http\Controllers\Client\EquipmentController;
use App\Http\Controllers\Client\EquipmentLinkController;
use App\Http\Controllers\Client\ExportController as ClientExportController;
use App\Http\Controllers\Client\HistoryController;
use App\Http\Controllers\Client\LicenseController;
use App\Http\Controllers\Client\NetworkController;
use App\Http\Controllers\Client\ServiceController;
use App\Http\Controllers\Client\SiteController;
use App\Http\Controllers\Client\SubnetController;
use App\Http\Controllers\Client\VlanController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EquipmentIndexController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\Files\FileExplorerController;
use App\Http\Controllers\Files\FileFolderController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Security\ConfirmationStatusController;
use App\Http\Controllers\Security\PasskeyController;
use App\Http\Controllers\TagSuggestionController;
use App\Http\Controllers\Vault\VaultController;
use App\Http\Controllers\Vault\VaultCredentialController;
use App\Http\Controllers\Vault\VaultFolderController;
use App\Http\Controllers\Vault\VaultUnlockController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth', 'session.policy', 'account.setup'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/search', SearchController::class)->name('search');
    Route::get('/equipment', EquipmentIndexController::class)->name('equipment.index');
    Route::get('/tags/suggest', TagSuggestionController::class)->name('tags.suggest');

    // Profil et sécurité du compte.
    Route::get('/profile', [ProfileController::class, 'show'])->middleware('no-store')->name('profile.show');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile/sessions', [ProfileController::class, 'destroyOtherSessions'])->name('profile.sessions.destroy');
    Route::get('/security/confirmation-status', ConfirmationStatusController::class)->name('security.confirmation-status');

    // Clés d'accès du compte : ajout et suppression après confirmation de l'identité.
    Route::get('/profile/passkeys/options', [PasskeyController::class, 'options'])
        ->middleware(['no-store', 'throttle:passkeys', 'recently-confirmed:default'])
        ->name('profile.passkeys.options');
    Route::post('/profile/passkeys', [PasskeyController::class, 'store'])
        ->middleware(['no-store', 'throttle:passkeys', 'recently-confirmed:default'])
        ->name('profile.passkeys.store');
    Route::put('/profile/passkeys/{passkey}', [PasskeyController::class, 'update'])->name('profile.passkeys.update');
    Route::delete('/profile/passkeys/{passkey}', [PasskeyController::class, 'destroy'])
        ->middleware('password.confirm')
        ->name('profile.passkeys.destroy');

    // Gestionnaire de mots de passe : déverrouillé uniquement par clé d'accès.
    // Le contrôle d'accès au client (404) précède le contrôle du verrou.
    Route::prefix('vault')->name('vault.')->group(function (): void {
        Route::get('/unlock/options', [VaultUnlockController::class, 'options'])
            ->middleware(['no-store', 'throttle:passkeys'])
            ->name('unlock.options');
        Route::post('/unlock', [VaultUnlockController::class, 'store'])->middleware('throttle:passkeys')->name('unlock');
        Route::post('/lock', [VaultUnlockController::class, 'destroy'])->name('lock');
        Route::get('/status', [VaultUnlockController::class, 'status'])->middleware('vault.unlocked')->name('status');

        Route::get('/', [VaultController::class, 'index'])->middleware('vault.unlocked')->name('index');

        Route::prefix('clients/{client}')->name('clients.')->scopeBindings()->middleware(['can:view,client', 'vault.unlocked'])->group(function (): void {
            Route::get('/', [VaultController::class, 'show'])->name('show');
            Route::post('/folders', [VaultFolderController::class, 'store'])->name('folders.store');
            Route::put('/folders/{vaultFolder}', [VaultFolderController::class, 'update'])->name('folders.update');
            Route::delete('/folders/{vaultFolder}', [VaultFolderController::class, 'destroy'])->name('folders.destroy');
            Route::post('/credentials/move', [VaultCredentialController::class, 'move'])->name('credentials.move');
            Route::post('/credentials/{credential}/reveal', [VaultCredentialController::class, 'reveal'])
                ->middleware(['no-store', 'throttle:secrets'])
                ->name('credentials.reveal');
        });
    });

    // Explorateur de fichiers : un dossier racine par client accessible.
    Route::prefix('files')->name('files.')->group(function (): void {
        Route::get('/', [FileExplorerController::class, 'index'])->name('index');

        Route::prefix('clients/{client}')->name('clients.')->scopeBindings()->middleware('can:view,client')->group(function (): void {
            Route::get('/', [FileExplorerController::class, 'show'])->name('show');
            Route::post('/upload', [FileExplorerController::class, 'upload'])->middleware('throttle:uploads')->name('upload');
            Route::post('/move', [FileExplorerController::class, 'move'])->name('move');
            Route::put('/attachments/{attachment}', [FileExplorerController::class, 'update'])->name('attachments.update');
            Route::post('/folders', [FileFolderController::class, 'store'])->name('folders.store');
            Route::get('/folders/{fileFolder}/summary', [FileFolderController::class, 'summary'])->name('folders.summary');
            Route::put('/folders/{fileFolder}', [FileFolderController::class, 'update'])->name('folders.update');
            Route::delete('/folders/{fileFolder}', [FileFolderController::class, 'destroy'])->name('folders.destroy');
        });
    });

    // Clients.
    Route::resource('clients', ClientController::class);
    Route::post('/clients/{client}/archive', [ClientController::class, 'archive'])->name('clients.archive');
    Route::post('/clients/{client}/restore', [ClientController::class, 'restore'])->name('clients.restore');
    Route::get('/clients/{client}/logo', [ClientController::class, 'logo'])->name('clients.logo');

    // Ressources d'un client. Les liaisons sont restreintes au client de l'URL :
    // /clients/1/equipment/42 répond 404 si l'équipement 42 appartient à un autre client.
    // Le middleware "can:view,client" refuse (404) tout client non attribué avant
    // même d'atteindre le contrôleur ; les politiques affinent ensuite chaque action.
    Route::prefix('clients/{client}')->name('clients.')->scopeBindings()->middleware('can:view,client')->group(function (): void {
        Route::get('/history', HistoryController::class)->name('history');

        Route::resource('sites', SiteController::class);
        Route::post('/sites/{site}/archive', [SiteController::class, 'archive'])->name('sites.archive');
        Route::post('/sites/{site}/restore', [SiteController::class, 'restore'])->name('sites.restore');

        Route::resource('contacts', ContactController::class)->only(['index', 'store', 'update', 'destroy']);

        Route::resource('equipment', EquipmentController::class);
        Route::post('/equipment/{equipment}/archive', [EquipmentController::class, 'archive'])->name('equipment.archive');
        Route::post('/equipment-links', [EquipmentLinkController::class, 'store'])->name('equipment-links.store');
        Route::delete('/equipment-links/{equipmentLink}', [EquipmentLinkController::class, 'destroy'])->name('equipment-links.destroy');

        Route::get('/network', NetworkController::class)->name('network.index');
        Route::resource('vlans', VlanController::class)->only(['store', 'update', 'destroy']);
        Route::resource('subnets', SubnetController::class)->only(['store', 'update', 'destroy']);

        Route::resource('services', ServiceController::class);
        Route::post('/services/{service}/archive', [ServiceController::class, 'archive'])->name('services.archive');

        Route::resource('licenses', LicenseController::class);
        Route::resource('certificates', CertificateController::class);

        Route::resource('credentials', CredentialController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('/credentials/{credential}/reveal', [CredentialController::class, 'reveal'])
            ->middleware(['no-store', 'throttle:secrets', 'recently-confirmed:secrets'])
            ->name('credentials.reveal');

        Route::resource('documents', DocumentController::class);
        Route::post('/documents/{document}/verify', [DocumentController::class, 'verify'])->name('documents.verify');
        Route::get('/documents/{document}/versions/{version}', [DocumentController::class, 'version'])->name('documents.versions.show');

        Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
        Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
        Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

        Route::get('/export/{dataset}/{format}', ClientExportController::class)
            ->middleware('throttle:exports')
            ->whereIn('format', ['csv', 'xlsx'])
            ->name('export');
    });

    // Exports globaux (limités aux clients pour lesquels l'utilisateur a le droit d'export).
    Route::get('/exports/{dataset}/{format}', ExportController::class)
        ->middleware('throttle:exports')
        ->whereIn('dataset', ['clients', 'equipment'])
        ->whereIn('format', ['csv', 'xlsx'])
        ->name('exports.download');

    // Imports.
    Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
    Route::get('/imports/create', [ImportController::class, 'create'])->name('imports.create');
    Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
    Route::get('/imports/templates/{type}/{format}', [ImportController::class, 'template'])
        ->whereIn('type', ['clients', 'equipment'])
        ->whereIn('format', ['csv', 'xlsx'])
        ->name('imports.template');
    Route::get('/imports/{import}', [ImportController::class, 'show'])->name('imports.show');
    Route::get('/imports/{import}/report/{format}', [ImportController::class, 'report'])
        ->whereIn('format', ['csv', 'xlsx'])
        ->name('imports.report');
    Route::put('/imports/{import}/mapping', [ImportController::class, 'updateMapping'])->name('imports.mapping');
    Route::post('/imports/{import}/execute', [ImportController::class, 'execute'])->name('imports.execute');
    Route::delete('/imports/{import}', [ImportController::class, 'destroy'])->name('imports.destroy');

    // Journal d'audit global.
    Route::get('/audit', AuditLogController::class)->name('audit.index');

    // Administration.
    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
        Route::post('/users/{user}/revoke-sessions', [UserController::class, 'revokeSessions'])->name('users.revoke-sessions');
        Route::post('/users/{user}/reset-two-factor', [UserController::class, 'resetTwoFactor'])->name('users.reset-two-factor');
        Route::post('/users/{user}/send-reset-link', [UserController::class, 'sendResetLink'])->name('users.send-reset-link');
        Route::put('/users/{user}/access', [UserAccessController::class, 'update'])->name('users.access.update');

        Route::resource('equipment-types', EquipmentTypeController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('custom-fields', CustomFieldController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('document-categories', DocumentCategoryController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('tags', TagController::class)->only(['index', 'store', 'update', 'destroy']);

        // Mises à jour de l'application (installation : confirmation récente de l'identité).
        Route::get('/updates', [UpdateController::class, 'index'])->name('updates.index');
        Route::get('/updates/status', [UpdateController::class, 'status'])->name('updates.status');
        Route::post('/updates/check', [UpdateController::class, 'check'])->middleware('throttle:update-checks')->name('updates.check');
        Route::post('/updates/apply', [UpdateController::class, 'apply'])
            ->middleware(['throttle:update-checks', 'recently-confirmed:default'])
            ->name('updates.apply');
    });
});
