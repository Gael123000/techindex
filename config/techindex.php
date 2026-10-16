<?php

/*
|--------------------------------------------------------------------------
| Configuration propre à TechIndex
|--------------------------------------------------------------------------
|
| Toutes les valeurs sensibles ou dépendantes de l'installation proviennent
| des variables d'environnement (.env). Voir .env.example pour la description
| de chaque variable.
|
*/

$csv = static fn (?string $value): array => array_values(array_filter(array_map(
    'trim',
    explode(',', (string) $value),
), static fn (string $item): bool => $item !== ''));

// Canal officiel des versions (dépôt GitHub et clé publique de signature),
// renseigné par « techindex.cmd release-init » et versionné avec le code.
$channelFile = __DIR__.'/release-channel.json';
$channel = is_file($channelFile) ? (array) json_decode((string) file_get_contents($channelFile), true) : [];
$versionFile = dirname(__DIR__).'/VERSION';

return [

    'tagline' => 'Le référentiel technique de vos clients.',

    // Version installée (fichier VERSION, mis à jour à chaque publication).
    'version' => is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '0.0.0',

    // Adresses (ou plages CIDR) des reverse proxys placés devant Nginx, dont
    // les en-têtes X-Forwarded-* sont acceptés. Vide si Nginx reçoit
    // directement les connexions des navigateurs.
    'trusted_proxies' => $csv(env('TRUSTED_PROXIES', '')),

    /*
    |--------------------------------------------------------------------------
    | Sécurité des comptes et des sessions
    |--------------------------------------------------------------------------
    */

    'security' => [
        // Rôles pour lesquels l'authentification à deux facteurs est obligatoire
        // (admin, manager, technician, reader). Vide = facultative pour tous.
        'two_factor_required_roles' => $csv(env('TWO_FACTOR_REQUIRED_ROLES', '')),

        // Durée de vie absolue d'une session authentifiée, en minutes, quelle
        // que soit l'activité. Complète SESSION_LIFETIME (expiration d'inactivité).
        'session_absolute_lifetime' => (int) env('SESSION_ABSOLUTE_LIFETIME', 720),

        // En-tête Content-Security-Policy et HSTS.
        'csp_enabled' => (bool) env('CSP_ENABLED', true),
        'hsts_enabled' => (bool) env('HSTS_ENABLED', false),

        // Tentatives de confirmation du mot de passe par minute et par utilisateur.
        'password_confirmation_per_minute' => (int) env('PASSWORD_CONFIRMATION_PER_MINUTE', 5),

        // Vérifie que les nouveaux mots de passe n'apparaissent pas dans des
        // fuites connues (API Have I Been Pwned, k-anonymat : seuls les 5
        // premiers caractères du hachage SHA-1 sont transmis). Nécessite un
        // accès Internet sortant.
        'check_pwned_passwords' => (bool) env('PASSWORD_CHECK_PWNED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Coffre des secrets clients
    |--------------------------------------------------------------------------
    |
    | Les secrets (mots de passe d'équipements, clés d'API...) sont chiffrés
    | avec AES-256-GCM via l'Encrypter de Laravel, avec une clé dédiée,
    | distincte d'APP_KEY. Voir docs/secrets-et-cles.md.
    |
    */

    'secrets' => [
        // Pilote du coffre. "local" = chiffrement applicatif. Le contrat
        // App\Secrets\Contracts\SecretVault permet d'ajouter un coffre externe.
        'driver' => env('SECRETS_DRIVER', 'local'),

        // Clé courante au format "base64:..." (32 octets), ou chemin d'un
        // fichier la contenant (secret Docker par exemple).
        'key' => env('SECRETS_KEY'),
        'key_file' => env('SECRETS_KEY_FILE'),

        // Anciennes clés encore acceptées en déchiffrement pendant une rotation.
        'previous_keys' => $csv(env('SECRETS_PREVIOUS_KEYS', '')),

        'cipher' => 'aes-256-gcm',

        // Nombre maximal de révélations / copies par minute et par utilisateur.
        'reveal_per_minute' => (int) env('SECRETS_REVEAL_PER_MINUTE', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gestionnaire de mots de passe
    |--------------------------------------------------------------------------
    |
    | Le gestionnaire ne s'ouvre qu'après vérification d'une clé d'accès
    | (WebAuthn, avec vérification de l'utilisateur : biométrie ou code PIN).
    | Le même verrou protège l'affichage des secrets sur les fiches. Il se
    | reverrouille 5 minutes après son ouverture, même en cas d'activité
    | (durée fixe : App\Services\Vault\VaultLock::DURATION).
    |
    */

    'vault' => [
        // Tentatives de déverrouillage par minute et par utilisateur.
        'unlock_per_minute' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Échéances affichées au tableau de bord (en jours)
    |--------------------------------------------------------------------------
    */

    'alerts' => [
        'warranty_days' => (int) env('ALERT_WARRANTY_DAYS', 60),
        'license_days' => (int) env('ALERT_LICENSE_DAYS', 60),
        'certificate_days' => (int) env('ALERT_CERTIFICATE_DAYS', 30),
        'service_days' => (int) env('ALERT_SERVICE_DAYS', 60),
        'end_of_life_days' => (int) env('ALERT_END_OF_LIFE_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pièces jointes
    |--------------------------------------------------------------------------
    |
    | Les fichiers sont stockés sur un disque privé sous un nom généré par
    | l'application. Chaque extension autorisée est associée aux types MIME
    | acceptés après analyse du contenu du fichier.
    |
    */

    'attachments' => [
        'disk' => env('ATTACHMENTS_DISK', 'local'),
        'max_size_kb' => (int) env('ATTACHMENTS_MAX_SIZE_KB', 20480),

        'types' => [
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'svg' => ['image/svg+xml'],
            'txt' => ['text/plain'],
            'csv' => ['text/csv', 'text/plain', 'application/csv'],
            'doc' => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
            'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
            'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
            'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
            'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
            'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
            'vsd' => ['application/vnd.visio', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
            'vsdx' => ['application/vnd.ms-visio.drawing.main+xml', 'application/vnd.ms-visio.drawing', 'application/zip'],
            'drawio' => ['text/xml', 'application/xml', 'text/plain'],
        ],

        // Types pouvant être affichés dans le navigateur (aperçu). Tous les
        // autres sont systématiquement servis en téléchargement.
        'inline' => ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'],

        // Explorateur de fichiers : téléversements par minute et par
        // utilisateur (un dossier importé produit un envoi par fichier).
        'uploads_per_minute' => (int) env('ATTACHMENTS_UPLOADS_PER_MINUTE', 120),
    ],

    'logos' => [
        'max_size_kb' => (int) env('LOGO_MAX_SIZE_KB', 2048),
    ],

    /*
    |--------------------------------------------------------------------------
    | Imports
    |--------------------------------------------------------------------------
    */

    'imports' => [
        'max_size_kb' => (int) env('IMPORT_MAX_SIZE_KB', 10240),
        'max_rows' => (int) env('IMPORT_MAX_ROWS', 5000),
        // Les fichiers importés sont supprimés après ce délai (jours).
        'retention_days' => (int) env('IMPORT_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentation
    |--------------------------------------------------------------------------
    */

    'documents' => [
        // Intervalle de vérification proposé par défaut pour une fiche (jours).
        'default_review_interval_days' => (int) env('DOCUMENT_REVIEW_INTERVAL_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Journal d'audit
    |--------------------------------------------------------------------------
    */

    'audit' => [
        // 0 = conservation illimitée. Sinon, purge planifiée au-delà de N jours.
        'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sauvegardes
    |--------------------------------------------------------------------------
    |
    | Voir docs/sauvegarde-restauration.md. Les clés de chiffrement ne sont
    | JAMAIS incluses dans une sauvegarde : elles se sauvegardent à part.
    |
    */

    'backups' => [
        'path' => env('BACKUP_PATH') ?: base_path('backups'),
        // Clé publique "age" (age1...) : les archives sont chiffrées pour ce
        // destinataire. La clé privée correspondante reste hors du serveur.
        'age_recipient' => env('BACKUP_AGE_RECIPIENT'),
        // Nombre d'archives conservées localement (0 = illimité).
        'keep' => (int) env('BACKUP_KEEP', 14),
        // Sauvegarde automatique par le planificateur : heure "HH:MM" (vide = désactivée).
        'daily_at' => env('BACKUP_DAILY_AT'),
        // Base utilisée par le test de restauration (backup-verify). Vide : la
        // base temporaire "<base>_restore_check" est créée puis supprimée, ce
        // qui exige le droit CREATE DATABASE. Renseignée : base existante et
        // vide, réservée à cet usage (hébergement Plesk), vidée après chaque test.
        'verify_database' => env('BACKUP_VERIFY_DATABASE') ?: null,
        'binaries' => [
            'mariadb_dump' => env('BACKUP_MARIADB_DUMP', 'mariadb-dump'),
            'mariadb' => env('BACKUP_MARIADB', 'mariadb'),
            // L'archive de déploiement Plesk embarque age dans bin/.
            'age' => env('BACKUP_AGE_BINARY') ?: (is_executable(base_path('bin/age')) ? base_path('bin/age') : 'age'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File d'attente
    |--------------------------------------------------------------------------
    |
    | Sans gestionnaire de processus (hébergement Plesk), le planificateur,
    | lancé chaque minute par une tâche cron, démarre aussi le traitement de
    | la file d'attente. Avec Docker, le service "worker" s'en charge.
    |
    */

    'queue' => [
        'work_from_scheduler' => (bool) env('QUEUE_WORK_FROM_SCHEDULER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mises à jour
    |--------------------------------------------------------------------------
    |
    | Les versions sont publiées sur GitHub avec un manifeste signé (Ed25519).
    | Une version n'est proposée, et installée, que si sa signature correspond
    | à la clé publique livrée avec le code. Voir docs/publication-et-mises-a-jour.md.
    |
    */

    'updates' => [
        // Vérification depuis l'administration et contrôle quotidien (accès sortant à github.com).
        'enabled' => (bool) env('UPDATE_CHECK_ENABLED', true),
        'repository' => env('UPDATE_REPOSITORY') ?: (string) ($channel['repository'] ?? ''),
        'public_key' => env('UPDATE_PUBLIC_KEY') ?: (string) ($channel['public_key'] ?? ''),
        // Miroir éventuel ; les fichiers sont lus sous <base>/<dépôt>/releases/...
        'base_url' => rtrim((string) (env('UPDATE_BASE_URL') ?: 'https://github.com'), '/'),
        // Mode d'installation : plesk (mise à jour possible depuis l'interface),
        // docker (commande sur le serveur) ou manual (développement).
        'deployment' => env('TECHINDEX_DEPLOYMENT', 'manual'),
        // Délai (secondes) laissé au script lancé depuis l'administration pour signaler son démarrage.
        'start_timeout' => (int) env('UPDATE_START_TIMEOUT', 15),
    ],

];
