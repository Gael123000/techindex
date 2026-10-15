# Tests automatisés

## Lancer les tests

Avec Docker (poste Windows) :

```powershell
.\techindex.cmd test
```

Sans Docker, avec l'environnement local (`techindex-local.cmd install` au préalable) :

```powershell
.\techindex-local.cmd test
.\techindex-local.cmd test --filter=ClientAccessTest
```

Ou directement, avec PHP et MariaDB disponibles :

```bash
php artisan test                                  # toute la suite
php artisan test tests/Feature/Secrets            # un dossier
php artisan test --filter=test_unassigned_client  # un test
```

Contrôles de l'interface et du style :

```bash
npm run typecheck        # vue-tsc : typage TypeScript strict des pages et composants
npm run build            # compilation de production
vendor/bin/pint --test   # style du code PHP
composer audit           # vulnérabilités connues des dépendances PHP
npm audit --omit=dev     # vulnérabilités connues des dépendances JavaScript
```

## Base de test

- Les tests utilisent **MariaDB** (pas SQLite), afin de vérifier le
  comportement réel : index FULLTEXT, contraintes, types.
- La base est `techindex_test`, forcée par `phpunit.xml`. L'installation Docker
  la crée automatiquement (`docker/mariadb/init`).
- **Garde-fou** : les tests refusent de démarrer si le nom de la base ne se
  termine pas par `_test`. Une variable d'environnement mal placée ne peut
  donc pas vider la base de travail.
- Les fichiers sont écrits sur un disque simulé (`Storage::fake`), et les
  clés de test sont propres à `phpunit.xml`.
- La recherche plein texte est testée avec des données réellement validées en
  base (`CommittedDataTestCase`), car InnoDB n'indexe pas les données d'une
  transaction non validée.

## Couverture

Le dernier passage compte 184 tests et 1 827 assertions, tous réussis.

Les clés d'accès sont testées avec un authentificateur logiciel
(`tests/Support/FakeAuthenticator.php`) : il produit de vraies réponses
WebAuthn (clé ECDSA P-256, signatures ES256), vérifiées par la même
bibliothèque qu'en production. Aucune vérification n'est simulée.

