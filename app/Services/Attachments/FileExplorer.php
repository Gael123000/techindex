<?php

namespace App\Services\Attachments;

use App\Models\Attachment;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\FileFolder;
use App\Models\Folder;
use App\Models\License;
use App\Models\Service;
use App\Models\Site;
use App\Services\Folders\FolderManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Explorateur de fichiers : rangement des pièces jointes d'un client dans une
 * arborescence de dossiers.
 *
 * Deux sortes de fichiers coexistent :
 * - ceux déposés dans l'explorateur, rattachés au client lui-même ;
 * - ceux joints à une fiche (équipement, site, document...), qui arrivent à
 *   la racine du client et peuvent être rangés sans perdre leur lien.
 */
class FileExplorer
{
    /**
     * Éléments auxquels un fichier peut être lié : modèle, colonne du libellé,
     * libellé du type et route de la fiche.
     *
     * @var array<string, array{0: class-string<Model>, 1: string, 2: string, 3: string}>
     */
    private const LINKABLE = [
        'site' => [Site::class, 'name', 'Site', 'clients.sites.show'],
        'equipment' => [Equipment::class, 'name', 'Équipement', 'clients.equipment.show'],
        'service' => [Service::class, 'name', 'Service', 'clients.services.show'],
        'document' => [Document::class, 'title', 'Document', 'clients.documents.show'],
        'license' => [License::class, 'name', 'Licence', 'clients.licenses.show'],
        'certificate' => [Certificate::class, 'name', 'Certificat', 'clients.certificates.show'],
    ];

    public function __construct(
        private readonly AttachmentStorage $storage,
        private readonly FolderManager $folders,
    ) {}

    /**
     * Dossier de destination d'un fichier importé avec son chemin relatif
     * (import d'un dossier entier) : les sous-dossiers manquants sont créés.
     *
     * @throws ValidationException
     */
    public function resolveUploadFolder(Client $client, ?FileFolder $target, ?string $relativePath): ?FileFolder
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return $target;
        }

        $segments = explode('/', str_replace('\\', '/', $relativePath));
        array_pop($segments);
        $segments = array_values(array_filter(array_map('trim', $segments), fn (string $segment): bool => $segment !== ''));

        foreach ($segments as $segment) {
            if (mb_strlen($segment) > 100 || in_array($segment, ['.', '..'], true) || ! preg_match(FolderManager::NAME_PATTERN, $segment)) {
                throw ValidationException::withMessages(['relative_path' => 'Le chemin du fichier contient un nom de dossier invalide.']);
            }
        }

        if (($target?->depth() ?? 0) + count($segments) > Folder::MAX_DEPTH) {
            throw ValidationException::withMessages(['relative_path' => 'Arborescence trop profonde ('.Folder::MAX_DEPTH.' niveaux au maximum).']);
        }

        $folder = $target;

        foreach ($segments as $segment) {
            $folder = $this->folders->findOrCreate(FileFolder::class, $client, $segment, $folder);
        }

        return $folder;
    }

    /**
     * Contenu d'un dossier et de ses sous-dossiers.
     *
     * @return array{folders: int, own: int, linked: int}
     */
    public function summary(FileFolder $folder): array
    {
        $ids = $folder->selfAndDescendantIds();
        $files = Attachment::query()->whereIn('folder_id', $ids)->toBase()
            ->selectRaw("sum(case when attachable_type = 'client' then 1 else 0 end) as own_count")
            ->selectRaw("sum(case when attachable_type <> 'client' then 1 else 0 end) as linked_count")
            ->first();

        return [
            'folders' => count($ids) - 1,
            'own' => (int) ($files->own_count ?? 0),
            'linked' => (int) ($files->linked_count ?? 0),
        ];
    }

    /**
     * Supprime un dossier et ses sous-dossiers.
     *
     * - Les fichiers déposés dans l'explorateur sont supprimés définitivement.
     * - Les fichiers liés à une fiche ne sont pas supprimés : ils reviennent à
     *   la racine du client, la fiche les affiche toujours.
     *
     * @return array{deleted: int, restored: int}
     */
    public function deleteFolder(FileFolder $folder): array
    {
        $paths = [];
        $restored = 0;

        DB::transaction(function () use ($folder, &$paths, &$restored): void {
            $files = Attachment::query()->whereIn('folder_id', $folder->selfAndDescendantIds())->get();

            foreach ($files as $attachment) {
                if ($attachment->attachable_type === 'client') {
                    $paths[] = $attachment->stored_path;
                    $attachment->delete();
                } else {
                    $attachment->folder_id = null;
                    $attachment->save();
                    $restored++;
                }
            }

            $folder->delete();
        });

        // Les fichiers ne sont effacés du disque qu'une fois la base à jour.
        foreach ($paths as $path) {
            $this->storage->disk()->delete($path);
        }

        return ['deleted' => count($paths), 'restored' => $restored];
    }

    /**
     * Élément auquel chaque fichier est lié (null : fichier propre au client).
     *
     * @param  Collection<int, Attachment>  $attachments
     * @return array<int, array{type: string, type_label: string, label: string, url: string}|null>
     */
    public function links(Collection $attachments): array
    {
        $labels = [];

        foreach ($attachments->groupBy('attachable_type') as $type => $items) {
            if (! isset(self::LINKABLE[$type])) {
                continue;
            }

            [$class, $column] = self::LINKABLE[$type];
            $labels[$type] = $class::query()->whereIn('id', $items->pluck('attachable_id')->unique()->all())->pluck($column, 'id')->all();
        }

        $links = [];

        foreach ($attachments as $attachment) {
            $type = $attachment->attachable_type;
            $label = $labels[$type][$attachment->attachable_id] ?? null;

            $links[$attachment->id] = $label === null ? null : [
                'type' => $type,
                'type_label' => self::LINKABLE[$type][2],
                'label' => (string) $label,
                'url' => route(self::LINKABLE[$type][3], [$attachment->client_id, $attachment->attachable_id]),
            ];
        }

        return $links;
    }
}
