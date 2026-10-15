# Fonctionnalités terminées et limites

État de la première version au 14 octobre 2026.

## 1. Fonctionnalités terminées

Une fonctionnalité figure ici seulement si elle est implémentée **et**
vérifiée, par un test automatisé ou une vérification manuelle (voir
[tests.md](tests.md)).

### Tableau de bord

- Indicateurs calculés sur les données réelles et limités aux clients accessibles :
  - clients accessibles et derniers éléments modifiés ;
  - garanties, licences, abonnements, certificats et services arrivant à échéance ;
  - documents à vérifier ;
  - équipements en fin de vie ou proches de l'être.

### Clients et sites

- Création, modification, archivage et restauration, avec suppression
  définitive réservée et confirmée.
- Fiche client :
  - nom et référence interne unique (proposée automatiquement) ;
  - logo (PNG, JPEG ou WebP ; SVG refusé), description, adresse ;
  - technicien référent, statut, notes et étiquettes.
- Sites (adresses) et contacts, rattachés ou non à un site.
- Contexte client toujours visible (en-tête et onglets) et fil d'Ariane.

### Inventaire

- Équipements :
  - type, marque, modèle, numéro de série et site ;
  - statut : en service, maintenance, hors service, archivé ;
  - dates d'achat, de fin de garantie et de fin de vie ;
  - fournisseur, URL d'administration, notes et étiquettes.
- Plusieurs interfaces réseau par équipement (IP, MAC, sous-réseau, interface principale).
- Liens typés entre équipements, affichés dans les deux sens.
- Champs ajoutés avec le constructeur de fiches, éventuellement réservés à
  certains types d'équipement (condition d'affichage).
- Accès liés et pièces jointes sur la fiche de l'équipement.
- Liste globale des équipements de tous les clients accessibles, avec filtres.

### Réseaux et services

- VLAN et sous-réseaux (CIDR validé, passerelle, DNS, DHCP), avec le nombre
  d'adresses utilisées, leur usage (LAN, WAN, DMZ...) et le lien Internet qui
  dessert un réseau WAN.
