# TechIndex

**Le référentiel technique de vos clients.**

TechIndex centralise la documentation technique des clients d'un prestataire
informatique : sites, contacts, équipements, réseaux, services, licences,
certificats, accès et procédures, avec un gestionnaire de mots de passe
déverrouillé par clé d'accès et un explorateur de fichiers par client. Les
accès sont limités client par client et action par action, et les secrets sont
chiffrés.

- Une installation par prestataire, pour plusieurs clients (pas de SaaS multi-tenant).
- Pas de compte client : seuls les collaborateurs du prestataire se connectent.
- Interface en français, responsive, utilisable au clavier.

| Pile          | Versions (figées)                                                      |
|---------------|------------------------------------------------------------------------|
| Serveur       | PHP 8.5, Laravel 13, Laravel Fortify (authentification, TOTP)          |
| Interface     | Vue 3 + TypeScript, Inertia 3, Tailwind CSS 4, Vite 8                   |
| Données       | MariaDB 11.8 LTS (recherche plein texte native)                        |
| Exploitation  | Nginx 1.30, PHP-FPM, Docker Compose, file d'attente en base, planificateur |

---

## Démarrage rapide (Windows)

### Prérequis

- Windows 10 22H2 ou Windows 11, 8 Go de RAM (16 Go conseillés), 10 Go d'espace disque.
- [Docker Desktop](https://docs.docker.com/desktop/setup/install/windows-install/)
  avec le moteur **WSL 2** (option par défaut), démarré.

PHP, Composer, Node.js, MariaDB et Nginx sont inutiles sur le poste : tout
s'exécute dans des conteneurs.

### Installation

Ouvrez PowerShell dans le dossier du projet (n'importe quel emplacement), puis lancez :

```powershell
.\techindex.cmd install
```

Le script :

1. vérifie Docker, WSL 2, Compose et la disponibilité des ports 8080 et 8025 ;
2. crée `.env` à partir de `.env.example` sans jamais écraser une valeur existante,
   et génère les mots de passe MariaDB aléatoires ;
3. construit les images puis installe les dépendances PHP et JavaScript ;
4. génère `APP_KEY` et `SECRETS_KEY` **uniquement si elles sont absentes** ;
5. attend MariaDB, exécute les migrations et charge les données de référence ;
6. démarre les services, puis propose de créer le premier administrateur
   (mot de passe saisi de façon masquée, ou généré et affiché une seule fois) ;
7. affiche l'adresse : **http://localhost:8080**.

Le script peut être relancé sans risque, par exemple après une erreur ou pour
réparer une installation.

> `techindex.cmd` lance `techindex.ps1` sans modifier la stratégie d'exécution
> PowerShell du poste. `.\techindex.ps1 <commande>` fonctionne aussi si vos
> scripts locaux sont autorisés.

### Première connexion

1. Connectez-vous avec le compte administrateur créé par le script.
2. Si le mot de passe a été généré, TechIndex impose d'en choisir un nouveau.
3. Activez la double authentification dans **Mon profil**. Elle est obligatoire
   pour les rôles qui la requièrent (variable `TWO_FACTOR_REQUIRED_ROLES`).
4. Facultatif : chargez des données de démonstration fictives avec
   `.\techindex.cmd demo`.
5. **Sauvegardez les clés de chiffrement hors du poste** :
   `.\techindex.cmd keys-export E:\techindex-cles.txt`. Le chemin est un
   exemple : choisissez une clé USB, un coffre ou un gestionnaire de mots de passe.

Les e-mails envoyés en local (réinitialisation de mot de passe) arrivent dans
Mailpit : **http://localhost:8025**.

### Commandes courantes

| Commande                                   | Effet                                                       |
|--------------------------------------------|-------------------------------------------------------------|
| `.\techindex.cmd start` / `stop` / `restart` | Démarrer, arrêter ou redémarrer (les données sont conservées) |
| `.\techindex.cmd status`                   | État des services                                           |
| `.\techindex.cmd logs app -Follow`         | Journaux d'un service (app, web, worker, scheduler, mariadb) |
| `.\techindex.cmd update`                   | Sauvegarde, dépendances, recompilation, migrations          |
| `.\techindex.cmd backup`                   | Sauvegarde dans `.\backups`                                 |
| `.\techindex.cmd verify-backup <archive>`  | Test de restauration dans une base temporaire               |
| `.\techindex.cmd restore <archive>`        | Restauration (remplace les données)                         |
| `.\techindex.cmd keys-export <fichier>`    | Copie des clés de chiffrement (à conserver hors du poste)   |
| `.\techindex.cmd demo`                     | Données de démonstration fictives (clients `DEMO-xxx`)      |
| `.\techindex.cmd create-admin`             | Nouvel administrateur                                       |
| `.\techindex.cmd doctor`                   | Diagnostic de la configuration                              |
| `.\techindex.cmd test`                     | Tests automatisés                                           |
| `.\techindex.cmd start -ExposeDatabase`    | Accès MariaDB local sur `127.0.0.1:3307` (désactivé par défaut) |
| `.\techindex.cmd dev`                      | Vite avec rechargement à chaud (développement de l'interface) |
| `.\techindex.cmd artisan <commande>`       | Commande Artisan quelconque                                 |
| `.\techindex.cmd release [patch\|minor\|major]` | Publie une version sur GitHub (voir « Publier une version ») |
| `.\techindex.cmd plesk-deploy -Release latest` | Met à jour la production Plesk par SSH avec la dernière version publiée |
| `.\techindex.cmd plesk-deploy`             | Déploiement forcé d'une archive construite sur le poste (tests) |

Tous les ports sont publiés **uniquement sur 127.0.0.1**. Les données sont
stockées dans des volumes Docker persistants : arrêter ou reconstruire les
conteneurs ne les supprime pas.

---

## Environnement local sans Docker (tests)

Pour tester rapidement sur le poste, sans Docker, `techindex-local.cmd`
installe un petit serveur web PHP et une base MariaDB. Il ne demande aucun
droit administrateur et ne modifie pas le PATH du système :

```powershell
.\techindex-local.cmd install        # téléchargement vérifié (SHA-256), base, migrations, démarrage
.\techindex-local.cmd create-admin   # premier compte (mot de passe saisi masqué)
```

| | Docker (`techindex.cmd`) | Local sans Docker (`techindex-local.cmd`) |
|---|---|---|
| Application | http://localhost:8080 | http://127.0.0.1:8000 (serveur intégré de PHP) |
| Base de données | MariaDB dans un conteneur | MariaDB 11.8 sur 127.0.0.1:3308 |
| Configuration | `.env` | `.env.local` |
| Données, journaux, pièces jointes | volumes Docker et `storage/` | `%USERPROFILE%\TechIndex-local` |
| E-mails | Mailpit (http://localhost:8025) | journal de l'application (`logs app`) |

Les deux environnements sont indépendants et peuvent coexister.

Autres commandes de l'environnement local :

- `start`, `stop`, `restart`, `status` ;
- `logs web|worker|mariadb|app [-Follow]` ;
- `test [options]` ;
- `update` ;
- `demo` ;
- `artisan …`.

Pour tout supprimer, arrêtez l'environnement (`stop`), puis supprimez le
dossier `%USERPROFILE%\TechIndex-local` et le fichier `.env.local`.

---

## Production (serveur Linux)

```bash
sudo ./scripts/techindex.sh install
```

La même application est déployée avec `compose.prod.yaml` : images compilées
sans outils de développement, HTTPS (Let's Encrypt ou certificat fourni) et
MariaDB sur un réseau interne. Voir [docs/deploiement-linux.md](docs/deploiement-linux.md).

---

## Production (hébergement Plesk)

```powershell
.\techindex.cmd plesk-deploy -Release latest -Target utilisateur@serveur.example.com
```

Sans Docker sur le serveur : la version publiée et signée est téléchargée par
le serveur, puis installée ou mise à jour sous l'utilisateur de l'abonnement
Plesk (sans root), avec sauvegarde préalable et retour arrière. PHP, MariaDB,
HTTPS et la tâche planifiée sont ceux de Plesk. Les mises à jour suivantes se
lancent aussi depuis *Administration > Mises à jour*. Voir
[docs/deploiement-plesk.md](docs/deploiement-plesk.md).

---

## Publier une version

```powershell
.\techindex.cmd release-init -Repository votre-compte/techindex   # une seule fois
.\techindex.cmd release minor                                      # puis à chaque version
```

Le code est versionné avec Git et publié sur un dépôt GitHub public. `release`
contrôle le dépôt, lance les tests, met à jour `VERSION` et `CHANGELOG.md`,
crée le commit et l'étiquette `vX.Y.Z`, puis envoie le tout : GitHub Actions
rejoue les contrôles, construit l'archive Plesk et la publie avec un manifeste
signé. Toutes les installations la proposent ensuite. Voir
[docs/publication-et-mises-a-jour.md](docs/publication-et-mises-a-jour.md).

---

## Tests

```powershell
.\techindex.cmd test
```

Les tests s'exécutent sur la base dédiée `techindex_test`, distincte de la base
de travail, et refusent toute base dont le nom ne se termine pas par `_test`.
Voir [docs/tests.md](docs/tests.md).

---

## Documentation

| Document | Contenu |
|----------|---------|
| [docs/architecture.md](docs/architecture.md) | Architecture, modèle de données, choix techniques et versions |
| [docs/administration.md](docs/administration.md) | Utilisateurs, rôles et droits, double authentification, e-mail, imports, exports, audit |
| [docs/deploiement-linux.md](docs/deploiement-linux.md) | Ressources, ports, domaine, paramètres, HTTPS, installation serveur |
| [docs/deploiement-plesk.md](docs/deploiement-plesk.md) | Hébergement Plesk : autorisations de l'abonnement, réglages Plesk, installation, exploitation, dépannage |
| [docs/sauvegarde-restauration.md](docs/sauvegarde-restauration.md) | Sauvegarde, chiffrement, copie hors serveur, vérification, restauration |
| [docs/secrets-et-cles.md](docs/secrets-et-cles.md) | Chiffrement des secrets, gestion, sauvegarde et rotation des clés |
| [docs/publication-et-mises-a-jour.md](docs/publication-et-mises-a-jour.md) | Git et GitHub, publication des versions signées, mise à jour de la production |
| [docs/mise-a-jour.md](docs/mise-a-jour.md) | Mise à jour, migrations, retour arrière |
| [docs/tests.md](docs/tests.md) | Lancer les tests, ce qu'ils couvrent |
| [docs/fonctionnalites-et-limites.md](docs/fonctionnalites-et-limites.md) | Fonctionnalités terminées, limites connues, éléments non vérifiés |

---

## Sécurité en bref

- Les secrets clients (mots de passe d'accès, clés) sont chiffrés en AES-256-GCM
  avec une clé dédiée (`SECRETS_KEY`), distincte de `APP_KEY`. Ils sont masqués
  par défaut et ne sont envoyés au navigateur qu'à la demande. Chaque
  révélation exige le droit « secrets » et un déverrouillage par clé d'accès,
  et elle est journalisée sans la valeur.
- Les mots de passe (gestionnaire et fiches) ne s'ouvrent qu'avec une clé
  d'accès (Windows Hello, téléphone, clé de sécurité), et se reverrouillent
  5 minutes après l'ouverture, même en cas d'activité.
- Les contrôles d'accès sont faits côté serveur sur chaque route, téléchargement,
  recherche et export. Un client non attribué répond « introuvable » (404).
- Les comptes sont créés sans mot de passe par défaut connu : pas
  d'inscription publique, double authentification par code TOTP (avec codes
  de secours) ou par clé d'accès, limitation des tentatives de connexion.
- La désactivation d'un utilisateur révoque immédiatement ses sessions.
- Les pièces jointes sont stockées hors de la racine web sous un nom généré, et
  leur type est vérifié sur le contenu réel du fichier.
- Le HTML est filtré à l'enregistrement et à l'affichage.
- Les en-têtes de sécurité sont envoyés, avec une CSP à nonce.
- Les exports sont protégés contre l'injection de formules et ne contiennent
  jamais de secrets.
- L'audit couvre les connexions, les modifications, les changements de droits,
  les révélations de secrets, les imports et les exports, sans jamais
  enregistrer de valeur secrète.

Signalez toute vulnérabilité à l'administrateur de votre installation.
