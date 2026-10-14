# Architecture, modèle de données et choix techniques

## 1. Vue d'ensemble

TechIndex est un **monolithe Laravel** : une seule application, un seul dépôt,
une seule base. L'interface Vue est servie par Inertia, sans API publique
séparée. Ce choix limite la surface d'attaque et la complexité d'exploitation,
tout en gardant une organisation par domaines qui permettra d'extraire un
module plus tard si nécessaire.

```
Navigateur ──HTTPS──► Nginx (web) ──FastCGI──► PHP-FPM (app) ──► MariaDB
                         │                         ▲                ▲
              fichiers compilés              worker (file d'attente) │
              (public/build)                 scheduler (tâches) ─────┘
```

| Service     | Rôle |
|-------------|------|
| `web`       | Nginx : fichiers statiques versionnés, en-têtes TLS, relais vers PHP-FPM. Seul `public/index.php` est exécutable. |
| `app`       | PHP-FPM : application Laravel. |
| `worker`    | `queue:work` : imports, envoi des e-mails (file d'attente en base de données). |
| `scheduler` | `schedule:work` : purge, contrôle quotidien des clés, sauvegarde automatique (facultative). |
| `mariadb`   | Base de données ; jamais exposée publiquement. |
| `mailpit`   | Développement uniquement : boîte de réception de test. |
| `certbot`   | Production avec Let's Encrypt : renouvellement des certificats. |

Développement (`compose.yaml`) et production (`compose.prod.yaml`) utilisent
le même `Dockerfile` (cibles `dev`, `production` et `web`) et les mêmes
fichiers de configuration Nginx et PHP.

Sur un hébergement Plesk, sans Docker ([deploiement-plesk.md](deploiement-plesk.md)),
nginx, Apache et PHP-FPM de Plesk jouent les rôles de `web` et `app`. Une
tâche planifiée lance `schedule:run` chaque minute : le planificateur démarre
alors aussi le traitement de la file d'attente (`QUEUE_WORK_FROM_SCHEDULER`),
faute de service permanent. L'archive livrée est produite par la cible
`plesk-release` du même `Dockerfile`.

## 2. Organisation du code

```
app/
  Enums/                 Statuts, rôles, droits, catégories (valeurs et libellés français)
  Models/                Modèles Eloquent ; Concerns/ : BelongsToClient, Auditable, HasTags, TracksAuthors
  Policies/              Contrôle d'accès (ClientPolicy, ClientResourcePolicy, CredentialPolicy...)
  Http/
    Controllers/         Contrôleurs fins ; Client/ = ressources d'un client, Admin/ = paramétrage
    Requests/            Validation serveur (FormRequest)
    Middleware/          En-têtes de sécurité, politique de session, confirmation récente...
  Secrets/               Module des secrets (contrat SecretVault, coffre local, trousseau de clés)
  Services/
    Audit/               Journal d'audit, masquage des champs sensibles, événements d'authentification
    Auth/                Sessions, cérémonies WebAuthn des clés d'accès (PasskeyCeremony)
    Attachments/         Stockage privé, téléchargement contrôlé, explorateur de fichiers
    Folders/             Règles communes des arborescences de dossiers
    Vault/               Verrou du gestionnaire de mots de passe (VaultLock)
    Backup/              Sauvegarde, vérification, restauration
    Documents/           Filtrage HTML, gestion des versions
    Export/, Import/     Échanges CSV / Excel
    Inventory/           Équipements (interfaces, champs personnalisés, filtres)
    Search/              Recherche globale
    Spreadsheets/        Lecture / écriture CSV et XLSX (OpenSpout)
  Console/Commands/      Commandes techindex:* (administrateur, clés, sauvegardes, diagnostic)
resources/js/
  Pages/                 Une page Vue par écran (Inertia)
  Components/            Composants réutilisables (formulaires, tableaux, modales, secrets)
  Layouts/               AppLayout (menu, recherche, contexte client), AuthLayout
docker/                  Configuration Nginx, PHP, MariaDB et scripts des conteneurs
scripts/techindex.sh     Exploitation sous Linux (Docker)
scripts/techindex-plesk.sh  Déploiement et exploitation sur un hébergement Plesk (sans Docker)
scripts/release-tool.php    Manifeste et signature des versions (GitHub Actions, vérification Docker)
.github/workflows/       Tests à chaque envoi (ci.yml), publication signée des versions (release.yml)
techindex.ps1 / .cmd     Exploitation sous Windows
```

## 3. Modèle de données

Toutes les données métier sont rattachées à un **client** (`client_id`), ce
qui permet d'appliquer les droits dans chaque requête SQL.

```
users ──< client_user >── clients
                            ├──< sites ──< (rattachement facultatif des éléments ci-dessous)
                            ├──< contacts
                            ├──< equipment ──< network_interfaces >── subnets ──> vlans
                            │        ├──< custom_field_values >── custom_fields ──> equipment_types
                            │        └──< equipment_links (source → cible, type de lien)
                            ├──< services ──< equipment_service >── equipment
                            ├──< licenses ──< equipment_license >── equipment
                            ├──< certificates ──< certificate_equipment >── equipment
                            ├──< credentials (→ équipement ou service facultatif, → vault_folders facultatif)
                            ├──< vault_folders (arborescence : parent_id)
                            ├──< documents ──< document_versions
                            │        ├──< document_equipment, document_service
                            │        └──> document_categories
                            ├──< attachments (polymorphe : client, site, équipement, service, document...,
                            │                 → file_folders facultatif)
                            └──< file_folders (arborescence : parent_id)
users ──< passkeys
tags ──< taggables (polymorphe)          audit_logs (client_id facultatif)          imports
```

| Table | Contenu principal |
|-------|-------------------|
| `users` | Rôle, actif ou désactivé, mot de passe haché (bcrypt), secret TOTP et codes de secours chiffrés (`APP_KEY`), dernière connexion. |
| `client_user` | Affectation d'un utilisateur à un client avec les droits `can_edit`, `can_export`, `can_view_secrets` et `can_manage`. |
| `clients` | Nom, référence interne unique, logo, description, adresse, technicien référent, statut actif ou archivé, notes. |
| `sites` | Adresses et sites du client. |
| `contacts` | Contacts du client, éventuellement rattachés à un site. |
| `equipment` | Type, marque, modèle, numéro de série, statut (en service, maintenance, hors service, archivé), dates d'achat, de garantie et de fin de vie, fournisseur, URL d'administration. |
| `network_interfaces` | Plusieurs adresses IP et MAC par équipement (MAC normalisée pour la recherche), interface principale. |
| `equipment_links` | Liens typés entre équipements : héberge, protégé par, connecté à, dépend de, sauvegardé par, alimenté par. |
| `custom_fields`, `custom_field_values` | Champs personnalisés typés (texte, nombre, date, oui/non, liste, URL), stockés dans une colonne par type. |
| `vlans`, `subnets` | Réseaux du client (CIDR, passerelle, DHCP, VLAN). |
| `services` | Accès Internet, VPN, domaines et DNS, hébergement, cloud, messagerie, applications métier, sauvegardes. Les champs propres à chaque catégorie sont dans `details` (JSON validé côté serveur). |
| `licenses` | Licences, abonnements et contrats de support : quantité, fournisseur, échéance, renouvellement, coût. |
| `certificates` | Certificats : domaines, émetteur, dates, renouvellement automatique. |
| `credentials` | Accès : libellé, type, hôte, identifiant (métadonnées en clair), et `secret_ciphertext` chiffré avec l'identifiant de la clé utilisée. |
| `documents`, `document_versions` | Base de connaissances : HTML filtré, texte brut pour la recherche, responsable, dernière vérification, périodicité de revue, historique des versions. |
| `attachments` | Pièces jointes : nom d'origine, chemin de stockage généré (UUID), type MIME détecté, taille, empreinte SHA-256, dossier de l'explorateur. |
| `passkeys` | Clés d'accès (WebAuthn) d'un utilisateur : nom, identifiant de la clé, clé **publique** et compteur de signatures, dernière utilisation. |
| `vault_folders`, `file_folders` | Dossiers du gestionnaire de mots de passe et de l'explorateur, par client. La racine du client est implicite (parent nul) ; une colonne générée garantit l'unicité du nom parmi les dossiers frères, casse ignorée. |
| `audit_logs` | Action, auteur, client, objet, différences de champs non sensibles, adresse IP, navigateur. |
| `imports` | Imports CSV / Excel : correspondance des colonnes, mode, rapport ligne par ligne. |

Des index FULLTEXT (InnoDB) existent sur `clients`, `sites`, `equipment`,
`services` et `documents`. Aucun index ne porte sur `credentials` : les
secrets ne sont jamais indexés.

## 4. Sécurité : conception

### Contrôle d'accès

- **Rôles globaux** : Administrateur, Responsable technique, Technicien, Lecteur.
  Le rôle fixe les droits proposés par défaut lors d'une affectation et les
  fonctions transverses (gestion des utilisateurs, création de clients).
- **Droits par client** (`client_user`) : lecture (affectation), modification,
  export, secrets, gestion. L'administrateur a tous les droits sur tous les clients.
- **Politiques Laravel** sur chaque action. Un client non attribué répond
  « 404 introuvable », ce qui ne révèle pas son existence. Un client archivé
  est en lecture seule.
- **Liaisons imbriquées** (`scopeBindings`) : `/clients/1/equipment/42` est
  refusé si l'équipement 42 n'appartient pas au client 1.
- **Listes, recherche, tableau de bord et exports** sont filtrés dans la
  requête SQL (`visibleTo`), et pas après coup.
- **Masquer un bouton n'est jamais un contrôle** : chaque route vérifie les droits.

### Secrets clients

Ce module est détaillé dans [secrets-et-cles.md](secrets-et-cles.md).

- Le chiffrement authentifié AES-256-GCM s'appuie sur l'`Encrypter` de Laravel
  (OpenSSL), sans cryptographie maison.
- La clé `SECRETS_KEY` est dédiée et distincte d'`APP_KEY`. Elle peut être lue
  depuis un fichier, et un trousseau gère la rotation.
- Tout passe par `App\Secrets\CredentialSecrets`, seul point de lecture et
  d'écriture. Le contrat `SecretVault` permet de brancher plus tard un coffre
  externe (HashiCorp Vault, Azure Key Vault...) sans toucher aux contrôleurs.
- Une révélation passe par :
  - une requête POST dédiée ;
  - le droit « secrets » ;
  - une confirmation du mot de passe datant de moins de 10 minutes ;
  - une limitation du débit ;
  - un en-tête `Cache-Control: no-store` ;
  - une entrée d'audit sans la valeur.
- Le secret n'est jamais présent dans les pages, les journaux, la recherche,
  les exports, l'historique ni les sauvegardes en clair. Les sauvegardes
  contiennent les valeurs chiffrées, sans la clé.

### Authentification

- Laravel Fortify : pas d'inscription publique, mots de passe hachés (bcrypt)
  avec une politique d'au moins 12 caractères.
- Double authentification TOTP avec codes de secours et/ou clé d'accès
  (WebAuthn, `laravel/passkeys` fourni avec Fortify), obligatoire selon le rôle.
  Une clé d'accès est toujours un second facteur, après le mot de passe ; la
  vérification de l'utilisateur (biométrie, code PIN) est exigée. Les routes
  du paquet ne sont pas utilisées : TechIndex déclare les siennes, avec un défi
  à usage unique propre à chaque usage (connexion, confirmation, coffre).
- Gestionnaire de mots de passe : déverrouillé uniquement par clé d'accès,
  reverrouillé après inactivité et au bout d'une durée maximale
  (middleware `vault.unlocked`, détails dans [secrets-et-cles.md](secrets-et-cles.md)).
- Limitation des tentatives de connexion, de double authentification et de
  réinitialisation.
- Sessions chiffrées en base, avec une durée d'inactivité et une durée absolue.
- La désactivation d'un utilisateur supprime ses sessions et bloque la
  connexion. Changer de mot de passe révoque les autres sessions.
- Les comptes sont créés avec un mot de passe saisi ou généré aléatoirement,
  à changer à la première connexion.

### Protection web

- CSRF : jeton vérifié sur toute requête modifiante.
- XSS :
  - échappement Vue par défaut ;
  - HTML des documents filtré par liste blanche (`symfony/html-sanitizer`) à
    l'enregistrement et à l'affichage ;
  - CSP à nonce, sans `unsafe-eval`.
- Injection SQL : requêtes paramétrées (Eloquent / Query Builder) ; la
  recherche plein texte échappe les opérateurs booléens.
- Affectation de masse : attributs `#[Fillable]` explicites ; les données
  validées (`$request->validated()`) sont seules utilisées.
- En-têtes : `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
  `Permissions-Policy`, HSTS en HTTPS.
- Hôte : en production, seules les requêtes adressées au domaine d'`APP_URL`
  sont acceptées.
- Erreurs : page générique sans détail technique quand `APP_DEBUG=false`.
- Pièces jointes :
  - stockées hors de la racine web, sous un nom aléatoire ;
  - type validé sur le contenu réel (`finfo`) avec une liste blanche
    d'extensions et de types ;
  - taille limitée ;
  - téléchargement contrôlé, `Content-Disposition: attachment` sauf pour les
    images et PDF sûrs, et `nosniff`.

### Audit

`audit_logs` enregistre :

- les connexions (réussies, échouées, verrouillées) et les déconnexions ;
- les créations, modifications, archivages et suppressions, avec les
  différences de champs ;
- les changements de rôle et de droits ;
- les révélations et copies de secrets ;
- les imports, exports, chargements de démonstration et restaurations.

Le `Redactor` remplace toute valeur de champ sensible (mot de passe, secret,
jeton, clé...) par « [masqué] » avant l'écriture.

### Mises à jour

Détail dans [publication-et-mises-a-jour.md](publication-et-mises-a-jour.md).

- Les versions sont construites par GitHub Actions après la réussite de tous
  les contrôles, puis publiées avec un manifeste `release.json` (version,
  commit, empreinte SHA-256 de l'archive, migrations) signé en Ed25519.
- La clé publique est livrée avec le code (`config/release-channel.json`) ;
  la clé privée n'est connue que du développeur et du secret GitHub. Une
  installation vérifie chaque version avec la clé de la version qu'elle a
  déjà, ce qui permet de publier une nouvelle clé.
- `App\Services\Updates` : `ReleaseChannel` lit et vérifie le manifeste,
  `UpdateManager` mémorise la recherche, enregistre la demande d'installation
  et l'avancement, `UpdateLauncher` lance le script de déploiement détaché.
- Le processus web ne modifie jamais le code : sur Plesk, il dépose une
  demande que la tâche planifiée (`techindex:update-dispatch`) confie à
  `techindex-plesk.sh`, qui vérifie de nouveau la signature avant d'installer.

## 5. Recherche

La recherche globale utilise uniquement MariaDB (pas de moteur externe) :

- FULLTEXT en mode booléen, avec préfixes, sur les noms, descriptions, notes
  et le texte des documents ;
- `LIKE` préfixe sur les identifiants : référence, numéro de série, modèle,
  adresse IP (y compris partielle), adresse MAC (avec ou sans séparateurs),
  étiquettes.

Les droits sont appliqués dans la requête : un résultat d'un client non
attribué n'est jamais lu. Les résultats peuvent être filtrés par client, site,
type et statut.

## 6. Choix techniques et versions

Les versions sont figées à trois niveaux :

- `composer.lock` et `package-lock.json` pour les dépendances ;
- les tags précis des images Docker (`Dockerfile`, `compose*.yaml`) ;
- l'absence d'étiquette `latest`.

| Composant | Version | Raison |
|-----------|---------|--------|
| PHP | 8.5.11 (image `php:8.5.11-fpm-trixie`) | Version stable courante, prise en charge par Laravel 13. |
| Laravel | 13.36 | Version majeure courante (attributs de modèle, sécurité CSRF renforcée). |
| Laravel Fortify | 1.41 | Authentification sans interface imposée, TOTP et codes de secours éprouvés. |
| Inertia (Laravel 3.5, Vue 3.9) | 3.x | Rendu Vue avec routage et contrôle d'accès côté serveur, sans API publique. |
| Vue | 3.5 | Version stable ; TypeScript strict. |
| TypeScript | 6.0.3 | `vue-tsc` 3.3 n'est pas compatible avec TypeScript 7 (API JavaScript retirée). |
| Tailwind CSS | 4.3 | Thème marine, turquoise et neutres défini dans `resources/css/app.css`. |
| Vite | 8.3 | Compilation des ressources ; noms versionnés et mis en cache. |
| TipTap | 3.32 | Éditeur riche moderne ; le HTML produit est de toute façon filtré côté serveur. |
| OpenSpout | 5.12 | Lecture et écriture CSV / XLSX en flux (faible mémoire), sans macros. |
| symfony/html-sanitizer | 7.4 | Filtrage HTML par liste blanche, maintenu par Symfony. |
| MariaDB | 11.8.9 (LTS) | Base demandée ; FULLTEXT InnoDB suffisant pour la recherche. |
| Nginx | 1.30.5 | Branche stable. |
| Node.js (compilation uniquement) | 24.21 LTS | Absent de l'image de production. |
| Composer | 2.10.3 | Absent de l'image de production. |
| Mailpit | 1.31.5 | Développement uniquement. |
| Certbot | 5.8.0 | Renouvellement Let's Encrypt. |

Autres décisions :

- **File d'attente et cache en base de données** : aucun Redis à exploiter. Le
  volume (imports, e-mails) le permet largement.
- **Sessions en base** : la révocation d'un utilisateur supprime réellement ses
  sessions.
- **Imports** : analyse et aperçu synchrones, puis exécution en tâche de fond
  dans **une seule transaction**. Une erreur annule tout l'import, et le
  rapport indique chaque ligne.
- **Archivage plutôt que suppression** : la suppression définitive est
  réservée aux droits élevés et confirmée par saisie.
- **Dates** : stockées en UTC, affichées dans `APP_DISPLAY_TIMEZONE` (Europe/Paris par défaut).
- **Pas de SSR** Inertia : simplicité d'exploitation, et application derrière
  une connexion de toute façon.
