# Mise à jour et retour arrière

## 1. Principes

- **Les installations reçoivent des versions publiées**, construites et
  signées par GitHub (voir [publication-et-mises-a-jour.md](publication-et-mises-a-jour.md)).
  Une version dont la signature ne correspond pas à la clé publique livrée
  avec le code est refusée, de même qu'une version plus ancienne que celle
  installée.
- **Une sauvegarde précède toute mise à jour** : le script la crée et
  s'arrête si elle échoue.
- **Les migrations ne sont pas toujours réversibles.** Une migration qui
  supprime une colonne ou transforme des données ne peut pas être annulée sans
  perte. Le retour arrière fiable repose donc sur la **sauvegarde préalable**,
  et non sur `migrate:rollback`.
- **Chaque version produit des images Docker distinctes**, étiquetées par date
  (`TECHINDEX_VERSION`). L'image précédente reste disponible pour revenir en arrière.
- **Testez d'abord sur une copie** : restaurez une sauvegarde récente sur un
  poste ou un serveur de test, puis appliquez la mise à jour.

## 2. Serveur Linux

```bash
cd /opt/techindex
sudo ./scripts/techindex.sh check-update     # version disponible ?
sudo ./scripts/techindex.sh update           # dernière version publiée
sudo ./scripts/techindex.sh update 1.4.0     # version précise
```

L'administration (*Mises à jour*) affiche aussi la version disponible et cette
commande.

Déroulement :

1. lecture du manifeste de la version sur GitHub et vérification de sa
   signature, avec le PHP de l'image en service (aucun changement si
   TechIndex est déjà à jour, refus si la version est plus ancienne) ;
2. sauvegarde complète, obligatoire, et mémorisation de son nom dans
   `.deploy/pre-update-backup` ;
3. mémorisation de la version en service dans `.deploy/previous-version` ;
4. récupération des étiquettes Git, contrôle que l'étiquette `vX.Y.Z` désigne
   bien le commit indiqué par le manifeste signé, puis passage du code sur ce
   commit. Des fichiers modifiés sur le serveur bloquent la mise à jour.
   Sans canal configuré (`config/release-channel.json` vide), le code suit la
   branche : `git pull --ff-only` ;
5. construction des nouvelles images (`--pull` pour les correctifs des images de base) ;
6. mise en maintenance du site, puis arrêt du worker et du planificateur ;
7. migrations et données de référence ;
8. démarrage de la nouvelle version, contrôle de santé et levée de la maintenance.

L'interruption de service dure généralement moins d'une minute, hors
construction des images qui se fait site en ligne.

**Sans Git** : remplacez le contenu du dossier par la nouvelle version **en
conservant** :

- `.env` ;
- `backups/` ;
- `docker/certs/` ;
- `.deploy/`.

Lancez ensuite `update`.

Après la mise à jour :

```bash
sudo ./scripts/techindex.sh doctor
sudo ./scripts/techindex.sh logs app      # quelques minutes, à la recherche d'erreurs
```

## 3. Retour arrière

### Cas 1 : la mise à jour ne comportait pas de migration de base

Exemples : correctif d'interface, changement de code uniquement.

```bash
sudo ./scripts/techindex.sh rollback
```

Les services redémarrent sur l'image précédente. Les données saisies depuis la
mise à jour sont conservées.

### Cas 2 : la mise à jour comportait des migrations

Un retour à l'ancien code sur le nouveau schéma peut échouer ou corrompre des
données. Revenez donc à l'image précédente **et** restaurez la sauvegarde
préalable :

```bash
sudo ./scripts/techindex.sh rollback
sudo ./scripts/techindex.sh restore "$(sudo cat .deploy/pre-update-backup)"
```

Les données saisies entre la mise à jour et la restauration sont **perdues** :
notez-les ou exportez-les avant.

N'utilisez `artisan migrate:rollback` que si les notes de version indiquent
explicitement que la migration est réversible sans perte.

Pour savoir si une version contient des migrations, avant la mise à jour :
`check-update` (ou la page *Mises à jour*) l'indique (« modifie la base de
données : oui/non »), tout comme les notes de la version sur GitHub.

Après la mise à jour :

```bash
sudo ./scripts/techindex.sh artisan migrate:status
```

### Nettoyage des anciennes images

Les images de chaque version restent sur le disque. Une fois la nouvelle
version validée, supprimez les plus anciennes en gardant au moins la précédente :

```bash
docker image ls techindex-app
docker image rm techindex-app:<ancienne-version> techindex-web:<ancienne-version>
```

## 4. Hébergement Plesk

Voir aussi [deploiement-plesk.md](deploiement-plesk.md). Trois façons de
lancer la même mise à jour :

| Depuis | Commande |
|--------|----------|
| L'administration | *Administration > Mises à jour* > *Rechercher une mise à jour*, puis *Installer la version X.Y.Z* (confirmation de l'identité) |
| Le serveur, en SSH | `./techindex-plesk.sh update` (dernière version), `update --version 1.4.0` |
| Le poste de développement | `.\techindex.cmd plesk-deploy -Release latest` (ou `-Release 1.4.0`) |

Pour savoir si une version est disponible : `./techindex-plesk.sh check-update`.
Une archive construite sur le poste s'installe aussi
(`.\techindex.cmd plesk-deploy` sans `-Release`, ou
`./techindex-plesk.sh update archives/<archive>`) : c'est le déploiement forcé,
réservé aux tests et aux urgences.

Déroulement, site en service jusqu'à l'étape 5 :

1. verrou : une seule opération à la fois (mise à jour, retour arrière,
   restauration) ; vérification de l'hébergement ;
