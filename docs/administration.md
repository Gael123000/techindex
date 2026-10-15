# Administration

Ce guide s'adresse aux administrateurs de TechIndex. Les commandes sont données
pour Windows (`.\techindex.cmd ...`) et pour un serveur Linux
(`sudo ./scripts/techindex.sh ...`).

## 1. Rôles et droits

### Rôles

| Rôle | Fonctions transverses | Droits proposés lors d'une affectation à un client |
|------|----------------------|-----------------------------------------------------|
| **Administrateur** | Utilisateurs, paramétrage, tous les clients, journal global, suppression définitive | Tous, sur tous les clients (sans affectation) |
| **Responsable technique** | Création de clients, journal des clients qu'il gère | Lecture, modification, export, secrets, gestion |
| **Technicien** | – | Lecture, modification, secrets |
| **Lecteur** | – | Lecture seule |

### Droits par client

Un utilisateur non administrateur ne voit **que** les clients auxquels il est
affecté. Pour chaque affectation, cinq droits s'ajustent indépendamment du rôle :

| Droit | Permet |
|-------|--------|
| Lecture | Consulter le client et ses éléments. Implicite : il découle de l'affectation. |
| Modification | Créer, modifier et archiver sites, contacts, équipements, services, documents, pièces jointes et accès (métadonnées). |
| Export | Exporter les données du client en CSV ou Excel (jamais les secrets). |
| Secrets | Révéler ou copier les secrets, et définir leur valeur si le droit « modification » est aussi accordé. |
| Gestion | Archiver ou restaurer le client, supprimer définitivement ses éléments, consulter son journal d'audit. |

Règles appliquées côté serveur, quelle que soit l'interface :

- Un client non attribué, même appelé par son URL, répond « page introuvable »
  (404). De même, les recherches, exports et téléchargements n'incluent jamais
  ses données.
- Un client archivé est en lecture seule : restaurez-le pour le modifier.
- Seul un administrateur supprime définitivement un client, et uniquement
  après l'avoir archivé.

### Affecter un utilisateur à des clients

**Administration → Utilisateurs →** un utilisateur **→ Accès aux clients** :

1. Cochez les clients.
2. Ajustez les droits de chaque client. Les valeurs proposées dépendent du rôle.

Chaque changement est enregistré dans l'audit (`droits modifiés`).

Un responsable technique qui crée un client y est automatiquement affecté avec
les droits par défaut de son rôle.

## 2. Comptes utilisateurs

### Créer un compte

**Administration → Utilisateurs → Nouvel utilisateur**, deux possibilités :

- **Invitation par e-mail** (recommandé) : l'utilisateur reçoit un lien de
  définition du mot de passe. La messagerie doit être configurée (voir § 4).
- **Mot de passe temporaire** : saisi par l'administrateur et transmis par un
  canal sûr. L'utilisateur doit le changer à sa première connexion.

Aucun compte n'est créé avec un mot de passe par défaut, et il n'existe aucune
inscription publique.

Politique des mots de passe :

- 12 caractères minimum, avec majuscules, minuscules et chiffres ;
- en option, refus des mots de passe connus dans des fuites (`PASSWORD_CHECK_PWNED=true`,
  ce qui nécessite un accès sortant à l'API Have I Been Pwned).

### Opérations sur un compte

| Action | Effet |
|--------|-------|
| Désactiver | Connexion impossible, **toutes ses sessions sont fermées immédiatement**. Les données et l'historique sont conservés. |
| Réactiver | Rend la connexion de nouveau possible. |
| Fermer les sessions | Déconnecte l'utilisateur sur tous ses appareils. |
| Réinitialiser la double authentification | Pour un téléphone ou un appareil perdu : supprime le code TOTP **et toutes les clés d'accès** ; l'utilisateur devra les reconfigurer. |
| Envoyer un lien de réinitialisation | E-mail de définition d'un nouveau mot de passe (valable 60 minutes par défaut). |
| Changer le rôle | Enregistré dans l'audit. Un administrateur ne peut pas retirer son propre rôle ni se désactiver. |

Les comptes ne sont pas supprimés, afin de conserver la traçabilité de l'audit.
Désactivez-les.

### Accès administrateur perdu

Depuis le serveur ou le poste, sans passer par l'interface :

```powershell
.\techindex.cmd create-admin
```

```bash
sudo ./scripts/techindex.sh create-admin
```

Le mot de passe est saisi de façon masquée, ou généré avec
`--generate-password` (affiché une seule fois). Dans les deux cas, il doit être
changé à la première connexion.

## 3. Sécurité des connexions

### Double authentification (TOTP)

- Chaque utilisateur l'active dans **Mon profil** avec une application
  d'authentification (Microsoft Authenticator, Google Authenticator, Aegis,
  1Password...), après confirmation de son mot de passe.
