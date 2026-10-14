# Déploiement sur un hébergement Plesk Test

Cette méthode installe TechIndex **sans Docker** sur un serveur administré
avec Plesk Obsidian (Linux), derrière un domaine ou un sous-domaine. Elle
s'adapte aux contraintes d'un abonnement Plesk :

- tout s'exécute sous **l'utilisateur système de l'abonnement** (propriétaire
  du site), sans root ni sudo ;
- aucun service permanent n'est possible : la file d'attente est traitée par
  la tâche planifiée de l'abonnement ;
- l'utilisateur MariaDB n'a de droits que sur les bases créées dans Plesk ;
- PHP, le serveur web, la base de données et le certificat sont ceux de Plesk.

Pour un serveur Linux dédié avec Docker, voir plutôt
[deploiement-linux.md](deploiement-linux.md).

## 1. Principe

L'application est **construite par GitHub** à chaque publication (voir
[publication-et-mises-a-jour.md](publication-et-mises-a-jour.md)), puis
livrée sous forme d'archive signée. Le serveur la télécharge lui-même : il n'a
besoin ni de Composer, ni de Node.js, seulement d'un accès HTTPS sortant à
GitHub.

```
GitHub (versions publiées)                   Serveur Plesk (utilisateur de l'abonnement)
  release.json + signature   ──HTTPS──────►  ~/techindex/
  techindex-plesk-<version>.tar.gz             techindex-plesk.sh   script d'exploitation
  techindex-plesk.sh                           app/                 version en service
                                                 public/            ◄── racine du document Plesk
Poste Windows (déploiement forcé)              shared/              conservé d'une version à l'autre
  .\techindex.cmd plesk-deploy   ──SSH────►      .env               configuration et clés (600)
                                                 storage/           pièces jointes, journaux, avancement des mises à jour
                                                 backups/           sauvegardes
                                               releases/            versions précédentes (retour arrière)
                                               archives/            archives téléchargées ou reçues
```

L'archive contient le code, les dépendances PHP de production, l'interface
compilée, le script d'exploitation et l'outil `age` (chiffrement des
sauvegardes, compilé sans dépendance système). Elle ne contient ni `.env`, ni
clé, ni test, ni outil de développement. Avant toute installation, le script
vérifie la **signature** du manifeste de la version (clé publique livrée avec
le code), puis l'empreinte SHA-256 de l'archive.

Les mises à jour se font ensuite depuis **Administration > Mises à jour**, en
SSH (`./techindex-plesk.sh update`) ou depuis le poste de développement
(`.\techindex.cmd plesk-deploy -Release latest`) : sauvegarde complète,
maintenance de moins d'une minute, migrations de la base, retour arrière
possible.

| Fonction | Serveur Linux (Docker) | Hébergement Plesk |
|----------|------------------------|-------------------|
| Serveur web | Nginx du conteneur `web` | nginx et Apache de Plesk |
| PHP | conteneur `app` | PHP-FPM de Plesk, sous l'utilisateur de l'abonnement |
| Base de données | conteneur `mariadb` | MariaDB du serveur, base créée dans Plesk |
| HTTPS | service `certbot` | certificat Let's Encrypt géré par Plesk |
| File d'attente | service `worker` | worker lancé chaque minute par le planificateur |
| Tâches planifiées | service `scheduler` | tâche planifiée Plesk (`schedule:run` chaque minute) |
| Journaux | `docker compose logs` | `shared/storage/logs` et journaux du domaine dans Plesk |
| Retour arrière | image Docker précédente | version précédente conservée dans `releases/` |

## 2. Prérequis

### Serveur (administrateur Plesk ou hébergeur)

