<?php

namespace App\Services\Documents;

use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Services\Records\RecordExtensions;
use App\Services\Tags\TagResolver;
use Illuminate\Support\Facades\DB;

/**
 * Enregistrement d'une fiche : nettoyage du HTML, texte de recherche,
 * création d'une version à chaque changement de titre ou de contenu.
 */
class DocumentService
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly TagResolver $tags,
        private readonly RecordExtensions $extensions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Document $document, Client $client, array $data, User $user): Document
    {
        return DB::transaction(function () use ($document, $client, $data, $user): Document {
            $isNew = ! $document->exists;
            $html = $this->sanitizer->sanitize($data['content'] ?? null);
            $contentChanged = $isNew || $html !== (string) $document->content_html || $data['title'] !== $document->title;

            $document->fill([
                'title' => $data['title'],
                'category_id' => $data['category_id'] ?? null,
                'status' => $data['status'],
                'owner_id' => $data['owner_id'] ?? null,
                'review_interval_days' => $data['review_interval_days'] ?? null,
            ]);

            $document->forceFill([
                'content_html' => $html,
                'content_text' => $this->sanitizer->toPlainText($html),
            ]);

            if ($isNew) {
                $document->client()->associate($client);
                $document->current_version = 1;
            } elseif ($contentChanged) {
                $document->current_version = $document->current_version + 1;
                $document->addAuditChange('content', 'version '.($document->current_version - 1), 'version '.$document->current_version);
            }

            // Pour une modification, les liens sont synchronisés avant la
            // sauvegarde afin que l'historique regroupe tous les changements.
            if (! $isNew) {
                $this->syncLinks($document, $data, false);
            }

            $document->save();

            if ($contentChanged) {
                $document->versions()->create([
                    'version' => $document->current_version,
                    'title' => $document->title,
                    'content_html' => $html,
                    'change_summary' => $data['change_summary'] ?? ($isNew ? 'Création' : null),
                    'created_by' => $user->id,
                ]);
            }

            if ($isNew) {
                $this->syncLinks($document, $data, true);
            } else {
                $document->flushPendingAudit();
            }

            return $document;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncLinks(Document $document, array $data, bool $isNew): void
    {
        $beforeEquipment = $isNew ? null : $document->equipment()->pluck('name')->sort()->implode(', ');
        $beforeServices = $isNew ? null : $document->services()->pluck('name')->sort()->implode(', ');
        $beforeLinks = $isNew ? null : $document->internetLinks()->pluck('name')->sort()->implode(', ');

        $document->equipment()->sync($data['equipment_ids'] ?? []);
        $document->services()->sync($data['service_ids'] ?? []);
        // Champs ajoutés avec le constructeur de fiches.
        $this->extensions->save('document', $document, (int) $document->client_id, $data, $isNew);

        // Champ absent (ancien formulaire, import) : liens Internet inchangés.
        if (array_key_exists('internet_link_ids', $data)) {
            $document->internetLinks()->sync($data['internet_link_ids'] ?? []);
        }

        if ($isNew) {
            $document->tags()->sync($this->tags->idsFor($data['tags'] ?? []));

            return;
        }

        $document->syncTagsWithAudit($this->tags->idsFor($data['tags'] ?? []));
        $document->addAuditChange('equipment', $beforeEquipment ?: null, $document->equipment()->pluck('name')->sort()->implode(', ') ?: null);
        $document->addAuditChange('services', $beforeServices ?: null, $document->services()->pluck('name')->sort()->implode(', ') ?: null);
        $document->addAuditChange('internet_links', $beforeLinks ?: null, $document->internetLinks()->pluck('name')->sort()->implode(', ') ?: null);
    }
}