- Huit codes de secours à usage unique sont fournis. Ils sont à conserver hors
  ligne et peuvent être régénérés.
- `TWO_FACTOR_REQUIRED_ROLES` (par exemple `admin,manager,technician`) rend la
  double authentification obligatoire : tant qu'elle n'est pas configurée
  (code TOTP ou clé d'accès), l'utilisateur est redirigé vers son profil.

### Clés d'accès (WebAuthn)

- Chaque utilisateur ajoute ses clés dans **Mon profil**, après confirmation de
  son identité, en choisissant le type dans une liste : Windows Hello,
  Touch ID / Face ID, téléphone Android, clé de sécurité USB / NFC (YubiKey...)
  ou gestionnaire de mots de passe. La vérification biométrique ou par
  code PIN est exigée. Conseillez d'en enregistrer au moins deux (par exemple
  le poste et une clé de secours).
- À la connexion, une clé d'accès remplace le code TOTP comme second facteur,
  après le mot de passe. Elle peut aussi confirmer les actions sensibles.
- **Les mots de passe ne s'affichent qu'après un déverrouillage par clé
  d'accès**, dans le gestionnaire comme sur les fiches (équipements, services).
  Un utilisateur sans clé d'accès ne peut donc plus révéler de secret.
- Les navigateurs n'acceptent les clés d'accès qu'en HTTPS (ou sur
  `localhost`). Elles sont liées au domaine d'`APP_URL` ; `PASSKEYS_RP_ID` et
  `PASSKEYS_ALLOWED_ORIGINS` permettent de le préciser derrière un reverse proxy.
- Le déverrouillage dure 5 minutes, même en cas d'activité. Cette durée est
  fixe : les anciens paramètres `VAULT_IDLE_TIMEOUT` et `VAULT_MAX_UNLOCK` ne
  sont plus lus.

### Sessions

| Paramètre | Défaut | Rôle |
|-----------|--------|------|
| `SESSION_LIFETIME` | 120 min | Expiration après inactivité |
| `SESSION_ABSOLUTE_LIFETIME` | 720 min | Durée maximale d'une session, même active |
| `AUTH_PASSWORD_TIMEOUT` | 900 s | Validité d'une confirmation de mot de passe (actions sensibles du profil) |
| `SECRETS_REVEAL_PER_MINUTE` | 20 | Nombre maximal de révélations par minute et par utilisateur |

Chaque utilisateur voit ses sessions actives dans **Mon profil** et peut fermer
les autres. Changer son mot de passe ferme ses autres sessions.

### Limitation des tentatives

- **Connexion** : 5 essais par minute pour un même e-mail et une même adresse IP,
  et 30 par minute par adresse IP. Un verrouillage est enregistré dans l'audit
  (`auth.lockout`).
- **Double authentification** : 5 essais par minute.
- **Réinitialisation de mot de passe** : 3 demandes par minute par adresse IP.
  La réponse est identique, que l'adresse existe ou non.

## 4. Messagerie (récupération de compte, invitations)

Renseignez le serveur SMTP dans `.env` :