| Élément | Exigence | Remarque |
|---------|----------|----------|
| Plesk | Obsidian 18.0, Linux | Plesk pour Windows n'est pas pris en charge |
| PHP | **8.5** (version de référence, testée) | 8.4.1 est le minimum accepté par les dépendances. Installation : *Outils et paramètres > Mises à jour > Ajouter/supprimer des composants* |
| Extensions PHP | `pdo_mysql`, `mbstring`, `intl`, `zip`, `fileinfo`, `openssl`, `dom`, `xml`, `xmlreader`, `ctype`, `tokenizer`, `sodium` | Fournies par les versions de PHP de Plesk ; `techindex-plesk.sh check` les contrôle |
| Fonctions PHP | `proc_open` non désactivée (`disable_functions`) | Sauvegardes et tâches de fond |
| Base de données | **MariaDB 10.11** ou plus récent (11.8 de référence) | MySQL n'est pas pris en charge |
| Client MariaDB | `mariadb-dump` et `mariadb` (ou `mysqldump` et `mysql`) | Présents sur les serveurs Plesk ; nécessaires aux sauvegardes |
| Processeur | x86_64 | L'outil `age` livré est compilé pour cette architecture (voir § 10 pour ARM) |
| Disque | 1 Go, plus les pièces jointes et les sauvegardes | Dans le quota de l'abonnement |

Flux réseau :

- 80 et 443 entrants (Plesk) ;
- 443 sortant vers `github.com` et `*.githubusercontent.com` (téléchargement
  et recherche des versions ; sans cet accès, seul le déploiement forcé depuis
  le poste reste possible) ;
- SMTP sortant (587 ou 465) si la messagerie n'est pas celle du serveur.

MariaDB n'a pas à être joignable de l'extérieur.

### Abonnement : autorisations nécessaires

Les restrictions d'un abonnement Plesk portent sur ce que son utilisateur peut
faire. TechIndex a besoin des autorisations suivantes ; si vous n'êtes pas
administrateur du serveur, demandez-les à l'hébergeur.

| Autorisation | Usage | Sans elle |
|--------------|-------|-----------|
| Accès SSH avec le shell **`/bin/bash`** | Installation, mises à jour, sauvegardes | Installation impossible. Le shell isolé (« chroot ») ne voit ni PHP ni les outils MariaDB |
| Gestion des tâches planifiées | `schedule:run` chaque minute | Ni e-mails (file d'attente), ni sauvegarde automatique, ni purge |
| Gestion des paramètres PHP (ou réglage par l'administrateur) | Version de PHP, taille des envois | Pièces jointes limitées à 2 Mo (valeur par défaut de PHP) |
| Gestion des paramètres d'hébergement | Racine du document | Site inaccessible |
| Bases de données : **2** | Base de l'application et base de vérification des sauvegardes | Une seule suffit, mais `verify-backup` est alors impossible |
| Certificats SSL/TLS | Let's Encrypt | Pas de HTTPS (obligatoire : cookies sécurisés, clés d'accès) |

Côté administrateur : *Abonnements >* (abonnement) *> Personnaliser >
Autorisations*, puis, pour le shell, *Accès à l'hébergement Web* (Web Hosting
Access) de l'abonnement. Selon la version de Plesk, les écrans changent de
place : le champ de recherche de Plesk les retrouve par leur nom.

### Isolation : un abonnement dédié à TechIndex

Dans Plesk, **tous les sites d'un abonnement** (domaine principal,
sous-domaines, domaines supplémentaires) s'exécutent sous le même utilisateur
système. Un site vulnérable du même abonnement (un WordPress, par exemple)
pourrait lire `shared/.env`, donc les clés de chiffrement de tous les secrets
clients.

Créez donc un **abonnement dédié** pour le nom de TechIndex, même s'il s'agit
d'un sous-domaine (`techindex.mondomaine.fr` peut être un abonnement distinct
de `mondomaine.fr`). À défaut, n'hébergez aucune autre application dans
l'abonnement de TechIndex.

## 3. Préparer l'abonnement dans Plesk

