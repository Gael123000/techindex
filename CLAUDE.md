# TechIndex – consignes pour les agents de développement

Application Laravel 13 (PHP 8.5) + Inertia 3 / Vue 3 / TypeScript / Tailwind 4,
MariaDB 11.8, interface et documentation en français. Architecture et choix :
`docs/architecture.md`.

## Règles de sécurité non négociables

- Les secrets clients ne sont lus ou écrits **que** par `App\Secrets\CredentialSecrets`,
  les identifiants d'API des intégrations **que** par `App\Secrets\IntegrationSecrets`.
  Jamais de valeur de secret dans une page Inertia, un journal, l'audit, la
  recherche, un export, une notification ou un message d'erreur.
- Intégrations : un connecteur (`app/Services/Integrations/Providers`) n'appelle
  que les domaines de son fournisseur (`ServiceUrl` pour une adresse
  configurable) et ne remonte que des `IntegrationException` sans corps de réponse.
- Tout accès à une donnée client passe par une politique (`ClientPolicy`,
  `ClientResourcePolicy`...) ou par `scopeVisibleTo()` dans la requête SQL.
  Masquer un bouton n'est pas un contrôle. Client non attribué = 404.
- Routes imbriquées sous `/clients/{client}` avec `scopeBindings()`.
- Validation serveur systématique (`FormRequest` ou `$request->validate`) et
  utilisation exclusive des données validées ; attributs `#[Fillable]` explicites.
- HTML riche : toujours filtré par `App\Services\Documents\HtmlSanitizer`.
- Fichiers : `App\Services\Attachments\AttachmentStorage` (disque privé, nom
  généré, type vérifié par `MimeDetector`).
- Exports : `SpreadsheetWriter` (neutralisation des formules), jamais de secrets.
- Toute action sensible est auditée via `AuditLogger` ; les champs sensibles
  sont masqués par `Redactor`.

## Conventions

- Code et noms en anglais, textes visibles et commentaires en français.
- Statuts et listes de valeurs : énumérations dans `app/Enums` (`label()`, `options()`).
- Modèles : traits `BelongsToClient`, `Auditable`, `TracksAuthors`, `HasTags`.
- `Model::shouldBeStrict()` est actif hors production : sélectionner toutes
  les colonnes utilisées.
- Interface : composants de `resources/js/components` (FormField, TextInput,
  SelectInput, Modal, ConfirmDialog...). Pas de `//` littéral dans un attribut
  de template Vue (bogue vue-tsc) : passer par une constante.

## Vérifications avant de terminer

```bash
php artisan test                # MariaDB, base techindex_test (garde-fou _test)
npm run typecheck && npm run build
vendor/bin/pint
```

Ajouter ou adapter un test pour tout changement de droits, de secrets,
de fichiers, d'imports ou d'exports.