```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp.office365.com      # exemple
MAIL_PORT=587
MAIL_USERNAME=techindex@votre-domaine.fr
MAIL_PASSWORD=...                 # mot de passe d'application ou compte de relais
MAIL_FROM_ADDRESS="techindex@votre-domaine.fr"
```

Appliquez la configuration puis testez l'envoi :

```bash
sudo ./scripts/techindex.sh restart
sudo ./scripts/techindex.sh mail-test vous@votre-domaine.fr
```

En local, tous les e-mails arrivent dans Mailpit (http://localhost:8025).

Les e-mails partent par la file d'attente : si rien n'arrive, consultez les
journaux du service `worker`.

## 5. Paramétrage

Menu **Administration** (administrateurs uniquement) :

- **Types d'équipement** : nom et icône. Un type utilisé par au moins un
  équipement ne peut pas être supprimé.
- **Fiches et champs** : constructeur de fiches (voir ci-dessous).
- **Catégories de documents**.
- **Fournisseurs d'accès** : catalogue des opérateurs (FAI) commun à tous les
  clients, livré avec les principaux opérateurs français et étrangers et leurs
  logos. Pour chaque opérateur : site web, espace client, page d'état du
  réseau, numéros d'assistance, e-mail du support et notes (procédure
  d'ouverture d'incident...). Un logo peut être déposé (PNG, JPEG ou WebP) ; il
  remplace le logo fourni. Un opérateur utilisé par un lien ne peut pas être
  supprimé : décochez « Proposé dans les formulaires » pour le masquer.
- **Étiquettes** : nom et couleur, partagées entre clients, éléments et documents.
- **Intégrations** : licences et abonnements remontés automatiquement depuis
  les distributeurs et consoles d'éditeurs (voir ci-dessous).

### Intégrations

Chaque intégration se configure avec un compte d'API créé chez le
fournisseur ; les étapes sont rappelées sur sa page. Connecteurs livrés :

| Famille | Connecteurs |
|---------|-------------|
| Microsoft et Google | Microsoft 365 (Graph, un locataire par client), Microsoft Partner Center (CSP), Google Workspace (revendeur) |
| Distributeurs | Pax8, TD SYNNEX StreamOne Ion, Arrow ArrowSphere, Ingram Micro Cloud Marketplace, ALSO Cloud Marketplace, Crayon Cloud-iQ |
| Sécurité | Sophos Central Partner, Bitdefender GravityZone, ESET PROTECT Hub (MSP) |
| Sauvegarde | Acronis Cyber Protect Cloud |

Les connecteurs marqués **Bêta** ont été réalisés d'après la documentation
publique du fournisseur, sans compte réel de test : utilisez « Tester la
connexion » et vérifiez la première synchronisation.

Fonctionnement :

1. **Configuration** : identifiants d'API (chiffrés avec la clé des secrets,
   jamais réaffichés : un champ laissé vide conserve la valeur enregistrée),
   réglages (région, adresse de l'API limitée aux domaines du fournisseur),
   fréquence de synchronisation (manuelle, toutes les 6 heures, quotidienne).
2. **Comptes** : la première synchronisation récupère les clients (ou
   locataires) du fournisseur. Rattachez chacun à un client TechIndex ; le
   bouton « Rattacher les suggestions » associe ceux dont le nom (sans forme
   juridique) ou le domaine correspond à un seul client. Un compte peut être
   ignoré.
3. **Licences** : pour chaque compte rattaché, les licences et abonnements sont
   ajoutés ou mis à jour dans l'onglet « Licences » du client (produit,
   éditeur, quantité, licences attribuées quand le service les fournit,
   dates, périodicité, renouvellement). Un abonnement disparu chez le
   fournisseur passe « Résiliée ». Les licences saisies à la main ne sont
   jamais modifiées ; sur une licence synchronisée, le site, le coût, le
   préavis, les équipements et les notes restent modifiables.

Particularités :

