<?php

namespace App\Http\Controllers\Client;

use App\Enums\RecordFieldType;
use App\Enums\RecordStatus;
use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CustomRecord;
use App\Models\RecordField;
use App\Models\RecordType;
use App\Services\Audit\AuditLogger;
use App\Services\Records\RecordValues;
use App\Services\Spreadsheets\SpreadsheetWriter;
use App\Services\Tags\TagResolver;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Enregistrements des fiches créées avec le constructeur (un onglet par
 * fiche active). Droits : ceux des autres ressources du client
 * (ClientResourcePolicy) ; les valeurs sont validées champ par champ.
 */
class CustomRecordController extends Controller
{
    use InteractsWithClient;

    public function __construct(
        private readonly RecordValues $values,
        private readonly TagResolver $tags,
    ) {}

    public function index(Request $request, Client $client, RecordType $recordType): Response
    {
        $this->assertUsable($recordType);

        $listed = $this->values->fields($recordType)->filter(fn (RecordField $field): bool => $field->in_list && ! $field->is_title)->values();
        $sortable = ['title', 'created_at', 'updated_at', ...$listed->pluck('key')->all()];

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            'site' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in($sortable)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $status = $filters['status'] ?? 'active';
        $sort = $filters['sort'] ?? ($recordType->setting('sort_field') ?: 'title');
        $sort = in_array($sort, $sortable, true) ? $sort : 'title';
        $direction = $filters['direction'] ?? ($recordType->setting('sort_direction') ?: 'asc');

        $query = $client->customRecords()
            ->where('custom_records.record_type_id', $recordType->id)
            ->select('custom_records.*')
            ->when($status !== 'all', fn (Builder $query) => $query->where('custom_records.status', $status))
            ->when($filters['site'] ?? null, fn (Builder $query, int $site) => $query->where('custom_records.site_id', $site))
            ->when($filters['q'] ?? null, function (Builder $query, string $term): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $query->where(fn (Builder $query) => $query->where('custom_records.title', 'like', $like)->orWhere('custom_records.search_text', 'like', $like));
            })
            ->with(['site:id,name', 'tags:id,name,color']);

        $sortField = $listed->firstWhere('key', $sort);

        if ($sortField !== null && $recordType->hasDataTable()) {
            // Colonne issue des métadonnées contrôlées (RecordSchema).
            $query->leftJoin($recordType->table_name.' as data', 'data.record_id', '=', 'custom_records.id')
                ->orderBy('data.'.$sortField->column_name, $direction);
        } else {
            $query->orderBy('custom_records.'.(in_array($sort, ['created_at', 'updated_at'], true) ? $sort : 'title'), $direction);
        }

        $records = $query->orderBy('custom_records.id')->paginate(25)->withQueryString();
        $stored = $this->values->load($recordType, $records->getCollection()->pluck('id')->all());
        $labels = $this->values->referenceLabelsFor($recordType, array_values($stored), $client->id);

        $records->setCollection($records->getCollection()->map(fn (CustomRecord $record): array => [
            'id' => $record->id,
            'title' => $record->title,
            'status' => $record->status->value,
            'site' => $record->site?->only(['id', 'name']),
            'tags' => $record->tags->map->only(['id', 'name', 'color'])->values(),
            'updated_at' => $record->updated_at?->toIso8601String(),
            'cells' => $listed->mapWithKeys(fn (RecordField $field): array => [
                $field->key => $this->values->display($field, $stored[$record->id][$field->key] ?? null, $labels, 120),
            ]),
        ]));