- Onglet **FAI** : liens Internet du client avec l'opérateur du catalogue (logo,
  assistance, espace client, page d'état du réseau), la technologie, les
  débits, les adresses IP publiques, le raccordement WAN (mode, identifiant,
  VLAN opérateur, passerelle, DNS), le contrat (numéro client, référence de
  ligne, GTR, coût, fin d'engagement), les équipements, réseaux, documents et
  accès liés. Les anciens services « Connexion Internet » y ont été convertis.
- Services par catégorie, chacune avec ses champs propres validés côté serveur :
  - VPN, domaine et DNS ;
  - hébergement, cloud, messagerie ;
  - application métier, sauvegarde avec procédure de restauration.
- Licences, abonnements et contrats de support : quantité, fournisseur, coût,
  échéance, renouvellement automatique, préavis, équipements liés.
- Certificats : domaines, émetteur, type, dates, méthode de renouvellement, liens.

### Accès et secrets

Voir [secrets-et-cles.md](secrets-et-cles.md).

- Métadonnées (libellé, type, hôte ou URL, identifiant) séparées du secret.
- Secret chiffré en AES-256-GCM avec une clé dédiée, et masqué par défaut.
- Révélation et copie :
  - droit « secrets » et confirmation récente du mot de passe ;
  - limitation du débit et réponse `no-store` ;
  - masquage automatique ;
  - audit sans la valeur.
- Rotation de la clé avec rechiffrement, contrôle quotidien du déchiffrement,
  et contrat d'interface prêt pour un coffre externe.

### Gestionnaire de mots de passe

- Interface dédiée (menu **Mots de passe**) sur les mêmes données que les
  fiches : un accès créé depuis un équipement ou un service apparaît dans le
  gestionnaire, et inversement. Aucune copie, donc aucune désynchronisation.
- Rangement automatique : un dossier par client accessible, puis les accès
  par équipement (regroupés par site), par service, les accès généraux et les
  accès non classés.
- Dossiers et sous-dossiers libres par client (renommer, déplacer, supprimer ;
  supprimer un dossier ne supprime jamais d'accès, son contenu remonte).
- Recherche sur tous les clients (libellé, utilisateur, hôte, URL, notes ;
  jamais sur les secrets), copie de l'identifiant, révélation et copie du
  secret, déplacement groupé.
- Générateur de mots de passe (aléa cryptographique du navigateur) et
  indicateur de robustesse.
- **Coffre verrouillé** : il ne s'ouvre qu'avec une clé d'accès (vérification
  biométrique ou code PIN de l'appareil). Le mot de passe, même confirmé, ne
  suffit pas. Reverrouillage après 5 minutes d'inactivité et au bout de
  30 minutes dans tous les cas, ou manuellement. Chaque ouverture est auditée.

### Explorateur de fichiers

- Interface dédiée (menu **Fichiers**, et bouton **Dossier** de la vue
  d'ensemble d'un client) : un dossier racine par client accessible, créé
  automatiquement.
- Sous-dossiers, import de fichiers multiples et de dossiers entiers
  (arborescence recréée), glisser-déposer, renommage, déplacement groupé.
- Les pièces jointes des fiches (équipement, site, document...) arrivent à la
  racine du client avec la mention de l'élément lié, et peuvent être rangées
  sans perdre ce lien.
- Suppression d'un dossier : les fichiers déposés dans l'explorateur sont
  supprimés (droit « gestion »), ceux liés à une fiche reviennent à la racine.
- Mêmes protections que les pièces jointes : stockage privé, type vérifié sur
  le contenu, téléchargement contrôlé, audit.

### Documentation

- Base de connaissances par client : catégories, étiquettes, liens vers des
  équipements et services.
- Éditeur riche moderne : titres, listes, tableaux, code, liens, citations.
  Le HTML est filtré à l'enregistrement et à l'affichage.
- Statut (brouillon, publié, archivé), responsable, date de dernière
  vérification, périodicité de revue et alerte quand une revue est due.
- Historique des versions consultable.

### Pièces jointes

- Elles se rattachent aux clients, sites, équipements, services, licences,
  certificats et documents.
- Stockage privé sous un nom généré (UUID), avec empreinte SHA-256.
- Type vérifié sur le contenu réel, liste blanche d'extensions et taille
  limitée (20 Mo par défaut).
- Téléchargement contrôlé : affichage en ligne seulement pour les images et
  PDF sûrs, `nosniff`.

### Constructeur de fiches

- Fiches créées par un administrateur : un onglet par fiche dans chaque client,
  une vraie table SQL par fiche (nom choisi), une colonne typée par champ.
- 18 types de champs, sections de 1 à 3 colonnes, largeur des champs,
  glisser-déposer, conditions d'affichage, aperçu, liens entre fiches (avec
  la liste des fiches qui désignent un élément).
- Champs ajoutés aux fiches par défaut, dont les champs d'origine restent
  verrouillés.
- Historique champ par champ, étiquettes, pièces jointes, export CSV / Excel
  et recherche globale pour les fiches créées.

### Recherche globale

- Résultats affichés pendant la frappe sous la barre de recherche (Ctrl+K),
  navigation au clavier ; la page de recherche se met aussi à jour en direct.
- Elle porte sur les clients, sites, équipements (nom, modèle, numéro de série,
  IP, MAC), liens Internet (opérateur, référence de ligne, IP publiques),
  services, documents (contenu), fiches créées et étiquettes.
- Filtres par client, site, type et statut, avec extrait du contenu des documents.
- Droits appliqués dans la requête ; secrets jamais indexés ni recherchés.
- Champ de recherche présent dans l'en-tête de toutes les pages.

### Imports et exports

- Imports CSV et Excel pour les clients et les équipements :
  - modèles téléchargeables et correspondance des colonnes ;
  - aperçu ligne par ligne ;
  - règles de doublons explicites et mode choisi (création, mise à jour, les deux) ;
  - refus des imports partiels sans choix explicite ;
  - exécution en une transaction et rapport complet téléchargeable.
- Exports CSV et Excel de 9 jeux de données selon le droit « export » :
  - jamais de secrets ;
  - protection contre l'injection de formules.

### Utilisateurs, droits et sécurité

- Quatre rôles et cinq droits par client.
- Contrôle d'accès côté serveur partout (routes, téléchargements, recherche,
  exports, URL directes).