- **Microsoft 365** : créez une application Entra ID multilocataire
  (permission d'application `Organization.Read.All`) avec l'adresse de retour
  affichée sur la page. Pour chaque client, le « Lien de consentement » doit
  être ouvert par un administrateur général de son locataire ; le locataire
  est ajouté et vérifié au retour, ou peut être ajouté ensuite par son domaine.
- **TD SYNNEX StreamOne Ion** : le jeton de rafraîchissement n'est valable
  qu'une fois et 32 jours ; TechIndex enregistre le nouveau à chaque
  connexion. Gardez la synchronisation automatique active.
- **ALSO** et **ESET** utilisent un compte utilisateur dédié (sans double
  authentification pour ALSO) ; **Crayon** exige un client d'API et un
  utilisateur Cloud-iQ.

Supprimer une intégration efface ses identifiants, ses comptes et son
historique ; les licences déjà importées restent chez les clients.

### Constructeur de fiches

**Administration › Fiches et champs** permet :

- **de créer des fiches** : chaque fiche devient un onglet dans tous les
  clients (placé après l'onglet choisi), avec sa liste, son formulaire, sa
  page, son historique, ses étiquettes, ses pièces jointes, son export et sa
  place dans la recherche globale. À la création, vous choisissez le nom de
  la table de la base de données (`cx_<nom>`) ; il est figé ensuite ;
- **d'ajouter des champs aux fiches par défaut** (client, sites, contacts,
  équipements, réseau, FAI, services, licences, certificats, documentation).
  Leurs champs d'origine sont verrouillés : ils ne peuvent être ni modifiés ni
  supprimés. Les champs ajoutés apparaissent dans une ou plusieurs sections en
  bas du formulaire et de la fiche, et sont stockés dans une table
  d'extension (`cx_ext_<fiche>`).

Dans le constructeur :

- **Formulaire** : glissez un type de champ dans une section (ou cliquez
  dessus). Chaque champ ajouté crée une **colonne typée** dans la table de la
  fiche ; son nom technique (nom de la colonne) est proposé à partir du
  libellé, modifiable avant l'enregistrement, puis figé. Glissez les champs
  pour les réorganiser ; chaque section a 1, 2 ou 3 colonnes et chaque champ
  occupe une colonne, deux ou toute la largeur. La disposition est enregistrée
  automatiquement.
- **Types de champs** : texte court ou long, texte mis en forme, nombre entier
  ou décimal, montant, date, date et heure, oui / non, liste de choix (liste
  déroulante ou boutons), choix multiples, e-mail, adresse web, téléphone,
  adresse IP, réseau CIDR, lien vers une fiche (site, équipement, contact,
  accès, autre fiche créée... du même client), utilisateur de TechIndex.
- **Comportement** : saisie obligatoire, valeur unique parmi les fiches du
  client, colonne de la liste, inclusion dans la recherche, valeur par
  défaut, minimum et maximum, unité, texte d'aide et d'exemple.
- **Condition d'affichage** : un champ peut n'apparaître que selon la valeur
  d'un autre champ (« Date de résiliation » seulement si « Statut » vaut
  « Résilié ») ou d'un champ d'origine (« Nombre de ports » seulement pour le
  type d'équipement « Switch »). Un champ masqué n'est ni obligatoire ni
  modifié.
- **Aperçu** : le formulaire tel que le verront les techniciens.
- **Réglages** (fiches créées) : noms, icône, onglet, tri de la liste,
  site, étiquettes, pièces jointes ; une fiche désactivée n'a plus d'onglet.
- **Base de données** : la table, ses colonnes et leur type SQL.

Précautions :

- changer le type d'un champ déjà rempli n'est accepté que sans perte de
  données (texte court vers texte long, entier vers décimal...) ;
- supprimer un champ supprime sa colonne et ses valeurs ; supprimer une fiche
  supprime sa table, ses enregistrements et leurs pièces jointes (saisie du
  nom demandée dans les deux cas) ;
- aucun champ ne peut contenir de secret : un libellé évoquant un mot de
  passe, une clé ou un jeton est refusé. Les secrets restent chiffrés dans
  les accès, qu'un champ « lien vers une fiche » peut désigner ;
- les anciens champs personnalisés des équipements ont été repris dans la
  fiche « Équipements », avec une condition d'affichage par type
  d'équipement.

Seuils des alertes du tableau de bord, en jours, dans `.env` :

```ini
ALERT_WARRANTY_DAYS=60
ALERT_LICENSE_DAYS=60
ALERT_CERTIFICATE_DAYS=30
ALERT_SERVICE_DAYS=60
ALERT_END_OF_LIFE_DAYS=90
```

## 6. Imports CSV / Excel

**Menu Imports → Nouvel import**. Accessible aux administrateurs, aux
responsables techniques et aux utilisateurs ayant le droit « modification »
sur au moins un client.

1. **Modèle** : téléchargez le modèle CSV ou Excel, qui contient les colonnes
   attendues et une ligne d'exemple.
2. **Fichier** : CSV (UTF-8 ou Windows-1252, séparateur `;`, `,` ou tabulation détecté) ou
   classeur `.xlsx`. Limites : 10 Mo et 5 000 lignes (`IMPORT_MAX_SIZE_KB`,
   `IMPORT_MAX_ROWS`).
3. **Mode**, à choisir explicitement :
   - créer uniquement : les doublons sont ignorés et signalés ;
   - mettre à jour uniquement : les lignes inconnues sont ignorées ;
   - créer et mettre à jour.
4. **Correspondance des colonnes** : proposée automatiquement d'après les
   en-têtes et modifiable.
5. **Aperçu** : chaque ligne est validée et classée « création »,
   « mise à jour », « ignorée » ou « erreur », avec le motif.
6. **Exécution** :
   - elle est refusée s'il reste des lignes en erreur, sauf si vous choisissez
     explicitement de les ignorer ;
   - l'import est exécuté en tâche de fond dans **une seule transaction** :
     en cas d'échec, rien n'est enregistré ;
   - le rapport ligne par ligne reste consultable. Il est aussi téléchargeable
     en CSV ou Excel, avant et après l'exécution.

Règles de doublons :

| Import | Doublon reconnu par |
|--------|---------------------|
| Clients | Référence interne |
| Équipements (pour un client) | Numéro de série ; à défaut, nom identique dans le même client |

À l'exécution, les droits sont revérifiés : la mise à jour d'un client
existant exige le droit « modification » sur ce client, qui ne doit pas être
archivé.

Les fichiers importés sont supprimés après `IMPORT_RETENTION_DAYS` jours (30 par défaut).

## 7. Exports

- Où les trouver :
  - le bloc **Exporter** de la fiche client ;
  - les listes du client (équipements, réseau, services, licences, certificats) ;
  - en global, les listes **Clients** et **Équipements**, pour tous les
    clients autorisés.
- Formats : CSV (séparateur `;`, UTF-8 avec BOM pour Excel) ou Excel.
- Ils exigent le droit « export » et ne contiennent que les clients autorisés.
- Ils ne contiennent **jamais** de secret. Les accès ne sont pas exportables.
- Toute cellule commençant par `=`, `+`, `-`, `@`, une tabulation ou un retour
  chariot est neutralisée, pour éviter l'injection de formules.
- Chaque export est enregistré dans l'audit et limité à 10 par minute par utilisateur.

## 8. Journal d'audit

- **Menu Audit** : accessible aux administrateurs (tout le journal) et aux
  utilisateurs ayant le droit « gestion » (journal de leurs clients). Il se
  filtre par période, utilisateur, client et action.
- **Onglet Historique** de chaque client et élément : modifications avec les
  valeurs avant et après.
- Les valeurs sensibles (mots de passe, secrets, jetons, codes) ne sont jamais
  enregistrées : elles apparaissent sous la forme « [masqué] ».
- Conservation : illimitée par défaut. `AUDIT_RETENTION_DAYS=730`, par exemple,
  purge les entrées de plus de deux ans chaque nuit.

## 9. Tâches automatiques et diagnostic

Le planificateur (service `scheduler` avec Docker, tâche planifiée
`schedule:run` sur Plesk) exécute (heure de `APP_DISPLAY_TIMEZONE`) :

| Heure | Tâche |
|-------|-------|
| 01:30 | Sauvegarde, si `BACKUP_DAILY_AT` est renseigné (activée par défaut en production) |
| 02:30 | Purge des imports anciens, et de l'audit si une rétention est définie |
| 02:45 | Purge des jetons de réinitialisation expirés |
| 03:00 | Purge des tâches en échec de plus de 30 jours |
| 03:15 | Contrôle que tous les secrets restent déchiffrables (`techindex:secrets-check`) |
| Chaque heure (h17) | Synchronisation des intégrations arrivées à échéance (`techindex:integrations-sync`) |
| Lundi 04:10 | Mise à jour de la liste officielle des noms de licences Microsoft |
| 04:10 | Recherche d'une nouvelle version (`techindex:update-check`), si le canal est configuré et `UPDATE_CHECK_ENABLED` vrai |
| Chaque minute | Témoin d'activité contrôlé par `doctor` ; sur Plesk, file d'attente et lancement des mises à jour demandées depuis l'administration |

Commandes de diagnostic, en ajoutant `artisan` sous Windows :

| Commande | Rôle |
|----------|------|
| `doctor` | Contrôle la configuration : clés, mode debug, cookies sécurisés, HTTPS, base (MariaDB 10.11 ou plus récent) et migrations, planificateur actif, répertoires, file d'attente, messagerie, extensions PHP, outils de sauvegarde |
| `artisan techindex:update-check` | Recherche une nouvelle version publiée (signature vérifiée) |
| `artisan techindex:keys-info` | Empreintes des clés (sans les révéler) |
| `artisan techindex:secrets-check` | Vérifie que chaque secret est déchiffrable |
| `artisan techindex:integrations-sync --provider=pax8` | Lance tout de suite la synchronisation d'une intégration |
| `artisan queue:failed` | Tâches en échec (imports, e-mails) |

## 10. Mises à jour de l'application

*Administration > Mises à jour* (administrateurs) présente :

- la version installée et le mode d'installation (Plesk, Docker ou poste de
  développement) ;