1. **Domaine** : créez l'abonnement (ou le sous-domaine) et faites pointer le
   DNS (`A`, et `AAAA` en IPv6) vers le serveur.
2. **Accès SSH** : *Accès à l'hébergement Web* > *Accès au serveur via SSH* :
   `/bin/bash`. Préférez une authentification par clé SSH (extension *SSH
   Keys* de Plesk, ou fichier `~/.ssh/authorized_keys` de l'abonnement).
3. **PHP** : *Paramètres PHP* du domaine :
   - version **PHP 8.5**, gestionnaire **« Application FPM servie par
     Apache »** (choix par défaut ; pour nginx seul, voir § 6) ;
   - valeurs :

     | Directive | Valeur |
     |-----------|--------|
     | `memory_limit` | `256M` |
     | `max_execution_time` | `120` |
     | `max_input_time` | `120` |
     | `max_input_vars` | `5000` |
     | `post_max_size` | `26M` |
     | `upload_max_filesize` | `25M` |
     | `display_errors` | `off` |
     | `log_errors` | `on` |
     | `opcache.enable` | `on` |
     | `open_basedir` | valeur par défaut `{WEBSPACEROOT}{/}{:}{TMP}{/}`, à conserver |

   - au-delà d'une vingtaine d'utilisateurs simultanés, portez
     `pm.max_children` à 10 ou plus (paramètres de performance PHP-FPM).
4. **Bases de données** : *Bases de données > Ajouter une base de données* :
   - base `techindex` (Plesk ajoute parfois un préfixe), serveur MariaDB local ;
   - un utilisateur dédié, avec un mot de passe généré par Plesk : notez-le,
     il sera demandé à l'installation ;
   - une seconde base, **vide**, réservée au test de restauration des
     sauvegardes (par exemple `techindex_verify`), accessible au même
     utilisateur. Dans *Gestion des utilisateurs* de la page *Bases de
     données*, donnez à l'utilisateur l'accès à **toutes les bases de
     l'abonnement** (choix « Toutes » / *Any*). Avec un abonnement dédié, cela
     se limite aux deux bases de TechIndex.
5. **Certificat** : *Certificats SSL/TLS* > Let's Encrypt pour le domaine,
   puis activez la redirection permanente de HTTP vers HTTPS (*Redirection
   301 de HTTP vers HTTPS*). Plesk renouvelle le certificat automatiquement.
6. **Messagerie** (facultatif) : créez une boîte `techindex@votre-domaine`
   pour l'envoi des e-mails ; Plesk indique ses paramètres SMTP.

Ne modifiez pas encore la racine du document : le dossier `app/public`
n'existera qu'après l'installation.

## 4. Récupérer la version à installer

Les versions sont publiées sur le dépôt GitHub du projet (voir
[publication-et-mises-a-jour.md](publication-et-mises-a-jour.md)). Trois
façons de lancer l'installation, toutes avec l'utilisateur SSH de l'abonnement.

### Depuis le poste de développement (conseillé)

Prérequis du poste : le client OpenSSH de Windows (*Paramètres > Système >
Fonctionnalités facultatives*).

```powershell
.\techindex.cmd plesk-deploy -Release latest -Target utilisateur@serveur.example.com
```

Sur le serveur, la commande télécharge le script de la dernière version
publiée dans `~/techindex`, puis lance `install` (première fois) ou `update`
(ensuite), dans une session interactive. Options :

- `-Release 1.4.0` : une version précise ;
- `-RemotePath` : dossier de déploiement relatif au dossier personnel
  (`techindex` par défaut) ;
- `PLESK_DEPLOY_TARGET` et `PLESK_DEPLOY_PATH` dans le `.env` du poste
  remplacent `-Target` et `-RemotePath`.

Pour un port SSH ou une clé particuliers, déclarez un alias dans
`%USERPROFILE%\.ssh\config` et utilisez-le comme cible :

```
Host techindex-prod
    HostName serveur.example.com
    User utilisateur-abonnement
    Port 22
    IdentityFile ~/.ssh/id_ed25519
```

### Directement en SSH

```bash
mkdir -p ~/techindex && cd ~/techindex
curl -fsSL -o techindex-plesk.sh https://github.com/<compte>/<dépôt>/releases/latest/download/techindex-plesk.sh
chmod 700 techindex-plesk.sh
./techindex-plesk.sh check
./techindex-plesk.sh install
```

Le script téléchargé contient le nom du dépôt et la clé publique de
signature : il refuse toute archive qui ne provient pas de ce canal.

### Déploiement forcé d'une version construite sur le poste

Pour un serveur de test, sans publier sur GitHub (ou sans accès sortant du
serveur à GitHub) :

```powershell
.\techindex.cmd plesk-deploy -Target utilisateur@serveur.example.com
```

L'archive est construite dans Docker Desktop (`dist\plesk`, version
`X.Y.Z-dev.<date>`), envoyée par SSH avec son empreinte, puis installée par le
script qu'elle contient. Une archive existante se redéploie en l'indiquant :
`.\techindex.cmd plesk-deploy dist\plesk\techindex-plesk-1.4.1-dev.202610141530.tar.gz`.