- Double authentification TOTP avec codes de secours, éventuellement
  obligatoire par rôle.
- Clés d'accès (WebAuthn / FIDO2 : Windows Hello, Touch ID / Face ID,
  téléphone, clés de sécurité USB ou NFC) : second facteur après le mot de
  passe, confirmation des actions sensibles, ouverture du gestionnaire de mots
  de passe.
- Comptes désactivables avec révocation immédiate des sessions.
- Limitation des tentatives, expiration des sessions (inactivité et durée
  absolue), mot de passe temporaire à changer.
- Pas d'inscription publique ni de mot de passe par défaut.
- Protection web : CSP à nonce, en-têtes de sécurité, CSRF, filtrage de
  l'en-tête Host en production.
- Pages d'erreur sans détail technique.

### Audit

- Il couvre :
  - les connexions, échecs et verrouillages ;
  - les créations, modifications avec différences, archivages et suppressions ;
  - les changements de rôle et de droits ;
  - les révélations de secrets ;
  - les imports, exports, sauvegardes et restaurations.
- Journal global (administrateurs, ou responsables pour leurs clients) et
  historique par client et par élément.
- Rétention configurable.

### Interface

- En français, responsive (menu latéral repliable sur mobile).
- Navigation au clavier : lien d'évitement « Aller au contenu », modales avec
  piège du focus, libellés et descriptions ARIA des champs.
- Tableaux paginés, triables et filtrables côté serveur.
- Formulaires en sections avec messages de validation clairs, et états vides utiles.
- Confirmation adaptée à la gravité : saisie du nom pour une suppression définitive.

### Installation et exploitation

- **Windows** : `techindex.cmd` et `techindex.ps1`.
  - Installation relançable sans risque ; aucune valeur existante n'est écrasée.
  - Commandes start, stop, logs, update, backup, verify-backup, restore,
    keys-export, demo, create-admin, doctor et test.
- **Linux** : `scripts/techindex.sh`.
  - Installation avec HTTPS.
  - Mise à jour avec sauvegarde préalable, et retour arrière.
  - Sauvegarde chiffrable, restauration, rotation de clé, export des clés.
- **Plesk** : `scripts/techindex-plesk.sh` et `techindex.cmd plesk-deploy`.
  - Sans Docker ni root sur le serveur : archive publiée par GitHub (ou
    construite sur le poste pour un déploiement forcé), signature et SHA-256
    vérifiés, code en lecture seule, clés dans `shared/.env` (600).
  - Mise à jour avec sauvegarde préalable, maintenance et retour arrière.
  - File d'attente traitée par la tâche planifiée Plesk ; test de
    restauration dans une base dédiée (pas de droit de création de base).
- **Versions et mises à jour** : Git et dépôt GitHub public, `techindex.cmd
  release-init` et `release`.
  - Versions numérotées (`VERSION`, `CHANGELOG.md`, étiquettes `vX.Y.Z`),
    contrôles automatiques à chaque envoi et publication par GitHub Actions.
  - Manifeste signé (Ed25519) : seules les versions du canal officiel sont
    proposées et installées ; pas de retour silencieux à une version antérieure.
  - *Administration > Mises à jour* : recherche, notes, installation sur Plesk
    (confirmation de l'identité, audit, suivi de l'avancement et du journal).
  - Mise à jour par SSH (`techindex-plesk.sh update`, `techindex.sh update`)
    ou depuis le poste (`plesk-deploy -Release`), verrou contre les opérations
    simultanées.
- **Docker** : `Dockerfile` multi-cibles et Compose distincts pour le
  développement et la production.
  - Images et dépendances aux versions figées.
  - Contrôles de santé, rotation des journaux, réseau interne pour MariaDB.
- **Sauvegardes** : base, pièces jointes et paramètres, avec manifeste
  d'intégrité.
  - Chiffrement `age` facultatif et sauvegarde quotidienne automatique.
  - Vérification par restauration dans une base temporaire.
  - Restauration sur installation vierge.
- **Données de démonstration** : entièrement fictives, rechargeables sans doublon.

## 2. Ce qui n'a pas pu être exécuté

Le développement a eu lieu sur un poste Windows **sans Docker ni bash**, avec
PHP 8.5, MariaDB 11.8 et Node.js 24 en versions portables. Les éléments
suivants ont été écrits et relus, mais **pas exécutés** :

