# Publication des versions et mises à jour

Ce document décrit le circuit complet, du poste de développement aux
installations en production :

```
Poste de développement                 GitHub (dépôt public)                       Installations
──────────────────────                 ─────────────────────                       ─────────────
modifications, commits      ──push──►  Tests à chaque envoi (Actions)
.\techindex.cmd release     ──tag───►  Publication : tests, archive Plesk,    ◄──  Plesk : Administration > Mises à jour,
                                       manifeste signé, version GitHub              ou ./techindex-plesk.sh update
.\techindex.cmd plesk-deploy ─SSH────────────────────────────────────────────►    Plesk (mise à jour immédiate)
                                                                              ◄──  Docker : sudo ./scripts/techindex.sh update
```

Principes :

- **Le code de production n'est jamais construit sur le serveur.** GitHub
  Actions construit chaque version une fois, après la réussite de tous les
  contrôles ; toutes les installations reçoivent exactement les mêmes fichiers.
- **Chaque version est signée.** Le manifeste `release.json` contient
  l'empreinte de l'archive et il est signé (Ed25519) avec une clé privée
  connue de vous et de GitHub seulement. Les installations vérifient cette
  signature avec la clé publique livrée dans le code
  (`config/release-channel.json`). Une version modifiée, ou publiée par
  quelqu'un d'autre, est refusée.
- **Une mise à jour ne casse rien.** Elle commence par une sauvegarde
  complète, met le site en maintenance, applique les migrations de la base,
  puis remet le site en service. En cas d'échec avant le changement de
  version, l'ancienne reste en service ; après, le retour arrière reste
  possible (voir [mise-a-jour.md](mise-a-jour.md)).
- **Pas de retour silencieux à une version antérieure.** Une version plus
  ancienne que celle installée est refusée ; le retour arrière est une
  commande explicite.

## 1. Mise en place (une seule fois)

### Prérequis du poste

