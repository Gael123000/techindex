<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ClientPermission;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Credential;
use App\Models\User;
use App\Rules\AllowedAttachment;
use App\Support\AuditPresenter;
use App\Support\ClientContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Outils communs aux pages d'un client : contexte, fil d'Ariane, listes
 * d'options limitées au client, pièces jointes, accès et historique.
 */
trait InteractsWithClient
{
    /**
     * @return array<string, mixed>
     */
    protected function context(Client $client, Request $request): array
    {
        return ClientContext::for($client, $request->user());
    }

    /**
     * @param  list<array{label: string, href?: string}>  $items
     * @return list<array{label: string, href?: string}>
     */
    protected function breadcrumbs(Client $client, array $items = []): array
    {
        return [
            ['label' => 'Clients', 'href' => route('clients.index')],
            ['label' => $client->name, 'href' => route('clients.show', $client)],
            ...$items,
        ];
    }

    /**
     * @return Collection<int, array{value: int, label: string}>
     */
    protected function siteOptions(Client $client): Collection
    {
        return $client->sites()->orderBy('name')->get(['id', 'name', 'status'])
            ->map(fn ($site): array => [
                'value' => $site->id,
                'label' => $site->name.($site->status->value === 'archived' ? ' (archivé)' : ''),
            ]);
    }

    /**
     * @return Collection<int, array{value: int, label: string}>
     */
    protected function equipmentOptions(Client $client): Collection
    {
        return $client->equipment()->orderBy('name')->get(['id', 'name', 'hostname'])
            ->map(fn ($equipment): array => [
                'value' => $equipment->id,
                'label' => $equipment->name.($equipment->hostname ? ' ('.$equipment->hostname.')' : ''),
            ]);
    }

    /**
     * @return Collection<int, array{value: int, label: string}>
     */
    protected function serviceOptions(Client $client): Collection
    {
        return $client->services()->orderBy('name')->get(['id', 'name', 'category'])
            ->map(fn ($service): array => [
                'value' => $service->id,
                'label' => $service->name.' – '.$service->category->label(),
            ]);
    }

    /**
     * Pièces jointes d'un élément, avec les liens de téléchargement contrôlés.
     *
     * Pour la fiche client, seuls les fichiers de la racine de son dossier sont
     * listés : ceux rangés dans des sous-dossiers se consultent dans l'explorateur.
     *
     * @return array<string, mixed>
     */
    protected function attachmentsFor(Model $attachable, Client $client, User $user): array
    {
        $canEdit = $user->can('contribute', $client);
        $canManage = $user->hasClientPermission($client, ClientPermission::Manage);

        $items = Attachment::query()
            ->where('client_id', $client->id)
            ->where('attachable_type', $attachable->getMorphClass())
            ->where('attachable_id', $attachable->getKey())
            ->when($attachable instanceof Client, fn ($query) => $query->whereNull('folder_id'))
            ->with('uploader:id,name')
            ->latest()
            ->get()
            ->map(fn (Attachment $attachment): array => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'description' => $attachment->description,
                'mime_type' => $attachment->mime_type,
                'extension' => $attachment->extension,
                'size' => $attachment->size,
                'created_at' => $attachment->created_at?->toIso8601String(),
                'uploader' => $attachment->uploader?->only(['id', 'name']),
                'download_url' => route('clients.attachments.show', [$client, $attachment]),
                'preview_url' => $attachment->isInlineViewable() ? route('clients.attachments.show', [$client, $attachment, 'inline' => 1]) : null,
                'can_delete' => $canEdit && ($canManage || $attachment->uploaded_by === $user->id),
            ]);

        return [
            'items' => $items,
            'explorer_url' => route('files.clients.show', $client),
            'upload' => $canEdit ? [
                'url' => route('clients.attachments.store', $client),
                'attachable_type' => $attachable->getMorphClass(),
                'attachable_id' => $attachable->getKey(),
                'accept' => AllowedAttachment::acceptAttribute(),
                'max_size_kb' => (int) config('techindex.attachments.max_size_kb'),
            ] : null,
        ];
    }

    /**
     * Accès (métadonnées uniquement) rattachés à un équipement ou un service.
     * La route de révélation dépend de l'écran : fiches (confirmation du mot
     * de passe) ou gestionnaire de mots de passe (déverrouillé par clé d'accès).
     *
     * @param  Collection<int, Credential>  $credentials
     * @return Collection<int, array<string, mixed>>
     */
    protected function presentCredentials(Collection $credentials, Client $client, User $user, string $revealRoute = 'clients.credentials.reveal'): Collection
    {
        $canReveal = $user->hasClientPermission($client, ClientPermission::Secrets);

        return $credentials->map(fn (Credential $credential): array => [
            'id' => $credential->id,
            'label' => $credential->label,
            'access_type' => $credential->access_type->value,
            'access_type_label' => $credential->access_type->label(),
            'username' => $credential->username,
            'url' => $credential->url,
            'host' => $credential->host,
            'port' => $credential->port,
            'notes' => $credential->notes,
            'has_secret' => $credential->has_secret,
            'secret_updated_at' => $credential->secret_updated_at?->toIso8601String(),
            'updated_at' => $credential->updated_at?->toIso8601String(),
            'equipment' => $credential->relationLoaded('equipment') ? $credential->equipment?->only(['id', 'name']) : null,
            'service' => $credential->relationLoaded('service') ? $credential->service?->only(['id', 'name']) : null,
            'folder' => $credential->relationLoaded('folder') ? $credential->folder?->only(['id', 'name']) : null,
            'reveal_url' => $canReveal && $credential->has_secret ? route($revealRoute, [$client, $credential]) : null,
        ]);
    }

    /**
     * Dernières entrées d'historique d'un élément.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function historyFor(Model $subject, User $user, int $limit = 15): Collection
    {
        return AuditLog::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->with('user:id,name')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log): array => AuditPresenter::present($log, $user));
    }

    /**
     * Valide un nom saisi pour confirmer une suppression définitive.
     */
    protected function confirmDeletion(Request $request, string $expected): void
    {
        $request->validate([
            'confirmation' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($expected): void {
                if (trim((string) $value) !== $expected) {
                    $fail('Le texte de confirmation ne correspond pas.');
                }
            }],
        ]);
    }
}
