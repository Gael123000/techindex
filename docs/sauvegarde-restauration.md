# Sauvegarde et restauration

## 1. Ce qui est sauvegardé, et ce qui ne l'est pas

Une archive `techindex-backup-AAAAMMJJ-HHMMSS.zip` (ou `.zip.age` si elle est
chiffrée) contient :

| Élément | Contenu |
|---------|---------|
| `database.sql` | Export cohérent de MariaDB (`mariadb-dump --single-transaction`), avec toutes les tables |
| `files/` | Pièces jointes et logos (stockage privé) |
| `env.redacted` | Paramètres de fonctionnement, **sans** clés ni mots de passe (pour reconstruire `.env`) |
| `manifest.json` | Date, version de MariaDB, dernière migration, nombre de lignes par table, empreinte SHA-256 de chaque fichier, **empreintes** des clés (jamais les clés) |

Les secrets clients sont dans `database.sql` **sous forme chiffrée**. Les clés
de déchiffrement (`APP_KEY`, `SECRETS_KEY`) **ne sont jamais incluses** : elles
se sauvegardent séparément (voir [secrets-et-cles.md](secrets-et-cles.md)).
Ainsi, une archive volée ne donne pas accès aux secrets, et une archive seule
ne suffit pas à tout restaurer.

> **À conserver, séparément :**
>
> 1. les archives de sauvegarde, idéalement chiffrées ;
> 2. le fichier des clés (`keys-export`), dans un coffre ;
> 3. la clé privée `age`, si les sauvegardes sont chiffrées, dans un coffre.
>
> Les éléments 2 et 3 ne doivent **pas** être stockés uniquement avec les
> sauvegardes, ni sur le serveur.

## 2. Créer une sauvegarde

```powershell
.\techindex.cmd backup
```

```bash
sudo ./scripts/techindex.sh backup
```

L'archive est créée dans `./backups` avec les droits `600`. Les
`BACKUP_KEEP` archives les plus récentes sont conservées (14 par défaut).

**Sauvegarde automatique** : `BACKUP_DAILY_AT=01:30` dans `.env`. Ce réglage
est activé par défaut en production ; l'heure suit `APP_DISPLAY_TIMEZONE`. Le
planificateur crée alors une archive chaque nuit.

Chaque sauvegarde est enregistrée dans l'audit (`backup.created`).

## 3. Chiffrer les sauvegardes (recommandé)

