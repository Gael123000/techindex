<?php

namespace App\Http\Controllers;

use App\Enums\ClientPermission;
use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Jobs\ProcessImport;
use App\Models\Client;
use App\Models\Import;
use App\Rules\AllowedAttachment;
use App\Services\Import\ImportProcessor;
use App\Services\Spreadsheets\SpreadsheetWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Import en quatre étapes : chargement du fichier, correspondance des
 * colonnes, aperçu validé ligne par ligne, exécution en file d'attente avec
 * rapport final.
 */
class ImportController extends Controller
{
    private const PREVIEW_ROWS = 200;

    public function __construct(private readonly ImportProcessor $processor) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Import::class);

        $user = $request->user();

        return Inertia::render('Imports/Index', [
            'imports' => Import::query()
                ->when(! $user->isAdmin(), fn ($query) => $query->where('user_id', $user->id))
                ->with(['user:id,name', 'client:id,name'])
                ->latest('id')
                ->paginate(25)
                ->through(fn (Import $import): array => $this->summary($import)),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('viewAny', Import::class);

        $user = $request->user();

        return Inertia::render('Imports/Create', [
            'types' => collect(ImportType::cases())
                ->filter(fn (ImportType $type): bool => $type === ImportType::Equipment || $user->canCreateClients())
                ->map(fn (ImportType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'duplicate_rule' => ImportProcessor::definitionFor($type)->duplicateRule(),
                    'templates' => [
                        'csv' => route('imports.template', [$type->value, 'csv']),
                        'xlsx' => route('imports.template', [$type->value, 'xlsx']),
                    ],
                ])->values(),
            'modes' => ImportMode::options(),
            'clients' => Client::query()->visibleTo($user, ClientPermission::Edit)->where('status', 'active')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Client $client): array => ['value' => $client->id, 'label' => $client->name]),
            'defaultClient' => $request->integer('client') ?: null,
            'maxSizeKb' => (int) config('techindex.imports.max_size_kb'),
            'maxRows' => (int) config('techindex.imports.max_rows'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Import::class);

        $user = $request->user();
        $data = $request->validate([
            'type' => ['required', Rule::enum(ImportType::class)],
            'mode' => ['required', Rule::enum(ImportMode::class)],
            'client_id' => ['nullable', 'required_if:type,equipment', 'integer', Rule::exists('clients', 'id')],
            'file' => ['required', 'file', 'max:'.config('techindex.imports.max_size_kb'), new AllowedAttachment([
                'csv' => ['text/csv', 'text/plain', 'application/csv'],
                'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            ])],
        ]);

        $type = ImportType::from($data['type']);

        if ($type === ImportType::Clients) {
            Gate::authorize('create-clients');
            $data['client_id'] = null;
        } else {
            $client = Client::query()->findOrFail($data['client_id']);
            Gate::authorize('contribute', $client);
        }

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('imports', Str::uuid().'.'.$extension, 'local');

        $import = new Import;
        $import->forceFill([
            'user_id' => $user->id,
            'client_id' => $data['client_id'],
            'type' => $type,
            'mode' => ImportMode::from($data['mode']),
            'status' => ImportStatus::Uploaded,
            'original_filename' => Str::limit($file->getClientOriginalName(), 250, ''),
            'stored_path' => $path,
        ])->save();

        try {
            $content = $this->processor->read($import);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            $import->delete();

            report($exception);

            throw ValidationException::withMessages(['file' => 'Le fichier n\'a pas pu être lu. Vérifiez qu\'il s\'agit d\'un CSV (UTF-8 ou Windows) ou d\'un classeur Excel .xlsx.']);
        }

        if ($content['headers'] === []) {
            Storage::disk('local')->delete($path);
            $import->delete();

            throw ValidationException::withMessages(['file' => 'Le fichier est vide ou sa première ligne ne contient pas d\'en-têtes.']);
        }

        $import->forceFill([
            'headers' => $content['headers'],
            'mapping' => $this->processor->guessMapping($content['headers'], $this->processor->definition($import)),
            'total_rows' => count($content['rows']),
        ])->save();

        return redirect()->route('imports.show', $import)->with('success', 'Fichier chargé : vérifiez la correspondance des colonnes et l\'aperçu.');
    }

    public function show(Request $request, Import $import): Response
    {
        Gate::authorize('view', $import);

        $import->load(['user:id,name', 'client:id,name']);
        $definition = $this->processor->definition($import);
        $analysis = null;
        $error = null;

        if (in_array($import->status, [ImportStatus::Uploaded, ImportStatus::Validated], true)) {
            try {
                $analysis = $this->processor->analyze($import, $request->user());
                $analysis['rows'] = array_map(
                    fn (array $row): array => array_diff_key($row, ['data' => true]),
                    array_slice($analysis['rows'], 0, self::PREVIEW_ROWS),
                );
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        } elseif (is_array($import->report)) {
            $analysis = $import->report;
            $analysis['rows'] = array_slice($analysis['rows'] ?? [], 0, self::PREVIEW_ROWS);
        }

        return Inertia::render('Imports/Show', [
            'import' => [
                ...$this->summary($import),
                'headers' => $import->headers ?? [],
                'mapping' => $import->mapping ?? [],
                'skip_invalid_rows' => $import->skip_invalid_rows,
                'failure_reason' => $import->failure_reason,
                'started_at' => $import->started_at?->toIso8601String(),
                'finished_at' => $import->finished_at?->toIso8601String(),
            ],
            'columns' => collect($definition->columns())->map(fn (array $column, string $key): array => [
                'key' => $key,
                'label' => $column['label'],
                'required' => $column['required'],
                'help' => $column['help'] ?? null,
            ])->values(),
            'analysis' => $analysis,
            'previewLimit' => self::PREVIEW_ROWS,
            'error' => $error,
            'modes' => ImportMode::options(),
            'can' => ['execute' => Gate::allows('execute', $import)],
        ]);
    }

    /**
     * Rapport complet, ligne par ligne : aperçu courant avant exécution,
     * rapport enregistré ensuite.
     */
    public function report(Request $request, Import $import, string $format, SpreadsheetWriter $writer): StreamedResponse
    {
        Gate::authorize('view', $import);

        if (in_array($import->status, [ImportStatus::Uploaded, ImportStatus::Validated], true)) {
            try {
                $report = $this->processor->report($this->processor->analyze($import, $request->user()));
            } catch (RuntimeException $exception) {
                abort(409, $exception->getMessage());
            }
        } else {
            $report = $import->report ?? [];
        }

        return $writer->download(
            'rapport-import-'.$import->id,
            $format,
            ['Ligne', 'Élément', 'Action', 'Erreurs', 'Remarques'],
            $this->processor->reportRows($report),
        );
    }

    public function updateMapping(Request $request, Import $import): RedirectResponse
    {
        Gate::authorize('execute', $import);
        abort_unless(in_array($import->status, [ImportStatus::Uploaded, ImportStatus::Validated], true), 409, 'Cet import a déjà été exécuté.');

        $columns = array_keys($this->processor->definition($import)->columns());
        $headerCount = count($import->headers ?? []);

        $data = $request->validate([
            'mapping' => ['required', 'array', 'size:'.$headerCount],
            'mapping.*' => ['nullable', Rule::in($columns)],
            'mode' => ['required', Rule::enum(ImportMode::class)],
            'skip_invalid_rows' => ['boolean'],
        ], [
            'mapping.size' => 'La correspondance doit couvrir toutes les colonnes du fichier.',
        ]);

        $mapped = array_values(array_filter($data['mapping']));

        if (count($mapped) !== count(array_unique($mapped))) {
            throw ValidationException::withMessages(['mapping' => 'Une même colonne TechIndex ne peut être associée qu\'à une seule colonne du fichier.']);
        }

        $import->forceFill([
            'mapping' => array_map(fn ($value) => $value ?: null, $data['mapping']),
            'mode' => ImportMode::from($data['mode']),
            'skip_invalid_rows' => (bool) ($data['skip_invalid_rows'] ?? false),
            'status' => ImportStatus::Validated,
        ])->save();

        return redirect()->route('imports.show', $import)->with('success', 'Correspondance enregistrée : l\'aperçu a été recalculé.');
    }

    public function execute(Request $request, Import $import): RedirectResponse
    {
        Gate::authorize('execute', $import);
        abort_unless(in_array($import->status, [ImportStatus::Uploaded, ImportStatus::Validated], true), 409, 'Cet import a déjà été exécuté.');

        $analysis = $this->processor->analyze($import, $request->user());

        if ($analysis['missing_required'] !== []) {
            return back()->with('error', 'Associez d\'abord les colonnes obligatoires : '.implode(', ', $analysis['missing_required']).'.');
        }

        if ($analysis['summary']['error'] > 0 && ! $import->skip_invalid_rows) {
            return back()->with('error', 'Des lignes sont en erreur : corrigez le fichier ou cochez explicitement « ignorer les lignes en erreur ».');
        }

        if ($analysis['summary']['create'] + $analysis['summary']['update'] === 0) {
            return back()->with('error', 'Aucune ligne à créer ou à mettre à jour.');
        }

        $import->forceFill([
            'status' => ImportStatus::Queued,
            'valid_rows' => $analysis['summary']['create'] + $analysis['summary']['update'],
            'report' => $this->processor->report($analysis),
        ])->save();

        ProcessImport::dispatch($import->id);

        return redirect()->route('imports.show', $import)->with('success', 'Import lancé. Le rapport s\'affichera à la fin du traitement.');
    }

    public function destroy(Import $import): RedirectResponse
    {
        Gate::authorize('view', $import);
        abort_if(in_array($import->status, [ImportStatus::Queued, ImportStatus::Processing], true), 409, 'Import en cours de traitement.');

        if ($import->stored_path !== null) {
            Storage::disk('local')->delete($import->stored_path);
        }

        $import->delete();

        return redirect()->route('imports.index')->with('success', 'Import supprimé.');
    }

    public function template(Request $request, string $type, string $format, SpreadsheetWriter $writer): StreamedResponse
    {
        Gate::authorize('viewAny', Import::class);

        $definition = ImportProcessor::definitionFor(ImportType::from($type));
        $columns = $definition->columns();

        return $writer->download(
            'modele-import-'.$type,
            $format,
            array_values(array_map(fn (array $column): string => $column['label'], $columns)),
            [array_values(array_map(fn (array $column): string => $column['example'], $columns))],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Import $import): array
    {
        return [
            'id' => $import->id,
            'type' => $import->type->value,
            'type_label' => $import->type->label(),
            'mode' => $import->mode->value,
            'mode_label' => $import->mode->label(),
            'status' => $import->status->value,
            'status_label' => $import->status->label(),
            'finished' => $import->status->isFinished(),
            'original_filename' => $import->original_filename,
            'client' => $import->client?->only(['id', 'name']),
            'user' => $import->user?->only(['id', 'name']),
            'total_rows' => $import->total_rows,
            'created_count' => $import->created_count,
            'updated_count' => $import->updated_count,
            'skipped_count' => $import->skipped_count,
            'error_count' => $import->error_count,
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }
}