2. téléchargement du manifeste de la version, **vérification de sa
   signature**, refus d'une version plus ancienne que celle installée, puis
   téléchargement de l'archive et contrôle de son empreinte SHA-256 ;
3. extraction de la nouvelle version à côté de l'ancienne, puis contrôle
   qu'elle démarre avec le PHP du serveur (version, extensions) ;
4. attente de la fin d'un import ou d'une sauvegarde en cours, puis
   sauvegarde complète obligatoire, mémorisée dans `.deploy/pre-update-backup` ;
5. mise en maintenance et arrêt des traitements de fond ;
6. échange des dossiers : l'ancienne version part dans `releases/`, la
   nouvelle devient `app/` (le chemin de la racine du document ne change pas) ;
7. migrations, données de référence et caches ;
8. levée de la maintenance, contrôle de `https://<domaine>/up`, nettoyage
   (deux versions précédentes et trois archives conservées).

L'interruption de service dure généralement moins d'une minute. Un échec
avant l'étape 6 laisse l'ancienne version en service ; après l'étape 6, le
site reste en maintenance et le script indique la marche à suivre. Chaque
étape est inscrite dans `shared/storage/app/private/updates/status.json` et
dans un journal, affichés par la page *Mises à jour* ; l'issue est enregistrée
dans le journal d'audit (« Mise à jour de l'application »). Le script
`techindex-plesk.sh` du dossier de déploiement est remplacé par celui de la
nouvelle version.

Retour arrière, avec les mêmes précautions qu'au § 3 :

```bash
./techindex-plesk.sh rollback
./techindex-plesk.sh restore "$(cat .deploy/pre-update-backup)"   # si la base a été modifiée
```

`rollback` remet en service la version précédente conservée dans
`releases/` ; la version abandonnée y reste. Pour revenir ensuite à la
version la plus récente : `./techindex-plesk.sh update`.

### Dépannage d'une mise à jour lancée depuis l'administration

La page *Mises à jour* ne reste jamais en attente indéfiniment :

| Affichage | Cause et remède |
|-----------|-----------------|
| « La tâche planifiée ne s'exécute pas » sous la demande | La tâche Plesk `schedule:run` (chaque minute) est absente, désactivée ou en erreur : voir [deploiement-plesk.md](deploiement-plesk.md), § tâche planifiée. Annulez la demande ou lancez `./techindex-plesk.sh update` en SSH. Sans prise en charge, la demande est abandonnée au bout de 10 minutes. |
| Échec « Le script de mise à jour s'est arrêté avant de démarrer : … » | Le script n'a pas pu s'exécuter (bash, PHP ou droits) ; la fin du message reprend son erreur, également dans `shared/storage/app/private/updates/launch.log`. |
| Échec « Le script de mise à jour n'a pas démarré » | Le script lancé n'a rien signalé en 10 minutes (processus arrêté par l'hébergeur). Lancez la mise à jour en SSH. |

Versions 1.0.3 et antérieures : la fenêtre de confirmation de l'identité pouvait s'ouvrir
derrière celle de l'installation (bouton qui tourne sans fin). Pour installer
la version qui corrige ce défaut, actualisez la page *Mises à jour* (F5) avant
de cliquer sur *Installer*, ou mettez à jour une fois en SSH.

## 5. Poste Windows

```powershell
.\techindex.cmd update
```

La commande enchaîne :

- une sauvegarde ;
- la reconstruction des images ;
- `composer install` et `npm ci` avec les versions verrouillées, puis la
  recompilation de l'interface ;
- les migrations et le redémarrage.

Pour revenir en arrière, restaurez le code précédent (Git), puis la sauvegarde
créée au début de la mise à jour avec `restore`.

## 6. Monter de version les dépendances et les images

Les versions sont figées volontairement :

- `composer.lock` et `package-lock.json` pour les dépendances ;
- les tags précis des images Docker (`Dockerfile`, `compose.yaml`,
  `compose.prod.yaml`).

Un tag figé (par exemple `php:8.5.11-fpm-trixie`) ne reçoit pas les correctifs
publiés ensuite. Prévoyez donc une revue régulière, au moins mensuelle, et
immédiate en cas d'alerte de sécurité.

Sur le poste de développement :

```powershell
# 1. Vulnérabilités connues des dépendances
docker compose run --rm app composer audit
docker compose run --rm node npm audit --omit=dev

# 2. Mise à jour mineure des dépendances PHP et JavaScript
docker compose run --rm app composer update --with-all-dependencies
docker compose run --rm node npm update

# 3. Images Docker : modifier les tags dans Dockerfile, compose.yaml et compose.prod.yaml
#    (PHP_IMAGE, NODE_IMAGE, COMPOSER_IMAGE, NGINX_IMAGE, mariadb, certbot, mailpit ;
#    GO_IMAGE et AGE_VERSION pour l'archive Plesk)

# 4. Vérifier
.\techindex.cmd update
.\techindex.cmd test
```

Validez ensuite le résultat (fichiers de verrouillage et tags) dans Git
(`git commit`), puis publiez une version (`.\techindex.cmd release`) : les
installations la recevront comme toute autre mise à jour.

Précautions particulières :

- **MariaDB** : restez sur une branche LTS (11.8 actuellement). Un changement
  de version majeure exige une sauvegarde préalable. `MARIADB_AUTO_UPGRADE=1`
  applique `mariadb-upgrade` au démarrage, mais une version majeure ne peut
  pas être rétrogradée sans restauration.
- **PHP et Laravel** : lisez le guide de mise à niveau Laravel avant une
  version majeure ; la suite de tests couvre les fonctions critiques.
- **TypeScript** : `vue-tsc` 3.3 ne prend pas en charge TypeScript 7 ; gardez
  TypeScript 6 tant que `vue-tsc` n'est pas compatible.
