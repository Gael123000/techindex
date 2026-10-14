# Secrets clients et clés de chiffrement

## 1. Principe

Les **secrets** sont les valeurs confidentielles des accès enregistrés dans
TechIndex : mots de passe d'équipements, de comptes administrateur et de
services, clés d'API, clés partagées VPN...

| Mesure | Mise en œuvre |
|--------|---------------|
| Jamais en clair en base | Chiffrement authentifié **AES-256-GCM** (`Encrypter` de Laravel, OpenSSL), sans cryptographie maison |
| Clé dédiée | `SECRETS_KEY`, distincte d'`APP_KEY` : la compromission de l'une n'expose pas l'autre usage |
| Clé identifiée | Chaque secret stocke l'empreinte de la clé utilisée (`secret_key_id`), ce qui permet la rotation |
| Masqué par défaut | Les pages ne contiennent jamais la valeur, seulement l'indication « secret enregistré » |
| Révélation contrôlée | Requête dédiée, droit « secrets » sur le client, confirmation du mot de passe datant de moins de 10 minutes, 20 révélations par minute au plus, réponse `no-store`, masquage automatique après 30 secondes |
| Traçabilité | Chaque révélation ou copie est journalisée (qui, quand, quel accès, résultat), **sans la valeur** |
| Jamais ailleurs | Absent des journaux, de l'audit (modification notée « [masqué] »), de la recherche, des notifications, des exports et des messages d'erreur |
| Sauvegardes | Les secrets y figurent chiffrés ; la clé n'y figure jamais |

Les **mots de passe de connexion** des utilisateurs sont hachés (bcrypt) et
indéchiffrables. Les secrets clients sont, eux, chiffrés et déchiffrables par
les utilisateurs autorisés : c'est l'objet même de ce module.

### Gestionnaire de mots de passe

Le gestionnaire (menu **Mots de passe**) présente les mêmes accès que les
fiches, avec un niveau de protection supplémentaire :

| Mesure | Mise en œuvre |
|--------|---------------|
| Coffre verrouillé | Toutes ses pages et actions exigent un déverrouillage par **clé d'accès** (WebAuthn, vérification de l'utilisateur par biométrie ou code PIN obligatoire). Un mot de passe, même confirmé, ne suffit pas. |
| Défi à usage unique | Chaque vérification utilise un défi aléatoire conservé en session pour un seul usage (connexion, confirmation ou coffre) : une réponse ne peut être ni rejouée, ni réutilisée pour un autre usage. |
| Clés de l'utilisateur seulement | Seules les clés d'accès de l'utilisateur connecté sont proposées et acceptées. |
| Reverrouillage | Après `VAULT_IDLE_TIMEOUT` secondes d'inactivité (300 par défaut), au bout de `VAULT_MAX_UNLOCK` secondes (1 800) même en cas d'activité, à la déconnexion, à la suppression d'une clé d'accès, ou manuellement. L'état est propre à la session. |
| Mêmes règles ensuite | Droit « secrets », limitation du débit, réponse `no-store`, masquage après 30 secondes, audit sans la valeur. |
| Traçabilité | Ouvertures et échecs d'ouverture journalisés (catégorie « Secrets » du journal d'audit). |
| Pages non mises en cache | Les pages du gestionnaire sont servies avec `Cache-Control: no-store`. |

Les clés d'accès s'ajoutent dans **Mon profil**, après confirmation de
l'identité. Seule leur clé **publique** est enregistrée (table `passkeys`) :
la clé privée ne quitte jamais l'appareil. Une clé d'accès est liée au domaine
d'`APP_URL` (`PASSKEYS_RP_ID`) : changer de domaine rend les clés existantes
inutilisables. Une perte d'appareil se traite par **Réinitialiser la double
authentification** (administration des utilisateurs), qui supprime aussi les
clés d'accès.

### Isolation du code et coffre externe

Tout accès aux valeurs passe par `App\Secrets\CredentialSecrets`, qui s'appuie
sur le contrat `App\Secrets\Contracts\SecretVault` :

```php
interface SecretVault
{
    public function seal(string $plaintext): SealedSecret;    // chiffrer
    public function unseal(SealedSecret $sealed): string;     // déchiffrer
    public function needsResealing(SealedSecret $sealed): bool;
}
```

L'implémentation actuelle, `LocalSecretVault`, chiffre localement avec le
trousseau `Keyring`. Brancher un coffre externe (HashiCorp Vault Transit,
Azure Key Vault, AWS KMS...) revient à écrire une autre implémentation et à
changer la liaison dans `AppServiceProvider`. Les contrôleurs, droits et
journaux restent inchangés.

## 2. Les clés

| Clé | Protège | Si elle est perdue |
|-----|---------|-------------------|
| `APP_KEY` | Sessions, cookies, secrets TOTP et codes de secours des utilisateurs | Tout le monde est déconnecté et chacun doit reconfigurer sa double authentification |
| `SECRETS_KEY` | Secrets clients | **Les secrets sont définitivement illisibles.** Aucune récupération n'est possible : c'est voulu |
| `SECRETS_PREVIOUS_KEYS` | Anciennes clés, après une rotation | Les secrets et sauvegardes antérieurs à la rotation deviennent illisibles |
| Clé privée `age` | Sauvegardes chiffrées | Les sauvegardes sont illisibles |