Le chiffrement utilise [age](https://age-encryption.org), un outil standard et
audité. L'archive est chiffrée pour une **clé publique**. La **clé privée**,
seule capable de la déchiffrer, n'est jamais stockée sur le serveur.

Sur le serveur :

```bash
sudo ./scripts/techindex.sh backup-keygen
```

La commande :

- génère la paire de clés ;
- enregistre la clé publique dans `.env` (`BACKUP_AGE_RECIPIENT=age1...`) ;
- laisse la clé privée dans `age-key-AAAAMMJJ.txt`.

**Déplacez immédiatement ce fichier hors du serveur** (coffre, gestionnaire de
mots de passe, poste d'administration), puis supprimez-le du serveur :

```bash
sudo shred -u age-key-*.txt
```

Sous Windows, ou avec une paire de clés existante, renseignez directement
`BACKUP_AGE_RECIPIENT` avec la clé publique.

Les sauvegardes suivantes portent l'extension `.zip.age`.

## 4. Copier les sauvegardes hors du serveur

Une sauvegarde laissée sur le serveur ne protège ni d'une panne de disque, ni
d'un rançongiciel, ni d'une perte du serveur. Organisez une copie automatique,
par exemple :

- **Tirage depuis un serveur de sauvegarde** (recommandé : le serveur
  TechIndex ne détient alors aucun accès au stockage de sauvegarde) :
  ```bash
  # Sur le serveur de sauvegarde, tâche cron quotidienne :
  rsync -a --ignore-existing sauvegarde@techindex.example.com:/opt/techindex/backups/ /srv/sauvegardes/techindex/
  ```
- **Envoi vers un stockage objet** (S3, Azure Blob, OVH Object Storage...) avec
  `rclone copy`, en utilisant une clé d'accès limitée à l'écriture si possible.
- **Copie manuelle** sur un support externe, au minimum chaque semaine.

Les archives chiffrées avec `age` peuvent être déposées sur un stockage tiers
sans exposer leur contenu.

Conseil : conservez plusieurs générations (quotidienne, hebdomadaire,
mensuelle) et au moins une copie hors ligne ou non modifiable.

## 5. Vérifier une sauvegarde (test de restauration)

```powershell
.\techindex.cmd verify-backup .\backups\techindex-backup-20261014-013000.zip
```

```bash
sudo ./scripts/techindex.sh verify-backup techindex-backup-20261014-013000.zip.age --identity /backups/age-key.txt
```

Pour une archive chiffrée, la clé privée `age` doit être copiée
**temporairement** dans `./backups`, lisible par l'application, puis retirée :

```bash
sudo install -o 33 -g 33 -m 600 /chemin/vers/age-key.txt backups/age-key.txt
# ... verify-backup ou restore ...
sudo shred -u backups/age-key.txt
```

La vérification **ne touche pas aux données** en service. Elle :

1. déchiffre l'archive si besoin ;
2. contrôle le manifeste et l'empreinte de `database.sql` ;
3. compare les empreintes des clés installées à celles de la sauvegarde ;
4. restaure la base dans une base temporaire `<base>_restore_check`, puis la supprime ;
5. compare le nombre de lignes de chaque table au manifeste ;
6. vérifie la présence et l'intégrité de chaque pièce jointe ;
7. essaie de déchiffrer chaque secret avec les clés installées.

Planifiez une vérification au moins une fois par mois, et après chaque
changement de clé ou mise à jour importante.

## 6. Restaurer

> La restauration **remplace** toutes les données actuelles (base et pièces
> jointes). Créez d'abord une sauvegarde de l'état actuel si celui-ci a encore
> de la valeur.

1. Placez l'archive (et, si elle est chiffrée, la clé privée `age`) dans
   `./backups`. Sous Linux, les fichiers doivent être lisibles par l'application :
   `sudo install -o 33 -g 33 -m 600 <fichier> backups/`.
2. Vérifiez que `.env` contient les **mêmes** `APP_KEY` et `SECRETS_KEY` que
   lors de la sauvegarde. La restauration compare les empreintes et refuse de
   continuer en cas de différence.
3. Lancez :

   ```powershell
   .\techindex.cmd restore .\backups\techindex-backup-20261014-013000.zip
   ```

   ```bash
   sudo ./scripts/techindex.sh restore techindex-backup-20261014-013000.zip.age --identity /backups/age-key.txt
   ```

Déroulement :

1. Le site passe en maintenance ; le worker et le planificateur sont arrêtés
   (sous Linux).
2. Tout est contrôlé **avant** la moindre suppression : déchiffrement,
   empreinte SQL, chemin et empreinte de chaque fichier, et clés.
3. La base est remplacée, puis les pièces jointes.
4. Les migrations sont appliquées, dans le cas d'une sauvegarde plus ancienne
   que le code.
5. Un contrôle final vérifie que les secrets sont déchiffrables, puis les
   services redémarrent.

L'option `--ignore-keys` (commande `artisan techindex:restore`) restaure malgré
des clés différentes. Les secrets et la double authentification deviennent
alors inutilisables : à réserver au cas où les clés sont définitivement perdues.

## 7. Restaurer sur une installation vierge (nouveau serveur ou poste)

C'est la procédure de reprise après sinistre. Elle a été testée, voir § 8.

1. Installez TechIndex normalement :
   - **Linux** : copiez le code, puis préparez `.env` **avant** de lancer
     l'installation (étape 2) ;
   - **Windows** : lancez `.\techindex.cmd install` sans créer d'administrateur
     (répondez « n »).
2. Dans `.env`, **avant** l'installation sous Linux, ou en remplaçant les
   valeurs générées sous Windows :
   - reportez `APP_KEY`, `SECRETS_KEY` et `SECRETS_PREVIOUS_KEYS` depuis le
     fichier des clés ;
   - reportez les paramètres utiles depuis `env.redacted` (domaine, SMTP,
     seuils...).

   Les mots de passe MariaDB peuvent être nouveaux : la base est recréée.
3. Si les clés ont été modifiées après l'installation, redémarrez (`restart`)
   pour qu'elles soient prises en compte.
4. Copiez l'archive dans `./backups`, puis lancez `restore` (§ 6).
5. Contrôlez :
   - `doctor` ;
   - `artisan techindex:secrets-check`, qui doit indiquer 0 secret illisible ;
   - une connexion d'administrateur avec sa double authentification ;
   - l'ouverture d'une pièce jointe ;
   - la révélation d'un secret.

Les comptes utilisateurs, les droits, la double authentification (chiffrée
avec `APP_KEY`) et l'historique sont restaurés avec la base.

## 8. Tests réalisés

Le cycle suivant a été exécuté avec succès sur MariaDB 11.8 (poste Windows,
sans Docker) :

1. création d'une sauvegarde ;
2. `backup-verify` réussi (30 tables, 1 pièce jointe, 15 secrets déchiffrables) ;
3. restauration sur une base **vierge**, pièces jointes supprimées au préalable :
   toutes les données, le fichier et 15 secrets sur 15 sont restaurés ;
4. restauration avec une `APP_KEY` différente : refusée avec un message explicite.

Le chiffrement `age` et les scripts Docker n'ont pas pu être exécutés dans
l'environnement de développement (voir [fonctionnalites-et-limites.md](fonctionnalites-et-limites.md)) :
testez-les lors de votre première installation.

## 9. Hébergement Plesk

Les archives et les contrôles sont identiques ; les commandes passent par
`techindex-plesk.sh`, dans le dossier de déploiement (voir
[deploiement-plesk.md](deploiement-plesk.md)) :

```bash
cd ~/techindex
./techindex-plesk.sh backup
./techindex-plesk.sh verify-backup techindex-backup-20261014-013000.zip.age --identity ~/age-key.txt
./techindex-plesk.sh restore techindex-backup-20261014-013000.zip.age --identity ~/age-key.txt
```

- **Emplacement** : `~/techindex/shared/backups` (droits `700`, archives en
  `600`). Un nom d'archive seul désigne un fichier de ce dossier.
- **Sauvegarde automatique** : assurée par la tâche planifiée Plesk
  (`schedule:run`), à l'heure de `BACKUP_DAILY_AT`.
- **Clés `age`** : générez de préférence la paire de clés sur le poste
  d'administration (`winget install FiloSottile.age`, puis
  `age-keygen -o techindex-age.txt`) et reportez seulement la clé publique
  dans `shared/.env` (`BACKUP_AGE_RECIPIENT`), suivie de
  `./techindex-plesk.sh reload` : la clé privée ne passe jamais par le
  serveur. `./techindex-plesk.sh backup-keygen` reste possible, avec l'outil
  `age` livré dans l'archive.
- **Clé privée pour une vérification ou une restauration** : envoyez-la
  temporairement par SFTP dans le dossier personnel (hors racine web), avec
  les droits `600`, puis supprimez-la : `shred -u ~/age-key.txt`.
- **Vérification** : l'utilisateur MariaDB d'un abonnement Plesk ne peut pas
  créer de base temporaire. Renseignez `BACKUP_VERIFY_DATABASE` avec une base
  **vide** créée dans Plesk et accessible au même utilisateur (voir
  [deploiement-plesk.md](deploiement-plesk.md), § 3). Elle est vidée avant et
  après chaque test. TechIndex refuse de l'utiliser si elle contient une table
  étrangère à l'application, ou si c'est la base de l'application elle-même.
- **Restauration** : le site passe en maintenance et le script attend la fin
  d'un import ou d'une sauvegarde en cours.
- **Copie hors du serveur** : le *Gestionnaire de sauvegarde* de Plesk peut
  envoyer chaque nuit l'abonnement (base, pièces jointes et archives
  TechIndex) vers un stockage distant (FTP, S3, etc.). Excluez-en
  `techindex/shared/.env` et protégez ces sauvegardes par un mot de passe :
  sinon, elles contiennent aussi les clés. Évitez de confier au serveur de
  sauvegarde l'accès SSH de l'abonnement, qui permet de lire ces clés.