- la dernière version publiée sur le canal officiel, ses notes et
  l'indication « modifie la base de données », après vérification de la
  signature du manifeste. Une version non signée par la clé du projet est
  ignorée et signalée ;
- l'état et le journal de la dernière mise à jour.

Le bouton *Rechercher une mise à jour* interroge GitHub (6 recherches par
minute au plus) ; une recherche est aussi faite chaque nuit, et le menu
affiche « Nouvelle » lorsqu'une version est disponible.

Sur un hébergement Plesk, *Installer la version X.Y.Z* :

1. exige une confirmation récente de l'identité (mot de passe ou clé d'accès) ;
2. n'accepte que la dernière version vérifiée, et une seule demande à la fois ;
3. enregistre la demande dans l'audit (« Demande de mise à jour de
   l'application ») ;
4. confie l'installation à la tâche planifiée, qui lance `techindex-plesk.sh`
   dans la minute : sauvegarde, maintenance, migrations, remise en service ;
5. enregistre l'issue dans l'audit (« Mise à jour de l'application » ou
   « Échec de la mise à jour de l'application »).

Le processus web ne modifie jamais le code lui-même. Sur un serveur Docker, la
page indique la commande à lancer (`sudo ./scripts/techindex.sh update`). Le
détail des procédures et du retour arrière est dans
[mise-a-jour.md](mise-a-jour.md) ; la publication des versions, dans
[publication-et-mises-a-jour.md](publication-et-mises-a-jour.md).

Pour ne jamais contacter GitHub : `UPDATE_CHECK_ENABLED=false`.
