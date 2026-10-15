# Journal des modifications

Toutes les évolutions notables de TechIndex sont consignées ici. Les numéros
de version suivent le [versionnage sémantique](https://semver.org/lang/fr/) :
`MAJEUR.MINEUR.CORRECTIF`.

La commande `.\techindex.cmd release` déplace la section « Non publié » sous
le numéro de la nouvelle version. Si elle est vide, elle est remplie avec les
messages des commits depuis la version précédente.

## [Non publié]

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
