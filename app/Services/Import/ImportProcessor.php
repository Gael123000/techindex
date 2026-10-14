<?php

namespace App\Services\Import;

use App\Enums\ImportType;
use App\Models\Client;
use App\Models\Import;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\EquipmentService;
use App\Services\Spreadsheets\SpreadsheetReader;
use App\Services\Tags\TagResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Analyse et exécution d'un import.
 *
 * L'analyse (aperçu) valide chaque ligne, détecte les doublons dans le
 * fichier et en base selon la règle explicite de la définition, et indique
 * l'action prévue : création, mise à jour, ignorée ou erreur.
 *
 * L'exécution rejoue l'analyse puis applique toutes les lignes valides dans
 * une seule transaction : en cas d'erreur, rien n'est enregistré.
 */
class ImportProcessor
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly TagResolver $tags,
        private readonly EquipmentService $equipment,
        private readonly AuditLogger $audit,
    ) {}

    public function definition(Import $import): ImportDefinition
    {
        return match ($import->type) {
            ImportType::Clients => new ClientImportDefinition($this->tags),
            ImportType::Equipment => new EquipmentImportDefinition($import->client ?? throw new RuntimeException('Client cible manquant.'), $this->equipment),
        };
    }

    public static function definitionFor(ImportType $type): ImportDefinition
    {
        return $type === ImportType::Clients
            ? new ClientImportDefinition(app(TagResolver::class))
            : new EquipmentImportDefinition(new Client, app(EquipmentService::class));
    }

    /**
     * @return array{headers: list<string>, rows: list<array{line: int, values: list<string>}>, truncated: bool}
     */
    public function read(Import $import): array
    {
        $disk = Storage::disk('local');

        if ($import->stored_path === null || ! $disk->exists($import->stored_path)) {
            throw new RuntimeException('Le fichier importé n\'est plus disponible : recommencez l\'import.');
        }

        return $this->reader->read(
            $disk->path($import->stored_path),
            pathinfo($import->original_filename, PATHINFO_EXTENSION),
            (int) config('techindex.imports.max_rows'),
        );
    }

    /**
     * Correspondance automatique : en-tête du fichier → colonne attendue.
     *
     * @param  list<string>  $headers
     * @return list<string|null>
     */
    public function guessMapping(array $headers, ImportDefinition $definition): array
    {
        $candidates = [];

        foreach ($definition->columns() as $key => $column) {
            foreach ([$key, $column['label'], ...($column['aliases'] ?? [])] as $name) {
                $candidates[Normalizer::key($name)] ??= $key;
            }
        }

        $used = [];

        return array_map(function (string $header) use ($candidates, &$used): ?string {
            $key = $candidates[Normalizer::key($header)] ?? null;

            if ($key === null || in_array($key, $used, true)) {
                return null;
            }

            $used[] = $key;

            return $key;
        }, $headers);
    }

    /**
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>, missing_required: list<string>, truncated: bool, duplicate_rule: string}
     */
    public function analyze(Import $import, User $user): array
    {
        $definition = $this->definition($import);
        $file = $this->read($import);
        $mapping = $import->mapping ?? [];
        $columns = $definition->columns();

        $mapped = array_values(array_filter($mapping));
        $missing = [];

        foreach ($columns as $key => $column) {
            if ($column['required'] && ! in_array($key, $mapped, true)) {
                $missing[] = $column['label'];
            }
        }

        $summary = ['total' => count($file['rows']), 'create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
        $rows = [];
        $seen = [];

        foreach ($file['rows'] as $row) {
            $raw = [];

            foreach ($mapping as $index => $key) {
                if ($key !== null && isset($columns[$key])) {
                    $raw[$key] = $row['values'][$index] ?? '';
                }
            }

            $data = $definition->normalize($raw);
            $validator = Validator::make($data, $definition->rules(), $definition->messages(), $this->attributeNames($columns));
            $errors = $validator->fails() ? array_values(array_unique($validator->errors()->all())) : [];
            $messages = [];
            $action = 'error';
            $key = $definition->duplicateKey($data);

            if ($key !== null && isset($seen[$key])) {
                $errors[] = 'Doublon de la ligne '.$seen[$key].' dans le fichier.';
            } elseif ($key !== null) {
                $seen[$key] = $row['line'];
            }

            if ($errors === [] && $missing === []) {
                $existing = $definition->findExisting($data);

                if ($existing !== null) {
                    if (! $import->mode->allowsUpdate()) {
                        $action = 'skip';
                        $messages[] = 'Existe déjà : ignoré (mode création uniquement).';
                    } elseif (! $definition->canUpdate($existing, $user)) {
                        $errors[] = 'Un enregistrement existant correspond, mais vos droits ne permettent pas de le modifier.';
                    } else {
                        $action = 'update';
                    }
                } elseif (! $import->mode->allowsCreate()) {
                    $action = 'skip';
                    $messages[] = 'Introuvable : ignoré (mode mise à jour uniquement).';
                } else {
                    $action = 'create';
                }
            }

            if ($errors !== []) {
                $action = 'error';
            }

            $summary[$action]++;

            $rows[] = [
                'line' => $row['line'],
                'label' => $definition->rowLabel($data),
                'action' => $action,
                'errors' => $errors,
                'messages' => $messages,
                'data' => $data,
            ];
        }

        return [
            'summary' => $summary,
            'rows' => $rows,
            'missing_required' => $missing,
            'truncated' => $file['truncated'],
            'duplicate_rule' => $definition->duplicateRule(),
        ];
    }

    /**
     * Rapport conservé avec l'import : toutes les lignes (le nombre de lignes
     * est plafonné à l'import), sans les données normalisées.
     *
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    public function report(array $analysis): array
    {
        return [
            'summary' => $analysis['summary'],
            'missing_required' => $analysis['missing_required'],
            'truncated' => $analysis['truncated'],
            'duplicate_rule' => $analysis['duplicate_rule'],
            'rows' => array_map(
                fn (array $row): array => array_diff_key($row, ['data' => true]),
                $analysis['rows'],
            ),
        ];
    }

    /**
     * Lignes du rapport téléchargeable (une ligne par ligne du fichier).
     *
     * @param  array<string, mixed>  $report
     * @return list<list<string|int>>
     */
    public function reportRows(array $report): array
    {
        $actions = ['create' => 'Création', 'update' => 'Mise à jour', 'skip' => 'Ignorée', 'error' => 'Erreur'];

        return array_map(fn (array $row): array => [
            (int) $row['line'],
            (string) ($row['label'] ?? ''),
            $actions[$row['action']] ?? (string) $row['action'],
            implode(' | ', $row['errors'] ?? []),
            implode(' | ', $row['messages'] ?? []),
        ], $report['rows'] ?? []);
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: int}
     */
    public function execute(Import $import, User $user): array
    {
        $analysis = $this->analyze($import, $user);

        if ($analysis['missing_required'] !== []) {
            throw new ImportRejectedException('Colonnes obligatoires non associées : '.implode(', ', $analysis['missing_required']).'.');
        }

        if ($analysis['summary']['error'] > 0 && ! $import->skip_invalid_rows) {
            throw new ImportRejectedException('Le fichier contient des lignes en erreur. Corrigez-les ou choisissez explicitement de les ignorer.');
        }

        $definition = $this->definition($import);
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => $analysis['summary']['skip'], 'errors' => $analysis['summary']['error']];

        // Une seule transaction : en cas d'échec, aucune ligne n'est enregistrée.
        DB::transaction(function () use ($analysis, $definition, $user, &$counts): void {
            $this->audit->withoutLogging(function () use ($analysis, $definition, $user, &$counts): void {
                foreach ($analysis['rows'] as $row) {
                    if ($row['action'] === 'create') {
                        $definition->create($row['data'], $user);
                        $counts['created']++;
                    } elseif ($row['action'] === 'update') {
                        $existing = $definition->findExisting($row['data']) ?? throw new RuntimeException("Ligne {$row['line']} : l'enregistrement à mettre à jour a disparu.");
                        $definition->update($existing, $row['data'], $user);
                        $counts['updated']++;
                    }
                }
            });
        });

        $import->forceFill(['report' => $this->report($analysis)])->save();

        return $counts;
    }

    /**
     * @param  array<string, array<string, mixed>>  $columns
     * @return array<string, string>
     */
    private function attributeNames(array $columns): array
    {
        $names = [];

        foreach ($columns as $key => $column) {
            $names[$key] = '« '.$column['label'].' »';
        }

        return $names;
    }
}