Sans le client SSH de Windows : `.\techindex.cmd plesk-package`, puis envoi par
SFTP (WinSCP, FileZilla) ou par le *Gestionnaire de fichiers* de Plesk de
`techindex-plesk.sh` dans `~/techindex/`, et de l'archive avec son fichier
`.sha256` dans `~/techindex/archives/` ; enfin, en SSH :
`./techindex-plesk.sh install archives/techindex-plesk-<version>.tar.gz`.

Depuis Linux ou macOS, la construction est la même commande Docker :

```bash
docker build --target plesk-release --build-arg TECHINDEX_VERSION=1.4.1-dev.$(date +%Y%m%d%H%M) --output type=local,dest=dist/plesk .
```

Sur un poste Apple Silicon, l'outil `age` reste compilé pour x86_64
(`--build-arg AGE_ARCH=arm64` pour un serveur ARM).

## 5. Installer

`./techindex-plesk.sh check` contrôle l'hébergement sans rien modifier :
utilisateur (pas root), shell, version et extensions de PHP, fonctions
désactivées, mémoire, outils MariaDB. Corrigez chaque problème signalé (en
général dans les *Paramètres PHP* de Plesk) avant d'installer.

`./techindex-plesk.sh install` (dernière version publiée), `install --version
1.4.0` ou `install <archive>` ; l'installation, que l'on peut relancer sans
risque :

1. vérifie l'hébergement ;
2. pour une version publiée : télécharge le manifeste, vérifie sa
   **signature**, puis télécharge l'archive ;
3. vérifie l'empreinte SHA-256 de l'archive ;
4. extrait la version dans `app/` et met le code en lecture seule ;
5. crée `shared/.env` (droits `600`) à partir de `.env.plesk.example`, puis
   demande le domaine, la base, l'utilisateur, le serveur (`localhost`) et le
   mot de passe de la base (saisie masquée) ;
6. génère `APP_KEY` et `SECRETS_KEY` **uniquement si elles sont absentes** ;
7. vérifie la connexion et la version de MariaDB ;
8. exécute les migrations et les données de référence ;
9. construit les caches de configuration, de routes et de vues ;
10. propose la création du premier administrateur ;
11. lance le diagnostic `techindex:doctor` ;
12. affiche les réglages Plesk à terminer (§ 6), avec les chemins exacts.

Le script n'écrase jamais une valeur déjà renseignée dans `shared/.env`. En
cas d'erreur (mot de passe de base erroné, par exemple), corrigez
`shared/.env`, puis relancez la même commande.

## 6. Finaliser dans Plesk