| Élément | Conséquence | À faire lors de la première installation |
|---------|-------------|------------------------------------------|
| Construction des images (`Dockerfile`) et démarrage de `compose.yaml` / `compose.prod.yaml` | Une erreur de construction ou de configuration reste possible | `.\techindex.cmd install` sur un poste avec Docker Desktop, puis `.\techindex.cmd test` |
| Configuration Nginx (modèles HTTP et HTTPS, choix du modèle, rechargement) | Idem | Vérifier l'accès, `/nginx-health` et les en-têtes |
| `scripts/techindex.sh` (bash), y compris la mise à jour vers une version publiée | Analysé par ShellCheck, mais pas exécuté sur un serveur Linux | Installation sur un serveur de test, puis `check-update` et `update` |
| Certbot et Let's Encrypt | Non testés | Installation sur un domaine réel |
| Chiffrement des sauvegardes avec `age` sous Docker | Testé seulement avec l'outil livré dans l'archive Plesk | `backup-keygen`, `backup`, puis `verify-backup --identity` |
| Workflows GitHub Actions (`ci.yml`, `release.yml`) | Vérifiés par actionlint et leurs étapes rejouées localement (archive, manifeste, signature, notes), mais pas exécutés sur GitHub | Premier envoi et première publication (`release 1.0.0`), puis contrôle de l'onglet *Actions* et de la version publiée |
| Envoi réel par SSH de `plesk-deploy` (ssh, scp) | La commande distante a été exécutée dans la simulation Plesk, pas à travers une vraie connexion SSH | Premier déploiement sur l'abonnement de test |
| Envoi d'e-mails par un vrai serveur SMTP | Seuls les pilotes de test (journal et tableau) ont servi | `mail-test` |
| Déroulé complet de `techindex.ps1 install` | Seuls l'analyse syntaxique, l'aide, l'échec propre sans Docker et les fonctions `.env` ont été testés | Installation complète |
| Validation YAML des fichiers Compose | Aucun analyseur disponible ; relecture manuelle uniquement | `docker compose config` |
| Déploiement sur un vrai serveur Plesk (nginx, Apache, PHP-FPM, tâche planifiée et réglages du panneau) | Le script a été exécuté dans un environnement qui reproduit un abonnement Plesk (voir ci-dessous), pas dans Plesk lui-même | Suivre [deploiement-plesk.md](deploiement-plesk.md) sur un abonnement de test, puis la liste de contrôle du § 11 |

Ce qui a été exécuté :