        return Inertia::render('Records/Index', [
            'context' => $this->context($client, $request),
            'recordType' => $this->typeProps($recordType),
            'columns' => $listed->map(fn (RecordField $field): array => ['key' => $field->key, 'label' => $field->label, 'type' => $field->type->value])->values(),
            'records' => $records,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $status,
                'site' => $filters['site'] ?? null,
                'sort' => $sort,
                'direction' => $direction,
            ],
            'sites' => $recordType->setting('has_site', true) ? $this->siteOptions($client) : [],
        ]);
    }

    public function create(Request $request, Client $client, RecordType $recordType): Response
    {
        $this->assertUsable($recordType);
        Gate::authorize('contribute', $client);

        return Inertia::render('Records/Form', [
            'context' => $this->context($client, $request),
            'recordType' => $this->typeProps($recordType),
            'record' => null,
            'definition' => [
                ...$this->values->formDefinition($recordType, $client),
                'values' => (object) $this->values->formValues($recordType, null),
            ],
            'sites' => $this->siteOptions($client),
            'tags' => $this->tags->options(),
        ]);
    }

    public function store(Request $request, Client $client, RecordType $recordType): RedirectResponse
    {
        $this->assertUsable($recordType);
        Gate::authorize('contribute', $client);

        $data = $this->validated($request, $client, $recordType, null);

        $record = DB::transaction(function () use ($data, $client, $recordType): CustomRecord {
            $record = new CustomRecord(['site_id' => $data['site_id'] ?? null, 'status' => RecordStatus::Active]);
            $record->forceFill(['record_type_id' => $recordType->id, 'title' => $this->titleFromInput($recordType, $data)]);
            $record->setRelation('recordType', $recordType);
            $record->client()->associate($client);
            $record->save();

            $this->values->save($recordType, $record->id, $client->id, $data, true);
            $this->refreshDerived($record, $recordType, $client);

            if ($recordType->setting('has_tags', true)) {
                $record->tags()->sync($this->tags->idsFor($data['tags'] ?? []));
            }

            return $record;
        });

        return redirect()->route('clients.records.show', [$client, $record])->with('success', $recordType->name.' enregistré(e).');
    }

    public function show(Request $request, Client $client, CustomRecord $customRecord): Response
    {
        Gate::authorize('view', $customRecord);

        $user = $request->user();
        $type = $this->typeOf($customRecord);
        $customRecord->load(['site:id,name', 'tags:id,name,color', 'creator:id,name', 'editor:id,name']);
        $stored = $this->values->load($type, [$customRecord->id])[$customRecord->id] ?? [];

        return Inertia::render('Records/Show', [
            'context' => $this->context($client, $request),
            'recordType' => $this->typeProps($type),
            'record' => [
                'id' => $customRecord->id,
                'title' => $customRecord->title,
                'status' => $customRecord->status->value,
                'site' => $customRecord->site?->only(['id', 'name']),
                'tags' => $customRecord->tags,
                'created_at' => $customRecord->created_at?->toIso8601String(),
                'updated_at' => $customRecord->updated_at?->toIso8601String(),
                'creator' => $customRecord->creator?->only(['id', 'name']),
                'editor' => $customRecord->editor?->only(['id', 'name']),
            ],
            'sections' => $this->values->present($type, $client->id, $stored),
            'backlinks' => $this->backlinks($customRecord, $type, $client),
            'attachments' => $type->setting('has_attachments', true) ? $this->attachmentsFor($customRecord, $client, $user) : null,
            'history' => $this->historyFor($customRecord, $user),
            'can' => [
                'update' => Gate::allows('update', $customRecord),
                'delete' => Gate::allows('delete', $customRecord),
            ],
        ]);
    }

    public function edit(Request $request, Client $client, CustomRecord $customRecord): Response
    {
        Gate::authorize('update', $customRecord);

        $type = $this->typeOf($customRecord);
        $stored = $this->values->load($type, [$customRecord->id])[$customRecord->id] ?? [];

        return Inertia::render('Records/Form', [
            'context' => $this->context($client, $request),
            'recordType' => $this->typeProps($type),
            'record' => [
                'id' => $customRecord->id,
                'title' => $customRecord->title,
                'site_id' => $customRecord->site_id,
                'tags' => $customRecord->tags()->pluck('name'),
            ],
            'definition' => [
                ...$this->values->formDefinition($type, $client),
                'values' => (object) $this->values->formValues($type, $stored),
            ],
            'sites' => $this->siteOptions($client),
            'tags' => $this->tags->options(),
        ]);
    }

    public function update(Request $request, Client $client, CustomRecord $customRecord): RedirectResponse
    {
        Gate::authorize('update', $customRecord);

        $type = $this->typeOf($customRecord);
        $data = $this->validated($request, $client, $type, $customRecord);

        DB::transaction(function () use ($data, $client, $type, $customRecord): void {
            $customRecord->fill(['site_id' => $data['site_id'] ?? null]);

            foreach ($this->values->save($type, $customRecord->id, $client->id, $data, false) as $label => $change) {
                $customRecord->addAuditChange('Champ « '.$label.' »', $change['old'], $change['new']);
            }

            if ($type->setting('has_tags', true)) {
                $customRecord->syncTagsWithAudit($this->tags->idsFor($data['tags'] ?? []));
            }

            $stored = $this->values->load($type, [$customRecord->id])[$customRecord->id] ?? [];
            $customRecord->forceFill([
                'title' => $this->values->title($type, $stored),
                'search_text' => $this->values->searchText($type, $client->id, $stored),
            ]);
            $customRecord->save();
            $customRecord->flushPendingAudit();
        });

        return redirect()->route('clients.records.show', [$client, $customRecord])->with('success', 'Modifications enregistrées.');
    }

    public function archive(Client $client, CustomRecord $customRecord): RedirectResponse
    {
        Gate::authorize('archive', $customRecord);
        $this->typeOf($customRecord);

        $customRecord->update(['status' => RecordStatus::Archived]);

        return back()->with('success', 'Fiche archivée.');
    }

    public function restore(Client $client, CustomRecord $customRecord): RedirectResponse
    {
        Gate::authorize('archive', $customRecord);
        $this->typeOf($customRecord);

        $customRecord->update(['status' => RecordStatus::Active]);

        return back()->with('success', 'Fiche restaurée.');
    }

    public function destroy(Request $request, Client $client, CustomRecord $customRecord): RedirectResponse
    {
        Gate::authorize('delete', $customRecord);
        $type = $this->typeOf($customRecord);
        $this->confirmDeletion($request, $customRecord->title);

        DB::transaction(function () use ($customRecord): void {
            DB::table('taggables')->where('taggable_type', 'custom_record')->where('taggable_id', $customRecord->id)->delete();
            // La ligne de la table de la fiche est supprimée en cascade.
            $customRecord->delete();
        });

        return redirect()->route('clients.records.index', [$client, $type->key])->with('success', 'Fiche supprimée définitivement.');
    }

    public function export(Client $client, RecordType $recordType, string $format, SpreadsheetWriter $writer, AuditLogger $audit): StreamedResponse
    {
        $this->assertUsable($recordType);
        Gate::authorize('export', $client);

        $fields = $this->values->fields($recordType);
        $query = $client->customRecords()->where('record_type_id', $recordType->id)->with(['site:id,name', 'tags:id,name']);

        $audit->log('export.generated', $recordType, metadata: [
            'données' => $recordType->plural_name,
            'format' => $format,
            'lignes' => (clone $query)->count(),
        ], clientId: $client->id);

        $rows = function () use ($query, $recordType, $fields, $client): Generator {
            foreach ($query->lazyById(200)->chunk(200) as $chunk) {
                $stored = $this->values->load($recordType, $chunk->pluck('id')->all());
                $labels = $this->values->referenceLabelsFor($recordType, array_values($stored), $client->id);

                foreach ($chunk as $record) {
                    yield [
                        ...$fields->map(fn (RecordField $field): mixed => $this->exportValue($field, $stored[$record->id][$field->key] ?? null, $labels))->all(),
                        $record->site?->name,
                        $record->status->label(),
                        $record->tags->pluck('name')->implode(', '),
                        $record->created_at?->timezone(config('app.display_timezone'))->format('Y-m-d H:i'),
                        $record->updated_at?->timezone(config('app.display_timezone'))->format('Y-m-d H:i'),
                    ];
                }
            }
        };

        return $writer->download(
            Str::slug($client->reference.'-'.$recordType->key).'-'.now()->format('Ymd-His'),
            $format,
            [...$fields->pluck('label')->all(), 'Site', 'Statut', 'Étiquettes', 'Créé le', 'Mis à jour le'],
            $rows(),
        );
    }

    /**
     * @param  array<string, array<int, string>>  $labels
     */
    private function exportValue(RecordField $field, mixed $value, array $labels): mixed
    {
        return match ($field->type) {
            RecordFieldType::Integer => $value === null ? null : (int) $value,
            RecordFieldType::Decimal, RecordFieldType::Currency => $value === null ? null : (float) $value,
            RecordFieldType::Boolean => $value,
            RecordFieldType::Date => $value,
            default => $this->values->display($field, $value, $labels),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Client $client, RecordType $type, ?CustomRecord $record): array
    {
        $rules = [
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('client_id', $client->id)],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            ...$this->values->rules($type, $client, $request->all(), $record?->id),
        ];

        return $request->validate($rules, [], $this->values->attributes($type));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function titleFromInput(RecordType $type, array $data): string
    {
        $values = [];

        foreach ($this->values->fields($type) as $field) {
            $values[$field->key] = $this->values->normalize($field, $data['fields'][$field->key] ?? null);
        }

        return $this->values->title($type, $values);
    }

    private function refreshDerived(CustomRecord $record, RecordType $type, Client $client): void
    {
        $stored = $this->values->load($type, [$record->id])[$record->id] ?? [];

        $record->forceFill([
            'title' => $this->values->title($type, $stored),
            'search_text' => $this->values->searchText($type, $client->id, $stored),
        ])->saveQuietly();
    }

    /**
     * Fiches qui désignent cet enregistrement par un champ « lien vers une fiche ».
     *
     * @return list<array<string, mixed>>
     */
    private function backlinks(CustomRecord $record, RecordType $type, Client $client): array
    {
        $links = [];
        $fields = RecordField::query()->where('type', RecordFieldType::Reference->value)->with('recordType')->get()
            ->filter(fn (RecordField $field): bool => $field->option('target') === 'record:'.$type->id && ! $field->recordType->is_system);

        foreach ($fields as $field) {
            if (! $field->recordType->hasDataTable()) {
                continue;
            }

            $ids = DB::table($field->recordType->table_name)->where($field->column_name, $record->id)->limit(50)->pluck('record_id');

            CustomRecord::query()->whereIn('id', $ids)->where('client_id', $client->id)->orderBy('title')->get(['id', 'title', 'status'])
                ->each(function (CustomRecord $other) use (&$links, $field, $client): void {
                    $links[] = [
                        'id' => $other->id,
                        'title' => $other->title,
                        'status' => $other->status->value,
                        'type' => $field->recordType->name,
                        'field' => $field->label,
                        'url' => route('clients.records.show', [$client, $other]),
                    ];
                });
        }

        return $links;
    }

    /**
     * @return array<string, mixed>
     */
    private function typeProps(RecordType $type): array
    {
        return [
            ...$type->only(['id', 'key', 'name', 'plural_name', 'description', 'icon']),
            'has_site' => (bool) $type->setting('has_site', true),
            'has_tags' => (bool) $type->setting('has_tags', true),
            'has_attachments' => (bool) $type->setting('has_attachments', true),
        ];
    }

    /**
     * Fiche créée et active ; une fiche par défaut ou désactivée n'a pas d'onglet.
     */
    private function assertUsable(RecordType $type): void
    {
        abort_if($type->is_system || ! $type->is_active, 404);
    }

    private function typeOf(CustomRecord $record): RecordType
    {
        $type = RecordType::query()->findOrFail($record->record_type_id);
        $this->assertUsable($type);
        $record->setRelation('recordType', $type);

        return $type;
    }
}
