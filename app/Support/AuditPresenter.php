<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Met en forme une entrée d'historique pour l'interface (libellés français,
 * lien vers la ressource, liste lisible des changements).
 */
final class AuditPresenter
{
    private const ACTIONS = [
        'created' => 'Création',
        'updated' => 'Modification',
        'archived' => 'Archivage',
        'restored' => 'Restauration',
        'deleted' => 'Suppression',
    ];

    private const EVENTS = [
        'auth.login' => 'Connexion',
        'auth.failed' => 'Échec de connexion',
        'auth.logout' => 'Déconnexion',
        'auth.lockout' => 'Blocage temporaire après trop de tentatives',
        'auth.password_reset' => 'Réinitialisation du mot de passe',
        'auth.password_changed' => 'Changement du mot de passe',
        'auth.two_factor_enabled' => 'Double authentification activée',
        'auth.two_factor_disabled' => 'Double authentification désactivée',
        'auth.two_factor_failed' => 'Code de double authentification invalide',
        'auth.recovery_codes_generated' => 'Codes de récupération régénérés',
        'auth.recovery_code_used' => 'Code de récupération utilisé',
        'auth.sessions_revoked' => 'Sessions fermées',
        'auth.passkey_added' => 'Clé d\'accès ajoutée',
        'auth.passkey_removed' => 'Clé d\'accès supprimée',
        'auth.passkey_used' => 'Vérification par clé d\'accès',
        'auth.passkey_failed' => 'Échec de vérification par clé d\'accès',
        'auth.passkeys_reset' => 'Clés d\'accès réinitialisées',
        'vault.unlocked' => 'Déverrouillage des mots de passe',
        'vault.unlock_failed' => 'Échec de déverrouillage des mots de passe',
        'user.role_changed' => 'Changement de rôle',
        'user.deactivated' => 'Désactivation du compte',
        'user.reactivated' => 'Réactivation du compte',
        'user.reset_link_sent' => 'Envoi d\'un lien de réinitialisation',
        'access.granted' => 'Accès accordé à un client',
        'access.updated' => 'Droits modifiés sur un client',
        'access.revoked' => 'Accès retiré à un client',
        'credential.secret_revealed' => 'Révélation d\'un secret',
        'credential.secret_copied' => 'Copie d\'un secret',
        'secrets.rotated' => 'Rotation de la clé des secrets',
        'document.verified' => 'Vérification de la fiche',
        'record_type.layout_updated' => 'Modification de la disposition d\'une fiche',
        'internet_link.converted' => 'Conversion d\'un service « Connexion Internet » en lien FAI',
        'attachment.downloaded' => 'Téléchargement d\'une pièce jointe',
        'import.completed' => 'Import réalisé',
        'import.failed' => 'Import en échec',
        'export.generated' => 'Export de données',
        'demo.loaded' => 'Chargement des données de démonstration',
        'update.requested' => 'Demande de mise à jour de l\'application',
        'update.cancelled' => 'Demande de mise à jour annulée',
        'update.completed' => 'Mise à jour de l\'application',
        'update.failed' => 'Échec de la mise à jour de l\'application',
        'integration.synced' => 'Synchronisation d\'une intégration',
        'integration.licenses_synced' => 'Licences synchronisées par une intégration',
        'integration.tested' => 'Test de connexion d\'une intégration',
        'integration.account_linked' => 'Compte d\'une intégration rattaché à un client',
        'integration.account_unlinked' => 'Compte d\'une intégration détaché',
        'integration.account_added' => 'Compte ajouté à une intégration',
        'integration.account_removed' => 'Compte retiré d\'une intégration',
    ];