- la suite de tests (184 tests) sur MariaDB 11.8 ;
- `vue-tsc` et la compilation de production ;
- Pint, ShellCheck (scripts d'exploitation) et actionlint (workflows GitHub) ;
- `composer audit` et `npm audit`, sans vulnérabilité connue au 14 octobre 2026 ;
- le cycle sauvegarde, vérification et restauration ;
- l'environnement local sans Docker (`techindex-local.cmd`) : installation
  complète, arrêt et redémarrage, suite de tests complète ;
- les parcours principaux dans un navigateur ;
- le déploiement Plesk, dans des conteneurs reproduisant un abonnement :
  utilisateur sans droit root sous `/var/www/vhosts/<domaine>`, PHP 8.5 dans
  `/opt/plesk/php/8.5/bin/php`, MariaDB 11.8 avec un utilisateur limité à ses
  bases (sans droit de création de base) et voyant une base étrangère. Ont été
  exécutés : construction de l'archive, `check`, `install`, service des pages
  depuis `app/public` (`.env` inaccessible, hôte inconnu refusé), tâche
  `schedule:run` avec traitement de la file d'attente, `backup`,
  `verify-backup` (refus attendu sans base dédiée, puis réussite avec
  `BACKUP_VERIFY_DATABASE`), chiffrement `age`, `keys-export`, `update`,
  `rollback`, redéploiement, `restore` annulé puis forcé, reprise après échec ;
- le circuit des versions, avec un faux dépôt GitHub servant deux versions
  construites et signées comme le fera GitHub Actions : première installation
  depuis la publication (script téléchargé, signature vérifiée), `check-update`,
  mise à jour **demandée depuis l'administration** puis lancée par la tâche
  planifiée (sauvegarde, migration appliquée, audit de la demande et du
  résultat), refus d'une version plus ancienne, d'un manifeste modifié après
  signature (script et application) et d'une opération simultanée ;
- `release-init` et `release` (première version, version mineure, refus d'un
  arbre modifié et d'une version inférieure) sur une copie isolée du projet,
  avec un dépôt distant local ;
- la page *Administration > Mises à jour* dans un navigateur.

## 3. Limites connues de cette version

Fonctionnelles :

- **Notifications** : les échéances apparaissent sur le tableau de bord, mais
  aucun e-mail récapitulatif n'est envoyé.
- **Imports** : limités aux clients et aux équipements, comme demandé. Sites,
  contacts, services et licences se saisissent dans l'interface.
- **Documentation** :
  - pas de comparaison visuelle entre deux versions (chacune se consulte) ;
  - pas d'image intégrée dans le texte : les images se joignent en pièces jointes.
- **Catégories de services** : fixes (liste prévue dans le code) ; des champs
  peuvent leur être ajoutés avec le constructeur de fiches.
- **Constructeur de fiches** :
  - les fiches créées sont propres à chaque client (pas de fiche globale
    commune à tous les clients) ;
  - pas d'import CSV / Excel pour les fiches créées ;
  - les champs d'origine des fiches par défaut ne se déplacent pas : les
    champs ajoutés s'affichent dans leurs propres sections, en bas.
- **Logos des opérateurs** : une vingtaine d'opérateurs (souvent régionaux ou
  professionnels) n'ont pas de logo libre sur Wikimedia Commons ; leurs
  initiales s'affichent et un administrateur peut déposer le logo.
- **Pas de cartographie graphique** du réseau ni des liens entre équipements :
  ils sont présentés sous forme de listes.
- **Pas d'API** ni de connecteur (RMM, supervision, annuaire).
- **Recherche** :
  - les mots de moins de 3 caractères et les mots vides ne sont pas indexés
    par le moteur plein texte (comportement MariaDB) ; les identifiants courts
    restent trouvés par préfixe ;
  - pas de tolérance aux fautes de frappe.
- **Fuseau horaire** : un seul fuseau d'affichage pour toute l'installation.

Sécurité et exploitation :

- **Authentification** :
  - pas de SSO (Entra ID, Google, LDAP) ;
  - une clé d'accès est toujours un second facteur : la connexion sans mot
    de passe n'est pas proposée ;
  - une clé d'accès est liée au domaine de l'application : changer d'adresse
    (`APP_URL`) oblige chaque utilisateur à en enregistrer de nouvelles.
- **Gestionnaire de mots de passe** : pas de partage d'accès hors des droits
  par client, pas de coffre personnel, pas d'extension de navigateur ni de
  remplissage automatique ; la page « Accès » d'un client et les fiches
  restent utilisables avec une simple confirmation du mot de passe.
- **Explorateur de fichiers** : pas d'aperçu des documents bureautiques, pas
  de versions de fichiers, pas de téléchargement d'un dossier en archive ;
  500 fichiers au plus par import de dossier.
- **Coffre externe** : le contrat existe, mais seule l'implémentation locale
  est fournie.
- **Pièces jointes** : aucune analyse antivirus. Pour en ajouter une,
  `AttachmentStorage` est le point d'intégration, par exemple avec ClamAV.
- **Audit** : stocké dans la même base. Il n'est pas infalsifiable pour une
  personne disposant d'un accès direct à MariaDB ; prévoir un export vers un
  SIEM ou syslog si cette exigence existe.
- **Haute disponibilité** : un seul serveur, sans réplication MariaDB. Le
  stockage des pièces jointes est local ; un passage vers S3 est possible avec
  le système de fichiers Laravel, mais non implémenté.
- **Copie des sauvegardes hors du serveur** : à organiser (voir
  [sauvegarde-restauration.md](sauvegarde-restauration.md)), car TechIndex ne
  l'effectue pas lui-même.
- **Restauration sous Windows** : le script ne met pas le site en maintenance
  pendant l'opération ; le script Linux le fait.
- **Accessibilité** : bonnes pratiques appliquées (clavier, ARIA, contrastes de
  la palette), mais sans audit RGAA formel.