- Docker Desktop (déjà utilisé pour le développement) ;
- [Git pour Windows](https://git-scm.com/download/win), avec votre identité :
  ```powershell
  git config --global user.name "Prénom Nom"
  git config --global user.email "vous@exemple.fr"
  ```
- un compte GitHub, avec la **double authentification activée** : quiconque
  publie sur ce dépôt peut diffuser du code sur toutes les installations.

### Préparer le dépôt et la clé de signature

```powershell
.\techindex.cmd release-init -Repository votre-compte/techindex
```

La commande :

1. crée le dépôt Git local (branche `main`) s'il n'existe pas ;
2. génère la paire de clés de signature des versions :
   - la clé **publique** est écrite dans `config/release-channel.json` (avec le
     nom du dépôt) et sera publiée avec le code ;
   - la clé **privée** est écrite dans
     `%USERPROFILE%\.techindex\release-signing-key.txt`, lisible par votre
     seul compte Windows, et **jamais** dans le projet ;
3. déclare le dépôt distant `origin` (`https://github.com/votre-compte/techindex.git`) ;
4. crée le premier commit, après avoir vérifié qu'aucun fichier sensible
   (`.env`, clés, sauvegardes, archives) n'y figure ;
5. affiche les étapes à faire sur GitHub, puis propose d'envoyer le code.

La commande peut être relancée sans risque : une clé existante n'est jamais
remplacée.

### Côté GitHub

1. **Créez le dépôt** : <https://github.com/new>
   - nom : celui indiqué à `release-init` ;
   - visibilité : **Public** ;
   - **sans** README, licence ni `.gitignore` (le dépôt doit être vide).
2. **Ajoutez le secret de signature** : dans le dépôt, *Settings > Secrets and
   variables > Actions > New repository secret* :
   - nom : `TECHINDEX_SIGNING_KEY` ;
   - valeur : le contenu du fichier `%USERPROFILE%\.techindex\release-signing-key.txt`
     (une ligne, ouvrez-le avec le Bloc-notes).
3. **Envoyez le code** (si vous ne l'avez pas fait à la fin de `release-init`) :
   ```powershell
   git push -u origin main
   ```
   À la première connexion, Git ouvre le navigateur pour vous authentifier
   auprès de GitHub.
4. **Vérifiez les Actions** : onglet *Actions* du dépôt. Le workflow « Tests »
   doit être vert. Dans *Settings > Actions > General*, gardez *Allow all
   actions* (ou au minimum les actions de GitHub) et l'approbation requise
   pour les contributions externes.
5. **Protégez la publication** (recommandé) : *Settings > Rules > Rulesets* :
   - une règle sur la branche `main` qui interdit la suppression et la
     réécriture de l'historique (*Restrict deletions*, *Block force pushes*) ;
   - une règle sur les étiquettes `v*` qui interdit leur suppression et leur
     modification : une version publiée ne change plus.
6. **Publiez la première version** :
   ```powershell
   .\techindex.cmd release 1.0.0
   ```

### Sauvegarder la clé privée de signature

Copiez `release-signing-key.txt` dans votre gestionnaire de mots de passe ou
votre coffre, à côté des clés de vos installations. Elle est aussi dans les
secrets GitHub, mais ceux-ci ne sont pas relisibles.

| Situation | Conséquence | Solution |
|-----------|-------------|----------|
| Clé privée perdue, secret GitHub intact | Les publications continuent | Récupérez la clé dans votre coffre ; sinon, rotation (ci-dessous) |
| Secret GitHub supprimé | Les publications échouent (« Secret TECHINDEX_SIGNING_KEY absent ») | Recréez le secret à partir de votre coffre |
| Clé privée compromise | Une version piégée pourrait être signée | Rotation immédiate, puis vérification des installations |

**Rotation de la clé** : une installation vérifie chaque version avec la clé
publique de la version qu'elle a déjà. La nouvelle clé est donc publiée par
une version encore signée avec l'ancienne :

1. mettez l'ancienne clé privée de côté (coffre), videz la clé publique de
   `config/release-channel.json` (valeur `""`), déplacez le fichier de clé
   privée, puis relancez `release-init` : une nouvelle paire est créée ;
2. publiez une version (`release`) **sans toucher au secret GitHub** : elle
   est signée par l'ancienne clé et apporte la nouvelle clé publique ;
3. une fois cette version publiée, remplacez le secret `TECHINDEX_SIGNING_KEY`
   par la nouvelle clé privée. Les versions suivantes sont signées avec elle.

Une installation restée sur une version antérieure à la rotation installe
d'abord la version de rotation (`--version X.Y.Z`), puis les suivantes. Si
l'ancienne clé est perdue ou compromise, mettez à jour chaque installation une
fois par un déploiement forcé (§ 4).

## 2. Travailler au quotidien avec Git

| Action | Commande |
|--------|----------|
| Voir ce qui a changé | `git status` puis `git diff` |
| Enregistrer un ensemble de modifications | `git add -A` puis `git commit -m "Ajoute l'export des licences"` |
| Envoyer sur GitHub (lance les tests) | `git push` |
| Récupérer le travail d'un autre poste | `git pull --rebase` |
| Mettre de côté un travail en cours | `git stash`, puis `git stash pop` |
| Annuler les modifications d'un fichier non enregistré | `git restore chemin/du/fichier` |
| Historique | `git log --oneline` |

Bonnes pratiques :

- **Un commit = une modification cohérente**, avec un message court à
  l'infinitif ou à l'indicatif, en français : « Ajoute… », « Corrige… »,
  « Améliore… ». Ces messages deviennent les notes de version si le journal
  des modifications n'est pas rempli à la main.
- **Lancez les tests avant d'envoyer** : `.\techindex.cmd test`. GitHub les
  relance à chaque envoi ; une croix rouge dans l'onglet *Actions* signale un
  problème à corriger avant de publier.
- **Travail long ou risqué** : créez une branche (`git switch -c ma-fonction`),
  envoyez-la (`git push -u origin ma-fonction`), ouvrez une *pull request* sur
  GitHub : les tests s'exécutent, puis fusionnez-la dans `main`. Les versions
  se publient toujours depuis `main`.
- **Jamais de secret dans Git** : `.env`, clés, sauvegardes et archives sont
  exclus par `.gitignore`, et `release-init` vérifie le premier envoi. Si un
  secret a été publié par erreur, considérez-le comme compromis et
  changez-le : l'historique d'un dépôt public a pu être copié.
- **Base de données** : toute évolution passe par une nouvelle migration
  (`database/migrations`). Ne modifiez jamais une migration déjà publiée : les
  installations l'ont déjà exécutée et ne la rejoueraient pas. Préférez des
  changements compatibles avec la version précédente (ajouter une colonne,
  puis supprimer l'ancienne dans une version suivante) : le retour arrière
  reste alors possible sans restauration.
- Facultatif : notez au fil de l'eau les évolutions visibles dans
  `CHANGELOG.md`, section « Non publié ».

## 3. Publier une version

```powershell
.\techindex.cmd release            # correctif : 1.4.2 → 1.4.3
.\techindex.cmd release minor      # fonctionnalité : 1.4.2 → 1.5.0
.\techindex.cmd release major      # changement majeur : 1.4.2 → 2.0.0
.\techindex.cmd release 1.5.0-rc.1 # préversion (non proposée aux installations)
```

Le numéro suit le [versionnage sémantique](https://semver.org/lang/fr/) :
**MAJEUR.MINEUR.CORRECTIF**.

La commande, qui s'arrête à la moindre anomalie :

1. vérifie que vous êtes sur `main`, que tout est enregistré (commit) et que
   `main` n'est pas en retard sur GitHub ;
2. calcule la nouvelle version, qui doit être supérieure à la dernière publiée ;
3. prépare les notes : section « Non publié » de `CHANGELOG.md`, ou à défaut
   les messages des commits depuis la version précédente ;
4. signale les migrations de base de données de cette version ;
5. lance les tests (`-SkipTests` pour les ignorer : GitHub les relance) ;
6. demande confirmation (`-Yes` pour s'en passer) ;
7. met à jour `VERSION` et `CHANGELOG.md`, crée le commit « Version X.Y.Z » et
   l'étiquette `vX.Y.Z` ;
8. envoie le tout sur GitHub en une seule opération.

GitHub Actions prend ensuite le relais (5 à 10 minutes, onglet *Actions*,
workflow « Publication ») :

1. rejoue tous les contrôles (tests sur MariaDB, typage, compilation, style, scripts) ;
2. vérifie que `VERSION` correspond à l'étiquette ;
3. construit l'archive Plesk ;
4. produit le manifeste `release.json`, le signe avec `TECHINDEX_SIGNING_KEY`
   et vérifie la signature avec la clé publique du dépôt ;
5. crée la version sur GitHub (*Releases*) avec l'archive, son empreinte, le
   script `techindex-plesk.sh`, le manifeste et sa signature.

Une préversion (`-rc.1`, `-beta.1`) est publiée comme *pre-release* : elle
n'est pas proposée automatiquement, mais s'installe explicitement sur une
installation de recette (`./techindex-plesk.sh update --version 1.5.0-rc.1`).

Si la publication échoue sur GitHub (test rouge, secret absent), corrigez,
puis publiez la version suivante : une étiquette déjà envoyée ne se réutilise pas.

## 4. Mettre à jour la production

### Depuis l'administration (Plesk)

*Administration > Mises à jour* : le bouton **Rechercher une mise à jour**
interroge GitHub (le serveur le fait aussi chaque nuit, et le menu affiche
« Nouvelle » quand une version est disponible). La page présente la version,
ses notes et indique si elle modifie la base. **Installer la version X.Y.Z**
demande une confirmation de l'identité, puis la tâche planifiée lance la mise
à jour dans la minute ; la page suit son avancement et affiche le journal.
Pendant la mise en service (moins d'une minute en général), le site est en
maintenance.

### Depuis le poste, par SSH (Plesk)

```powershell
.\techindex.cmd plesk-deploy -Release latest     # dernière version publiée
.\techindex.cmd plesk-deploy -Release 1.5.0      # version précise
.\techindex.cmd release minor -Deploy            # publier, attendre GitHub, puis mettre à jour
```

Le serveur télécharge lui-même la version sur GitHub et vérifie sa signature :
rien ne transite par le poste. Pour éviter de retaper la cible, ajoutez dans
le `.env` du poste :

```ini
PLESK_DEPLOY_TARGET=utilisateur@serveur.example.com
PLESK_DEPLOY_PATH=techindex
```

Pour un port ou une clé SSH particuliers, utilisez un alias de
`%USERPROFILE%\.ssh\config` (voir [deploiement-plesk.md](deploiement-plesk.md), § 4).

### Déploiement forcé d'une version non publiée

```powershell
.\techindex.cmd plesk-deploy
```

Sans `-Release`, l'archive est construite sur le poste à partir du code
actuel (version `X.Y.Z-dev.<date>`), envoyée par SSH puis installée, avec la
même procédure (sauvegarde, maintenance, migrations). À réserver à un serveur
de test ou à une urgence : la production doit normalement recevoir des
versions publiées. La version publiée suivante remplacera ce déploiement
normalement.

### Serveur Linux avec Docker

```bash
sudo ./scripts/techindex.sh check-update
sudo ./scripts/techindex.sh update            # dernière version publiée
sudo ./scripts/techindex.sh update 1.5.0      # version précise
```

Le serveur vérifie le manifeste signé, place le code sur le commit de
l'étiquette correspondante (en contrôlant qu'ils concordent), puis reconstruit
les images. L'administration affiche la version disponible et la commande à
lancer.

## 5. Dépannage

| Symptôme | Cause | Solution |
|----------|-------|----------|
| `release` : « modifications non enregistrées » | Fichiers modifiés hors commit | `git add -A` puis `git commit -m "…"`, ou `git stash` |
| `release` : « retard sur GitHub » | Commits envoyés depuis un autre poste | `git pull --rebase` |
| `release` : envoi refusé | Authentification GitHub, ou règle de protection | Reconnectez-vous ; le commit et l'étiquette sont prêts localement : `git push --atomic origin main vX.Y.Z` |
| Actions : « Secret TECHINDEX_SIGNING_KEY absent » | Secret non créé | § 1, côté GitHub, étape 2, puis publiez la version suivante |
| Actions : « La clé privée ne correspond pas » | Secret d'une autre paire de clés | Remettez le contenu de `release-signing-key.txt` |
| Installation : « Signature invalide » | Version altérée, ou signée par une autre clé | Ne pas installer ; vérifiez la publication sur GitHub et la clé de `config/release-channel.json` |
| Installation : « Dépôt injoignable » | Pas d'accès sortant à github.com | Autorisez `github.com` et `*.githubusercontent.com` en HTTPS, ou déploiement forcé |
| Installation : « plus ancienne que la version installée » | Retour en arrière demandé | `./techindex-plesk.sh rollback` |
| Installation : « Une autre opération est en cours » | Mise à jour, retour arrière ou restauration déjà lancé | Attendre ; `./techindex-plesk.sh status` |