    private const TYPES = [
        'client' => 'Client',
        'site' => 'Site',
        'contact' => 'Contact',
        'equipment' => 'Équipement',
        'equipment_link' => 'Relation entre équipements',
        'equipment_type' => 'Type d\'équipement',
        'custom_field' => 'Champ personnalisé',
        'vlan' => 'VLAN',
        'subnet' => 'Sous-réseau',
        'service' => 'Service',
        'isp' => 'Opérateur (FAI)',
        'internet_link' => 'Lien Internet (FAI)',
        'record_type' => 'Fiche (constructeur)',
        'record_field' => 'Champ de fiche',
        'custom_record' => 'Fiche personnalisée',
        'license' => 'Licence',
        'certificate' => 'Certificat',
        'credential' => 'Accès',
        'document' => 'Document',
        'document_category' => 'Catégorie de documents',
        'attachment' => 'Pièce jointe',
        'vault_folder' => 'Dossier de mots de passe',
        'file_folder' => 'Dossier de fichiers',
        'passkey' => 'Clé d\'accès',
        'tag' => 'Étiquette',
        'user' => 'Utilisateur',
        'import' => 'Import',
        'integration' => 'Intégration',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function present(AuditLog $log, ?User $viewer = null): array
    {
        return [
            'id' => $log->id,
            'event' => $log->event,
            'event_label' => self::eventLabel($log->event),
            'subject_type' => $log->subject_type,
            'subject_type_label' => $log->subject_type === null ? null : (self::TYPES[$log->subject_type] ?? $log->subject_type),
            'subject_id' => $log->subject_id,
            'subject_label' => $log->subject_label,
            'subject_url' => self::subjectUrl($log, $viewer),
            'client' => $log->relationLoaded('client') && $log->client !== null
                ? ['id' => $log->client->id, 'name' => $log->client->name]
                : null,
            'user' => $log->relationLoaded('user') && $log->user !== null
                ? ['id' => $log->user->id, 'name' => $log->user->name]
                : null,
            'changes' => collect($log->changes ?? [])
                ->map(fn (mixed $change, string $field): array => [
                    'field' => $field,
                    'label' => FieldLabels::label($field),
                    'old' => self::stringify(is_array($change) ? ($change['old'] ?? null) : null),
                    'new' => self::stringify(is_array($change) ? ($change['new'] ?? null) : $change),
                ])
                ->values()
                ->all(),
            'metadata' => $log->metadata,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    public static function eventLabel(string $event): string
    {
        if (isset(self::EVENTS[$event])) {
            return self::EVENTS[$event];
        }

        $action = Str::afterLast($event, '.');

        return self::ACTIONS[$action] ?? Str::ucfirst(str_replace(['.', '_'], ' ', $event));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function typeOptions(): array
    {
        return collect(self::TYPES)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all();
    }

    private static function subjectUrl(AuditLog $log, ?User $viewer): ?string
    {
        if ($log->subject_id === null || str_ends_with($log->event, '.deleted')) {
            return null;
        }

        $client = $log->client_id;
        $id = $log->subject_id;

        return match ($log->subject_type) {
            'client' => route('clients.show', $id),
            'site' => $client ? route('clients.sites.show', [$client, $id]) : null,
            'equipment' => $client ? route('clients.equipment.show', [$client, $id]) : null,
            'service' => $client ? route('clients.services.show', [$client, $id]) : null,
            'internet_link' => $client ? route('clients.internet-links.show', [$client, $id]) : null,
            'custom_record' => $client ? route('clients.records.show', [$client, $id]) : null,
            'record_type' => $viewer?->isAdmin() ? route('admin.record-types.show', $id) : null,
            'isp' => $viewer?->isAdmin() ? route('admin.isps.index') : null,
            'license' => $client ? route('clients.licenses.show', [$client, $id]) : null,
            'certificate' => $client ? route('clients.certificates.show', [$client, $id]) : null,
            'document' => $client ? route('clients.documents.show', [$client, $id]) : null,
            'credential' => $client ? route('clients.credentials.index', $client) : null,
            'vault_folder' => $client ? route('vault.clients.show', [$client, 'folder' => $id]) : null,
            'file_folder' => $client ? route('files.clients.show', [$client, 'folder' => $id]) : null,
            'attachment' => $client ? route('files.clients.show', $client) : null,
            'contact' => $client ? route('clients.contacts.index', $client) : null,
            'vlan', 'subnet' => $client ? route('clients.network.index', $client) : null,
            'user' => $viewer?->isAdmin() ? route('admin.users.edit', $id) : null,
            'import' => route('imports.show', $id),
            'integration' => $viewer?->isAdmin() ? route('admin.integrations.index') : null,
            default => null,
        };
    }

    private static function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'Oui' : 'Non',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