1. **Racine du document** : *Hébergement & DNS > Hébergement* (Hosting
   Settings) > *Racine du document* : **`techindex/app/public`** (chemin
   relatif au dossier de l'abonnement, affiché par le script). Seul ce dossier
   est servi : il ne contient que `index.php`, `.htaccess`, les fichiers
   compilés et les icônes.
2. **Domaine préféré** : réglez-le sur le nom exact d'`APP_URL`. TechIndex
   refuse les requêtes adressées à un autre nom (`www.` compris).
3. **Tâche planifiée** : *Tâches planifiées > Ajouter une tâche* :

   | Champ | Valeur |
   |-------|--------|
   | Type de tâche | *Exécuter un script PHP* (Run a PHP script) |
   | Chemin du script | `techindex/app/artisan` |
   | Arguments | `schedule:run` |
   | Version de PHP | la même que celle du site (8.5) |
   | Exécution | *Style cron* : `* * * * *` |
   | Notification | *Erreurs seulement* |
   | Description | `TechIndex – planificateur` |

   Cette tâche unique déclenche chaque minute les traitements de fond : file
   d'attente (e-mails, imports), sauvegarde quotidienne, purge, contrôle
   quotidien des secrets, recherche des nouvelles versions et installation des
   mises à jour demandées depuis l'administration. Variante *Exécuter une commande* (shell non isolé) :
   `/opt/plesk/php/8.5/bin/php /var/www/vhosts/<domaine>/techindex/app/artisan schedule:run`.
4. **nginx seul** (facultatif) : avec le gestionnaire *Application FPM servie
   par nginx* et le mode proxy désactivé (*Paramètres Apache & nginx*), le
   fichier `.htaccess` n'est pas lu. Ajoutez dans *Directives nginx
   supplémentaires* :

   ```nginx
   location / {
       try_files $uri $uri/ /index.php?$query_string;
   }
   ```

   Si Plesk signale un doublon de `location /`, utilisez à la place :
   `if (!-e $request_filename) { rewrite ^ /index.php last; }`.
5. **Cache des fichiers compilés** (facultatif, dans les deux modes) : dans
   *Directives nginx supplémentaires* :

   ```nginx
   location ^~ /build/ {
       expires 1y;
       add_header Cache-Control "public, immutable" always;
       add_header X-Content-Type-Options "nosniff" always;
       try_files $uri =404;
   }
   ```

Puis, en SSH, après une ou deux minutes :

```bash
./techindex-plesk.sh doctor
```

Toutes les lignes doivent être « OK », y compris « Planificateur actif ».

Ensuite, sans attendre :

1. **Exportez les clés hors du serveur** (voir [secrets-et-cles.md](secrets-et-cles.md)) :
   ```bash
   ./techindex-plesk.sh keys-export ~/techindex-cles.txt
   ```
   Téléchargez ce fichier (SFTP) dans un coffre ou un gestionnaire de mots de
   passe, puis supprimez-le du serveur : `shred -u ~/techindex-cles.txt`.
2. **Configurez la messagerie** (§ 7), puis
   `./techindex-plesk.sh mail-test vous@votre-domaine.fr`.
3. **Chiffrez les sauvegardes** et **organisez leur copie hors du serveur**
   (voir [sauvegarde-restauration.md](sauvegarde-restauration.md)).
4. **Excluez les clés des sauvegardes Plesk** (§ 8).
5. Connectez-vous, changez le mot de passe si demandé, puis activez la double
   authentification.
6. Dans le journal d'audit, vérifiez que l'adresse IP de votre connexion est
   bien la vôtre (§ 10).

## 7. Paramètres (`shared/.env`)

Le fichier `.env.plesk.example` est commenté ligne par ligne. Différences
avec la production Docker :

| Paramètre | Rôle |
|-----------|------|
| `APP_URL` | URL publique en `https://` ; seules les requêtes adressées à ce domaine sont acceptées |
| `TECHINDEX_PHP` | PHP en ligne de commande (`/opt/plesk/php/8.5/bin/php`), même version que le site |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Base créée dans Plesk. `localhost` utilise la socket locale ; `127.0.0.1` passe par TCP |
| `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS` | Journaux quotidiens dans `shared/storage/logs`, 14 jours |
| `QUEUE_WORK_FROM_SCHEDULER=true` | La file d'attente est traitée par le planificateur (pas de service permanent) |
| `DB_QUEUE_RETRY_AFTER=960` | Supérieur à la durée maximale d'un import : une tâche longue n'est jamais exécutée deux fois |
| `BACKUP_VERIFY_DATABASE` | Base vide réservée au test de restauration (`verify-backup`) |
| `BACKUP_MARIADB_DUMP`, `BACKUP_MARIADB` | Renseignés par l'installation si seuls `mysqldump` et `mysql` existent |
| `TRUSTED_PROXIES` | Vide : nginx et Apache de Plesk transmettent déjà l'adresse du client et HTTPS |
| `MAIL_*` | Serveur SMTP, par exemple celui de la boîte créée dans Plesk |
| `TECHINDEX_DEPLOYMENT=plesk` | Renseigné par le script : active l'installation des mises à jour depuis l'administration |
| `UPDATE_CHECK_ENABLED` | `false` pour ne jamais contacter GitHub (recherche quotidienne et bouton désactivés) |
| `UPDATE_REPOSITORY`, `UPDATE_PUBLIC_KEY`, `UPDATE_BASE_URL` | Vides : dépôt et clé publique livrés avec le code (`config/release-channel.json`). À renseigner seulement pour un miroir ou un dépôt dérivé |

Les autres paramètres (sessions, double authentification, coffre, sauvegardes)
sont décrits dans [deploiement-linux.md](deploiement-linux.md#3-paramètres-principaux-env).

Pour modifier le fichier : `nano ~/techindex/shared/.env` (ou le *Gestionnaire
de fichiers* de Plesk). Mettez entre apostrophes toute valeur contenant des
espaces, `#` ou `$` : `MAIL_PASSWORD='mot de#passe$'`. Puis :

```bash
./techindex-plesk.sh reload
```

La configuration est mise en cache : sans `reload`, une modification n'est
pas prise en compte.

## 8. Droits et isolation

| Élément | Protection |
|---------|-----------|
| Dossier de déploiement | Hors de toute racine web ; droits `711` : le serveur web le traverse jusqu'à `app/public`, sans pouvoir le lister |
| `shared/` | Droits `700` : fermé au serveur web et aux autres comptes ; `.env` en `600` |
| Code (`app/`) | En lecture seule, y compris pour PHP ; seul `bootstrap/cache` est modifiable |
| Racine du document | `app/public` uniquement ; aucun autre script PHP que `index.php` |
| Processus | PHP-FPM et tâches planifiées sous l'utilisateur de l'abonnement ; jamais root |
| Base de données | Utilisateur limité aux bases de l'abonnement, sans droit de création de base |
| Sauvegardes | `shared/backups` en `700`, archives en `600` |
| Archives | `archives/` en `700` ; les trois plus récentes sont conservées |
| Versions installées | Signature Ed25519 du manifeste vérifiée avec la clé publique du code installé, puis empreinte SHA-256 ; une version plus ancienne est refusée |
| Mise à jour depuis l'interface | Administrateurs seulement, confirmation de l'identité, audit ; PHP ne fait que déposer une demande, que la tâche planifiée confie au script |

**Sauvegardes Plesk** : le *Gestionnaire de sauvegarde* de Plesk copie tout
le dossier de l'abonnement, donc `shared/.env` et ses clés, en plus de la
base. Une telle sauvegarde donne accès à tous les secrets. Dans ses
paramètres, excluez `techindex/shared/.env` (*Exclure des fichiers de la
sauvegarde*) et protégez les sauvegardes par un mot de passe. Les clés se
sauvegardent à part (`keys-export`), comme prévu par TechIndex.

## 9. Exploitation

### Mises à jour

- **Depuis l'administration** : *Administration > Mises à jour*. *Rechercher
  une mise à jour* interroge GitHub (la recherche est aussi faite chaque nuit
  à 04:10, et le menu affiche « Nouvelle » quand une version est disponible) ;
  *Installer la version X.Y.Z* demande la confirmation de votre identité, puis
  la tâche planifiée lance la mise à jour dans la minute. La page affiche
  l'étape en cours et le journal ; le site est en maintenance pendant la mise
  en service (moins d'une minute en général), et la page se recharge à la fin.
- **En SSH** : `./techindex-plesk.sh check-update`, puis
  `./techindex-plesk.sh update` (dernière version) ou `update --version 1.4.0`.
- **Depuis le poste de développement** :
  `.\techindex.cmd plesk-deploy -Release latest`.

Dans tous les cas, la même procédure s'applique : vérification de la
signature, sauvegarde complète, maintenance, migrations, contrôle du site.
Une seule opération à la fois : une mise à jour demandée pendant une autre
est refusée. Détails et retour arrière : [mise-a-jour.md](mise-a-jour.md#4-hébergement-plesk).

**Installation antérieure au système de mise à jour** (installée avec une
archive locale) : remplacez une fois le script par celui de la dernière
version publiée, puis mettez à jour :

```bash
cd ~/techindex
curl -fsSL -o techindex-plesk.sh https://github.com/<compte>/<dépôt>/releases/latest/download/techindex-plesk.sh
./techindex-plesk.sh update
```

### Commandes

```bash
cd ~/techindex
./techindex-plesk.sh status             # version, maintenance, dernière sauvegarde, dernière mise à jour
./techindex-plesk.sh logs -f            # journal de l'application (Ctrl+C pour quitter)
./techindex-plesk.sh doctor             # diagnostic
./techindex-plesk.sh reload             # après une modification de shared/.env
./techindex-plesk.sh down               # maintenance
./techindex-plesk.sh up                 # remise en service
./techindex-plesk.sh backup             # sauvegarde immédiate
./techindex-plesk.sh verify-backup <archive> [--identity cle-age.txt]
./techindex-plesk.sh restore <archive> [--identity cle-age.txt]
./techindex-plesk.sh keys-export <fichier>
./techindex-plesk.sh rotate-secrets-key
./techindex-plesk.sh create-admin
./techindex-plesk.sh artisan <commande>
```

- **Sauvegardes** : [sauvegarde-restauration.md](sauvegarde-restauration.md).
- **Journaux** : application dans `shared/storage/logs` (14 jours) ; erreurs
  du serveur web et de PHP-FPM dans *Journaux* du domaine, dans Plesk.
- **Traitements de fond** : la file d'attente est traitée au plus une minute
  après une demande (e-mail de réinitialisation, import).

## 10. Dépannage

| Symptôme | Cause probable | Solution |
|----------|----------------|----------|
| `check` : commande introuvable, aucun PHP trouvé | Shell SSH isolé (chroot) | Shell `/bin/bash` pour l'abonnement (§ 2) |
| `check` : extension ou fonction manquante | Réglages PHP de Plesk | *Paramètres PHP* ; `proc_open` ne doit pas figurer dans `disable_functions` |
| Connexion à la base refusée | Socket introuvable ou utilisateur limité à TCP | `DB_HOST=127.0.0.1` dans `shared/.env`, puis relancer `install` ou `reload` |
| « Serveur MySQL non pris en charge » | Serveur MySQL et non MariaDB | Base sur un serveur MariaDB (demande à l'hébergeur) |
| Erreur 403 ou liste de fichiers à la place du site | Racine du document | `techindex/app/public` (§ 6) |
| Erreur 400 ou 404 sur toutes les pages | Nom d'accès différent d'`APP_URL` (`www.`, alias) | Domaine préféré, ou `APP_URL` puis `reload` |
| Erreur 500 | Version de PHP du site différente, extension manquante | Journaux du domaine dans Plesk et `logs` ; même version que `TECHINDEX_PHP` |
| Pièce jointe refusée au-delà de 2 Mo | Limites PHP par défaut | `upload_max_filesize` 25M, `post_max_size` 26M (§ 3) |
| Redirections en boucle, session perdue | HTTPS non détecté (proxy supplémentaire : Cloudflare, répartiteur) | `TRUSTED_PROXIES` avec les adresses du proxy |
| Adresse 127.0.0.1 ou celle du serveur dans l'audit | Idem | Idem |
| E-mails jamais reçus, `doctor` : planificateur inactif | Tâche planifiée absente ou mauvaise version de PHP | § 6, étape 3 |
| `verify-backup` : *Access denied ... CREATE DATABASE* | Base de vérification non configurée | `BACKUP_VERIFY_DATABASE` (§ 3, étape 4) |
| Enregistrement d'un document refusé (403) | Pare-feu applicatif ModSecurity | Journal ModSecurity du domaine ; désactivez la règle concernée pour ce domaine |
| Sauvegarde chiffrée impossible (`age`) | Serveur ARM | Archive construite avec `AGE_ARCH=arm64` |
| Erreurs juste après une mise à jour | OPcache configuré pour ne pas revérifier les fichiers | Délai `TECHINDEX_SWITCH_DELAY=60 ./techindex-plesk.sh update ...`, ou enregistrer les *Paramètres PHP* pour redémarrer PHP-FPM |
| Site en maintenance après un échec | Mise à jour interrompue après le changement de version | Messages du script ou journal de la page *Mises à jour* ; `up` ou `rollback` en SSH |
| *Mises à jour* : « Dépôt injoignable » | Pas d'accès sortant à GitHub | Autorisez `github.com` et `*.githubusercontent.com` (443), ou déploiement forcé depuis le poste |
| *Mises à jour* : « Signature invalide » | Version altérée ou publiée hors du canal officiel | Ne rien installer ; prévenir le responsable du dépôt |
| *Mises à jour* : bouton d'installation absent | Mode d'installation non Plesk, ou script `techindex-plesk.sh` introuvable | `TECHINDEX_DEPLOYMENT=plesk` dans `shared/.env` et `reload` ; script dans le dossier parent de `app/` |
| Mise à jour demandée mais jamais lancée | Tâche planifiée absente | § 6, étape 3 ; `doctor` |
| « Une autre opération est en cours » | Mise à jour, retour arrière ou restauration en cours | Attendre ; `./techindex-plesk.sh status` (un verrou abandonné est repris automatiquement) |

L'extension *Laravel Toolkit* de Plesk n'est pas utilisée : TechIndex est
livré compilé, avec sa propre procédure de mise à jour (sauvegarde préalable,
maintenance, retour arrière).

## 11. Liste de contrôle après installation

- [ ] `./techindex-plesk.sh doctor` sans erreur, planificateur actif ;
- [ ] HTTPS valide et redirection de HTTP vers HTTPS ;
- [ ] `https://<domaine>/.env` et `https://<domaine>/../shared/.env` répondent 404 ;
- [ ] clés exportées et stockées hors du serveur ;
- [ ] `shared/.env` exclu des sauvegardes Plesk ;
- [ ] e-mail de test reçu ;
- [ ] sauvegarde chiffrée (`BACKUP_AGE_RECIPIENT`), copiée hors du serveur et
      vérifiée avec `verify-backup` ;
- [ ] pièce jointe de plus de 2 Mo enregistrée ;
- [ ] double authentification activée pour les administrateurs ;
- [ ] adresse IP correcte dans le journal d'audit ;
- [ ] *Administration > Mises à jour* : « Rechercher une mise à jour » répond
      (accès sortant à GitHub).