| Domaine | Fichier | Exemples de cas vérifiés |
|---------|---------|--------------------------|
| Authentification | `Feature/Auth/AuthenticationTest` (19) | Absence d'inscription publique ; message identique pour un e-mail inconnu et un mauvais mot de passe ; compte désactivé refusé et sessions fermées ; limitation des tentatives ; durée absolue de session ; mot de passe temporaire à changer ; double authentification et code de secours à usage unique ; double authentification obligatoire par rôle ; réinitialisation sans divulgation de l'existence du compte |
| Droits | `Feature/Authorization/ClientAccessTest` (14) | Client non attribué introuvable par URL directe (404) ; ressource d'un autre client inaccessible via l'URL d'un client autorisé ; lecteur sans modification ; client archivé en lecture seule ; suppression définitive (administrateur, client archivé, confirmation) ; administration et audit réservés ; changements de droits audités ; un administrateur ne peut pas se rétrograder |
| Secrets | `Feature/Secrets/CredentialSecretsTest` (13) | Chiffré en base, jamais en clair ; absent des pages ; confirmation récente exigée ; réponse `no-store` et audit sans la valeur ; lecteur refusé ; secret d'un autre client inaccessible ; jamais renvoyé après une erreur de validation ; limitation du débit ; rotation de clé ; clé absente : échec sûr ; chiffré altéré rejeté |
| Clés d'accès | `Feature/Auth/PasskeyTest` (13) | Ajout soumis à confirmation de l'identité ; seule la clé publique conservée et jamais transmise ; enregistrement falsifié (autre origine) ou rejoué refusé ; clé exigée en second facteur après le mot de passe ; jamais sans mot de passe ; clé d'un autre utilisateur refusée ; simple présence sans vérification refusée ; code TOTP refusé proprement pour un compte à clé seule ; second facteur obligatoire satisfait par une clé ; confirmation par clé avant révélation ; clé d'autrui ni renommable ni supprimable ; réinitialisation par l'administrateur |
| Gestionnaire de mots de passe | `Feature/Secrets/VaultTest` (13) | Verrouillé tant qu'aucune clé n'est vérifiée (page et JSON) ; mot de passe confirmé insuffisant ; aucun secret dans les pages, `no-store` ; ouverture falsifiée refusée et auditée ; mêmes accès que les fiches équipement, rangement conservé ; reverrouillage après inactivité et durée maximale ; verrouillage manuel ; révélation soumise au droit « secrets » et auditée ; client non attribué introuvable ; dossiers limités au client ; noms, cycles et suppression sans perte ; lecteur sans rangement ; recherche jamais sur les secrets |
| Explorateur de fichiers | `Feature/Files/FileExplorerTest` (13) | Un dossier racine par client accessible ; envoi privé dans un dossier ; import d'un dossier (arborescence recréée) ; chemins dangereux et dossiers d'un autre client refusés ; contenu vérifié ; pièce jointe d'équipement à la racine puis déplacée sans perdre son lien ; vue d'ensemble limitée à la racine ; suppression d'un dossier (droit de gestion, fichiers liés préservés) ; noms, doublons et cycles ; renommage sans changer l'extension ; lecteur et client archivé en lecture seule ; recherche globale filtrée par droits |
| Fichiers | `Feature/Files/AttachmentTest` (11) | Stockage privé sous un nom généré ; extensions interdites ; contenu différent de l'extension refusé ; limite de taille ; téléchargement contrôlé et forcé ; fichier d'un autre client introuvable ; invité refusé ; SVG jamais affiché en ligne |
| Imports | `Feature/Data/ImportTest` (11) | Correspondance des colonnes ; validation de chaque ligne ; doublons dans le fichier et en base ; exécution refusée tant que des erreurs restent ; modes création et mise à jour ; Excel et CSV Windows-1252 ; droits sur le client cible ; rapport complet téléchargeable et privé |
| Exports | `Feature/Data/ExportTest` (5) | Neutralisation des formules (CSV et XLSX) ; aucun secret ; droit « export » exigé ; export global limité aux clients autorisés |
| Recherche | `Feature/SearchTest` (13) | Nom, numéro de série, modèle, IP et MAC ; aucun résultat ni compteur d'un client non attribué ; secrets jamais trouvés ; contenu des documents ; étiquettes ; liens Internet (opérateur, référence de ligne, IP publique) ; résultats instantanés limités aux clients autorisés, plafonnés par type, refusés sans connexion |
| FAI | `Feature/Internet/InternetLinkTest` (13), `IspCatalogTest` (9), `LegacyInternetServiceTest` (4) | Catalogue chargé avec ses logos ; lien créé avec opérateur, équipements, documents et étiquettes ; opérateur du catalogue ou nom libre ; relations limitées au client ; valeurs invalides refusées ; lien d'un autre client introuvable ; lecteur sans modification ; opérateur masqué refusé pour un nouveau lien mais conservé ; historique, archivage, suppression confirmée ; sous-réseau WAN et accès reliés à un lien du même client ; export ; catalogue réservé aux administrateurs ; logo déposé privé, servi sans script, refusé en SVG ; logos fournis inertes ; opérateur utilisé non supprimable ; synchronisation idempotente qui respecte les modifications ; conversion des services « Connexion Internet » sans perte (champs, étiquettes, pièces jointes, équipements, documents, accès) ; catégorie retirée des nouveaux services |
| Constructeur de fiches | `Feature/Records/RecordBuilderTest` (12), `CustomRecordTest` (10), `SystemExtensionTest` (5) | Réservé aux administrateurs ; table `cx_` et champ titre créés ; noms de tables et de colonnes strictement contrôlés (injection, noms réservés, tables de TechIndex) ; libellés de secrets refusés ; une colonne typée par champ et clés étrangères des liens ; changement de type refusé s'il perd des données ; suppression d'un champ ou d'une fiche confirmée (et bloquée si une autre fiche la désigne) ; fiches par défaut non supprimables ; disposition ; valeurs validées champ par champ ; champs masqués ni obligatoires ni enregistrés ; unicité par client ; historique champ par champ ; droits du client (404, lecteur, gestion) ; recherche et export ; fiches liées ; champs ajoutés à chaque fiche par défaut ; reprise des anciens champs personnalisés des équipements avec leurs valeurs |
| Sécurité HTTP | `Feature/HttpSecurityTest` (6) | En-têtes et CSP à nonce ; erreurs sans détail technique ; protection CSRF active ; affectation de masse ; pages sensibles non mises en cache |
| Gestion | `Feature/Management/InventoryManagementTest` (13) | Logo et étiquettes ; SVG refusé comme logo ; référence unique ; audit des différences ; archivage et restauration ; adresses multiples ; IP et MAC invalides ; liens et sites limités au même client ; détails de service validés par catégorie ; licences et certificats ; plan réseau |
| Documentation | `Feature/Documents/DocumentTest` (5) | HTML dangereux supprimé ; une version par modification ; liens limités au client ; vérification et revue ; lecteur sans modification |
| Tableau de bord | `Feature/DashboardTest` (2) | Indicateurs issus des données réelles et filtrés par droits |
| Pages | `Feature/PagesSmokeTest` (3) | Les 64 pages s'affichent pour un administrateur et pour un lecteur ; données de démonstration complètes et fictives |
| Exploitation | `Feature/Operations/BackupVerifyTest` (3), `Feature/Operations/ScheduleTest` (3) | Test de restauration dans une base dédiée existante (cas Plesk), vidée ensuite ; refus de la base de l'application, d'un nom invalide et d'une base contenant une table étrangère ; worker de file d'attente planifié seulement sans service dédié, un seul à la fois ; témoin d'activité du planificateur signalé par le diagnostic ; tâches de mise à jour selon le mode d'installation |
| Mises à jour | `Feature/Operations/UpdateTest` (13) | Version plus récente signée proposée ; version identique ou antérieure ignorée ; signature d'une autre clé ou manifeste modifié refusés ; aucun appel réseau sans canal configuré ; page réservée aux administrateurs ; installation soumise à la confirmation de l'identité, limitée à la dernière version vérifiée et à une demande à la fois, auditée ; installation depuis l'interface réservée à Plesk ; lancement unique par la tâche planifiée ; issue auditée une fois ; outil de publication et application d'accord sur les signatures |
| Unitaires | `Unit/SecurityPrimitivesTest` (9 méthodes) | Trousseau de clés ; vecteur d'initialisation unique ; masquage de l'audit ; neutralisation des cellules ; normalisation des dates et des MAC ; appartenance à un sous-réseau |

## Vérifié hors de la suite automatisée

Ces opérations ont été exécutées manuellement pendant le développement :

- **Interface**, dans un navigateur :
  - connexion et changement imposé du mot de passe temporaire ;
  - confirmation du mot de passe ;
  - activation de la double authentification (QR code) ;
  - tableau de bord, liste et fiche d'équipement ;
  - révélation d'un secret (en-têtes `no-store` et CSP contrôlés).
- **Sauvegardes** :
  - création ;
  - vérification (30 tables, 15 secrets) ;
  - restauration sur une base vierge (données, pièce jointe, 15 secrets sur 15) ;
  - refus en cas de clé différente ;
  - refus d'une archive altérée **avant** toute suppression de données.
- **Commandes** : `doctor`, `keys-info`, `prune`, `schedule:list`, ainsi que
  l'analyse syntaxique et les fonctions `.env` de `techindex.ps1`.

Ce qui n'a pas pu être exécuté est listé dans
[fonctionnalites-et-limites.md](fonctionnalites-et-limites.md).