Les clés sont générées automatiquement à l'installation, **uniquement si elles
sont absentes**. Aucun script ne remplace une clé existante.

Pour afficher les empreintes, qui permettent de comparer deux installations
sans révéler les clés :

```powershell
.\techindex.cmd artisan techindex:keys-info
```

## 3. Sauvegarder les clés (indispensable)

Les clés ne sont **pas** dans les sauvegardes. Exportez-les dès l'installation,
puis après chaque rotation :

```powershell
.\techindex.cmd keys-export E:\techindex-cles.txt
```

```bash
sudo ./scripts/techindex.sh keys-export /root/techindex-cles.txt
```

```bash
./techindex-plesk.sh keys-export ~/techindex-cles.txt      # hébergement Plesk
```

Le chemin est un exemple. Conservez ce fichier :

- dans un **gestionnaire de mots de passe d'équipe** ou un coffre (Bitwarden,
  KeePass, 1Password, coffre physique...) ;
- **hors du serveur et hors du stockage des sauvegardes** : une personne qui
  obtient les deux obtient tous les secrets ;
- en au moins deux exemplaires, accessibles à deux personnes, pour éviter la
  dépendance à un seul administrateur.

Supprimez ensuite le fichier exporté du serveur ou du poste (`shred -u` sous Linux).

Le contrôle quotidien `techindex:secrets-check` (03:15) vérifie que tous les
secrets restent déchiffrables avec les clés installées. Un échec apparaît dans
les journaux du planificateur.

## 4. Protéger la clé sur le serveur

- `.env` appartient à `root:www-data`, avec les droits `640`, et il est monté
  en lecture seule dans les conteneurs.
- Hébergement Plesk : `shared/.env` est lisible par le seul utilisateur de
  l'abonnement (droits `600`), hors de la racine web. Les autres sites du même
  abonnement partagent cet utilisateur, d'où l'abonnement dédié recommandé, et
  le fichier doit être exclu des sauvegardes Plesk (voir
  [deploiement-plesk.md](deploiement-plesk.md), § 2 et § 8).
- Les clés ne figurent jamais dans les images Docker, l'archive de déploiement
  Plesk, le dépôt Git ou les journaux.
- Option renforcée : fournir la clé par un fichier, par exemple un secret
  Docker, plutôt que dans `.env` :
  ```ini
  SECRETS_KEY=
  SECRETS_KEY_FILE=/run/secrets/techindex_secrets_key
  ```
  Le fichier contient la clé au format `base64:...`. Il faut alors monter ce
  secret dans les services `app`, `worker` et `scheduler` (section `secrets:`
  de Compose).

## 5. Rotation de la clé des secrets

À faire en cas de soupçon de compromission, de départ d'un administrateur
ayant eu accès à la clé, ou selon votre politique (par exemple une fois par an).

### Serveur Linux

```bash
sudo ./scripts/techindex.sh rotate-secrets-key
```

La commande :

1. crée une sauvegarde préalable obligatoire ;
2. génère une nouvelle clé ;
3. déplace l'ancienne clé dans `SECRETS_PREVIOUS_KEYS`, où elle sert au
   déchiffrement uniquement ;
4. redémarre l'application ;
5. rechiffre tous les secrets avec la nouvelle clé (`techindex:secrets-rotate`) ;
6. vérifie que tous les secrets sont déchiffrables.

### Hébergement Plesk

```bash
./techindex-plesk.sh rotate-secrets-key
```

Même déroulement ; la configuration est rechargée (caches reconstruits) au
lieu d'un redémarrage des conteneurs.

### Poste Windows

```powershell
.\techindex.cmd backup
.\techindex.cmd artisan techindex:secrets-key --rotate
.\techindex.cmd artisan techindex:secrets-rotate
.\techindex.cmd artisan techindex:secrets-check
```

### Après la rotation

- **Exportez à nouveau les clés** (`keys-export`) et remplacez l'ancien export.
- Gardez l'ancienne clé dans `SECRETS_PREVIOUS_KEYS` **tant que des sauvegardes
  antérieures à la rotation sont conservées** : elle est nécessaire pour les
  restaurer.
- Si la rotation fait suite à une compromission, changez aussi les mots de
  passe chez les clients : une personne qui a pu lire la base et l'ancienne
  clé connaît les anciennes valeurs.

## 6. Rotation d'`APP_KEY`

Elle est rarement nécessaire, et seulement si la clé a fuité. Laravel accepte
les anciennes clés en déchiffrement :

1. faites une sauvegarde ;
2. dans `.env`, placez l'ancienne valeur dans `APP_PREVIOUS_KEYS` et générez
   une nouvelle clé :
   `docker compose -f compose.prod.yaml run --rm --no-deps -T --entrypoint php app artisan key:generate --show` ;
3. redémarrez, puis exportez les clés.

Les sessions et la double authentification restent valides grâce à
`APP_PREVIOUS_KEYS`. Pour vous en passer entièrement, demandez aux utilisateurs
de reconfigurer leur double authentification, puis retirez l'ancienne clé.
Une sauvegarde antérieure à la rotation se restaure en remettant
temporairement l'ancienne `APP_KEY` dans `.env`.
