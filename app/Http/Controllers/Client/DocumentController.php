<?php

namespace App\Http\Controllers\Client;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Concerns\HasRecordExtensions;
use App\Http\Controllers\Concerns\InteractsWithClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentRequest;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentService;
use App\Services\Documents\HtmlSanitizer;
use App\Services\Tags\TagResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    use HasRecordExtensions, InteractsWithClient;

    public function __construct(
        private readonly DocumentService $service,
        private readonly HtmlSanitizer $sanitizer,
        private readonly TagResolver $tags,
    ) {}

    public function index(Request $request, Client $client): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([...DocumentStatus::values(), 'current', 'all'])],
            'review' => ['nullable', 'boolean'],
            'tag' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(['title', 'updated_at', 'last_verified_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $status = $filters['status'] ?? 'current';

        $documents = $client->documents()
            ->when($status === 'current', fn (Builder $query) => $query->where('status', '!=', DocumentStatus::Archived))
            ->when(! in_array($status, ['current', 'all'], true), fn (Builder $query) => $query->where('status', $status))
            ->when($filters['category'] ?? null, fn (Builder $query, int $category) => $query->where('category_id', $category))
            ->when($filters['review'] ?? false, fn (Builder $query) => $query->needingReview())
            ->when($filters['tag'] ?? null, fn (Builder $query, int $tag) => $query->whereHas('tags', fn (Builder $query) => $query->whereKey($tag)))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $query) => $query
                ->where('title', 'like', "%{$term}%")->orWhere('content_text', 'like', "%{$term}%")))
            ->with(['category:id,name', 'owner:id,name', 'tags:id,name,color'])
            ->select(['id', 'client_id', 'category_id', 'title', 'status', 'owner_id', 'review_interval_days', 'last_verified_at', 'current_version', 'updated_at'])
            ->orderBy($filters['sort'] ?? 'title', $filters['direction'] ?? 'asc')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Document $document): Document => $document->append(['needs_review', 'next_review_at']));

        return Inertia::render('Documents/Index', [
            'context' => $this->context($client, $request),
            'documents' => $documents,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'category' => $filters['category'] ?? null,
                'status' => $status,
                'review' => (bool) ($filters['review'] ?? false),
                'tag' => $filters['tag'] ?? null,
                'sort' => $filters['sort'] ?? 'title',
                'direction' => $filters['direction'] ?? 'asc',
            ],
            'categories' => $this->categoryOptions(),
            'statuses' => DocumentStatus::options(),
            'tags' => $this->tags->options(),
        ]);
    }

    public function create(Request $request, Client $client): Response
    {
        Gate::authorize('contribute', $client);

        return Inertia::render('Documents/Form', [
            'context' => $this->context($client, $request),
            'document' => null,
            'defaults' => [
                'owner_id' => $request->user()->id,
                'review_interval_days' => (int) config('techindex.documents.default_review_interval_days'),
                'equipment_ids' => array_filter([(int) $request->query('equipment')]),
                'service_ids' => array_filter([(int) $request->query('service')]),
                'internet_link_ids' => array_filter([(int) $request->query('internet_link')]),
            ],
            'extension' => $this->extensionForm('document', $client, null),
            ...$this->formOptions($client),
        ]);
    }

    public function store(DocumentRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $document = $this->service->save(new Document, $client, $request->validated(), $request->user());

        return redirect()->route('clients.documents.show', [$client, $document])->with('success', 'Fiche créée.');
    }

    public function show(Request $request, Client $client, Document $document): Response
    {
        Gate::authorize('view', $document);

        $user = $request->user();
        $document->load([
            'category:id,name',
            'owner:id,name',
            'verifier:id,name',
            'tags:id,name,color',
            'equipment:id,client_id,name,hostname',
            'services:id,client_id,name,category',
            'internetLinks:id,client_id,name,isp_id,operator_name',
            'internetLinks.isp',
            'creator:id,name',
            'editor:id,name',
        ]);

        return Inertia::render('Documents/Show', [
            'context' => $this->context($client, $request),
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                // Nettoyé à nouveau à l'affichage (défense en profondeur).
                'content_html' => $this->sanitizer->sanitize($document->content_html),
                'status' => $document->status->value,
                'status_label' => $document->status->label(),
                'category' => $document->category?->only(['id', 'name']),
                'owner' => $document->owner?->only(['id', 'name']),
                'verifier' => $document->verifier?->only(['id', 'name']),
                'last_verified_at' => $document->last_verified_at?->toIso8601String(),
                'next_review_at' => $document->next_review_at,
                'needs_review' => $document->needs_review,
                'review_interval_days' => $document->review_interval_days,
                'current_version' => $document->current_version,
                'tags' => $document->tags,
                'equipment' => $document->equipment,
                'services' => $document->services->map(fn ($service): array => [
                    'id' => $service->id, 'name' => $service->name, 'category' => $service->category->label(),
                ]),
                'internet_links' => $document->internetLinks->map(fn ($link): array => [
                    'id' => $link->id, 'name' => $link->name, 'operator' => $link->operatorLabel(),
                ]),
                'created_at' => $document->created_at?->toIso8601String(),
                'updated_at' => $document->updated_at?->toIso8601String(),
                'creator' => $document->creator?->only(['id', 'name']),
                'editor' => $document->editor?->only(['id', 'name']),
            ],
            'versions' => $document->versions()->with('author:id,name')->get(['id', 'document_id', 'version', 'title', 'change_summary', 'created_by', 'created_at']),
            'attachments' => $this->attachmentsFor($document, $client, $user),
            'history' => $this->historyFor($document, $user),
            'extension' => $this->extensionDisplay('document', $client, $document, [
                'category_id' => $document->category_id === null ? null : (string) $document->category_id,
                'status' => $document->status->value,
            ]),
            'can' => [
                'update' => Gate::allows('update', $document),
                'delete' => Gate::allows('delete', $document),
            ],
        ]);
    }

    public function edit(Request $request, Client $client, Document $document): Response
    {
        Gate::authorize('update', $document);

        return Inertia::render('Documents/Form', [
            'context' => $this->context($client, $request),
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'content' => $this->sanitizer->sanitize($document->content_html),
                'category_id' => $document->category_id,
                'status' => $document->status->value,
                'owner_id' => $document->owner_id,
                'review_interval_days' => $document->review_interval_days,
                'equipment_ids' => $document->equipment()->pluck('equipment.id'),
                'service_ids' => $document->services()->pluck('services.id'),
                'internet_link_ids' => $document->internetLinks()->pluck('internet_links.id'),
                'tags' => $document->tags()->pluck('name'),
                'current_version' => $document->current_version,
            ],
            'defaults' => null,
            'extension' => $this->extensionForm('document', $client, $document),
            ...$this->formOptions($client),
        ]);
    }

    public function update(DocumentRequest $request, Client $client, Document $document): RedirectResponse
    {
        Gate::authorize('update', $document);

        $this->service->save($document, $client, $request->validated(), $request->user());

        return redirect()->route('clients.documents.show', [$client, $document])->with('success', 'Fiche mise à jour.');
    }

    /**
     * Marque la fiche comme vérifiée (contenu toujours exact) à la date du jour.
     */
    public function verify(Request $request, Client $client, Document $document, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('update', $document);

        $document->forceFill([
            'last_verified_at' => now(),
            'last_verified_by' => $request->user()->id,
        ])->saveQuietly();

        $audit->log('document.verified', $document, clientId: $client->id);

        return back()->with('success', 'Fiche marquée comme vérifiée.');
    }

    public function version(Request $request, Client $client, Document $document, DocumentVersion $version): Response
    {
        Gate::authorize('view', $document);

        $version->load('author:id,name');

        return Inertia::render('Documents/Version', [
            'context' => $this->context($client, $request),
            'document' => ['id' => $document->id, 'title' => $document->title, 'current_version' => $document->current_version],
            'version' => [
                'id' => $version->id,
                'version' => $version->version,
                'title' => $version->title,
                'content_html' => $this->sanitizer->sanitize($version->content_html),
                'change_summary' => $version->change_summary,
                'author' => $version->author?->only(['id', 'name']),
                'created_at' => $version->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request, Client $client, Document $document): RedirectResponse
    {
        Gate::authorize('delete', $document);
        $this->confirmDeletion($request, $document->title);

        $document->delete();

        return redirect()->route('clients.documents.index', $client)->with('success', 'Fiche supprimée définitivement.');
    }

    /**
     * @return Collection<int, array{value: int, label: string}>
     */
    private function categoryOptions(): Collection
    {
        return DocumentCategory::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
            ->map(fn (DocumentCategory $category): array => ['value' => $category->id, 'label' => $category->name]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Client $client): array
    {
        return [
            'categories' => $this->categoryOptions(),
            'statuses' => DocumentStatus::options(),
            'owners' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user): array => ['value' => $user->id, 'label' => $user->name]),
            'equipment' => $this->equipmentOptions($client),
            'services' => $this->serviceOptions($client),
            'internetLinks' => $this->internetLinkOptions($client),
            'tags' => $this->tags->options(),
        ];
    }
}
