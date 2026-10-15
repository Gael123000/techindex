<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\CustomRecord;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\InternetLink;
use App\Models\License;
use App\Models\Service;
use App\Models\Site;
use App\Rules\AllowedAttachment;
use App\Services\Attachments\AttachmentStorage;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pièces jointes privées. Le téléchargement passe toujours par ce
 * contrôleur : le fichier n'est jamais exposé par le serveur web.
 */
class AttachmentController extends Controller
{
    /**
     * Éléments auxquels une pièce jointe peut être rattachée.
     *
     * @var array<string, class-string<Model>>
     */
    private const ATTACHABLES = [
        'client' => Client::class,
        'site' => Site::class,
        'equipment' => Equipment::class,
        'service' => Service::class,
        'internet_link' => InternetLink::class,
        'custom_record' => CustomRecord::class,
        'document' => Document::class,
        'license' => License::class,
        'certificate' => Certificate::class,
    ];

    public function __construct(
        private readonly AttachmentStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('contribute', $client);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('techindex.attachments.max_size_kb'), new AllowedAttachment],
            'attachable_type' => ['required', Rule::in(array_keys(self::ATTACHABLES))],
            'attachable_id' => ['required', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $attachable = $this->resolveAttachable($client, $data['attachable_type'], (int) $data['attachable_id']);

        $this->storage->store($request->file('file'), $client, $attachable, $request->user(), $data['description'] ?? null);

        return back()->with('success', 'Fichier ajouté.');
    }

    public function show(Request $request, Client $client, Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        $this->audit->log('attachment.downloaded', $attachment, clientId: $client->id);

        return $this->storage->response($attachment, $request->boolean('inline'));
    }

    public function destroy(Request $request, Client $client, Attachment $attachment): RedirectResponse
    {
        $user = $request->user();

        // L'auteur peut retirer son fichier ; sinon le droit de gestion est requis.
        Gate::authorize($attachment->uploaded_by === $user->id ? 'update' : 'delete', $attachment);

        $this->storage->delete($attachment);

        return back()->with('success', 'Fichier supprimé.');
    }

    private function resolveAttachable(Client $client, string $type, int $id): Model
    {
        if ($type === 'client') {
            abort_unless($id === $client->id, 422, 'Élément de rattachement invalide.');

            return $client;
        }

        $class = self::ATTACHABLES[$type];

        /** @var Model|null $model */
        $model = $class::query()->where('client_id', $client->id)->find($id);

        abort_if($model === null, 422, 'Élément de rattachement invalide.');

        return $model;
    }
}
