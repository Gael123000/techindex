# Journal des modifications

Toutes les évolutions notables de TechIndex sont consignées ici. Les numéros
de version suivent le [versionnage sémantique](https://semver.org/lang/fr/) :
`MAJEUR.MINEUR.CORRECTIF`.

La commande `.\techindex.cmd release` déplace la section « Non publié » sous
le numéro de la nouvelle version. Si elle est vide, elle est remplie avec les
messages des commits depuis la version précédente.

## [Non publié]

- **Groupes d'entreprises** : un client peut être rattaché à une entreprise
  mère (filiale, établissement, franchisé ; siège social ou holding pour la
  tête de groupe), sur trois niveaux. Dans la liste des clients, les
  entreprises rattachées s'affichent sous leur mère, décalées sur fond plus
  sombre, et chaque groupe se replie. Carte « Groupe » sur la fiche client,
  rattachement rappelé dans l'en-tête, colonnes ajoutées à l'export.
- **Intégrations** (Administration › Intégrations) : licences et abonnements
  importés automatiquement depuis Microsoft 365, Microsoft Partner Center,
  Google Workspace, Pax8, TD SYNNEX StreamOne Ion, Arrow ArrowSphere, Ingram
  Micro, ALSO, Crayon, Sophos Central, Bitdefender GravityZone, ESET PROTECT
  Hub et Acronis. Identifiants chiffrés, rapprochement automatique des comptes
  avec les clients, synchronisation planifiée, historique, licences
  attribuées affichées dans les licences des clients.

- **Onglets** dans l'en-tête, comme dans un navigateur : bouton « + » pour en
  ouvrir un, navigation conservée dans chaque onglet, fermeture (croix ou clic
  molette), glisser-déposer, menu clic droit (actualiser, dupliquer, fermer
  les autres ou ceux de droite). Ils sont sauvegardés et retrouvés après une
  actualisation ou au retour sur TechIndex.
- **Menu contextuel** au clic droit sur les liens internes : ouvrir dans un
  nouvel onglet, en arrière-plan, dans une nouvelle fenêtre, copier le lien.
  Clic molette ou Ctrl+clic ouvrent un onglet en arrière-plan.
- Menu latéral **réductible** aux icônes (bouton en haut), catégorie
  Administration placée en bas et repliable ; la liste « Mes clients » est
  retirée (doublon de l'onglet Clients).
- Barre de recherche plus compacte, à droite de l'en-tête ; titre des pages
  client sous la forme « Client – rubrique ».

- Mots de passe : l'affichage et la copie d'un secret depuis une fiche
  (équipement, service, liste des accès) exigent désormais la clé d'accès,
  comme le gestionnaire de mots de passe ; la confirmation du mot de passe
  TechIndex ne suffit plus. Un seul déverrouillage vaut pour les fiches et le
  gestionnaire.
- Le déverrouillage dure 5 minutes, même en cas d'activité (durée fixe). Le
  temps restant s'affiche dans la barre supérieure, avec un bouton pour
  verrouiller. Les paramètres `VAULT_IDLE_TIMEOUT`, `VAULT_MAX_UNLOCK` et
  `SECRETS_REAUTH_TIMEOUT` ne sont plus utilisés.
- Clés d'accès : le type de clé se choisit dans une liste (Windows Hello,
  Touch ID / Face ID, Android, clé de sécurité, gestionnaire de mots de passe,
  autre), avec une précision facultative. Le type des clés existantes est
  déduit de leur nom.
- Onglet **FAI** dans chaque client : liens Internet avec l'opérateur, la
  technologie, les débits, les IP publiques, le raccordement WAN, le contrat et
  les accès liés ; un sous-réseau (WAN) peut être relié à son lien Internet.
- Catalogue des **fournisseurs d'accès** (Administration) avec les logos
  officiels des principaux opérateurs français et étrangers.
- Les services « Connexion Internet » existants sont convertis en liens FAI.
- **Recherche instantanée** : résultats pendant la frappe dans la barre de
  recherche (Ctrl+K), page de recherche mise à jour en direct.
- **Constructeur de fiches** (Administration › Fiches et champs) : création de
  fiches avec leur propre table, 18 types de champs, sections et colonnes,
  glisser-déposer, conditions d'affichage, aperçu ; champs ajoutés aux fiches
  par défaut. Les anciens champs personnalisés des équipements y sont repris.

## [1.0.2] - 2026-10-15

- Test Mise à jour

## [1.0.0] - 2026-10-15

- Première version publiée : référentiel technique des clients, gestionnaire
  de mots de passe, explorateur de fichiers, imports et exports, audit.
- Déploiement sur un hébergement Plesk, mise à jour depuis l'administration
  et publication des versions signées par GitHub.
