<?php

namespace App\Services\Attachments;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\FileFolder;
use App\Models\User;
use App\Support\MimeDetector;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stockage des pièces jointes sur un disque privé.
 *
 * - Nom de stockage généré (UUID), sans extension ni lien avec le nom d'origine.
 * - Type MIME déterminé par analyse du contenu, pas par le navigateur.
 * - Téléchargement uniquement via un contrôleur, après contrôle des droits.
 */
class AttachmentStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk(config('techindex.attachments.disk'));
    }

    /**
     * Enregistre un fichier rattaché à un élément du client. Sans dossier, il
     * apparaît à la racine du client dans l'explorateur de fichiers.
     */
    public function store(UploadedFile $file, Client $client, Model $attachable, User $user, ?string $description = null, ?FileFolder $folder = null): Attachment
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = sprintf('attachments/%d/%s', $client->id, Str::uuid());
        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false || ! $this->disk()->writeStream($path, $stream)) {
            throw new RuntimeException('Le fichier n\'a pas pu être enregistré.');
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        $attachment = new Attachment([
            'original_name' => $this->safeName($file->getClientOriginalName(), $extension),
            'description' => $description,
        ]);

        $attachment->forceFill([
            'client_id' => $client->id,
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'folder_id' => $folder?->id,
            'stored_path' => $path,
            'mime_type' => MimeDetector::detect($file->getRealPath()),
            'extension' => $extension,
            'size' => (int) $file->getSize(),
            'checksum_sha256' => (string) hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $user->id,
        ])->save();

        return $attachment;
    }

    public function delete(Attachment $attachment): void
    {
        $this->disk()->delete($attachment->stored_path);
        $attachment->delete();
    }

    /**
     * Supprime tous les fichiers d'un client (avant suppression définitive).
     */
    public function deleteClientFiles(Client $client): void
    {
        $this->disk()->deleteDirectory('attachments/'.$client->id);

        if ($client->logo_path !== null) {
            $this->disk()->delete($client->logo_path);
        }
    }

    public function response(Attachment $attachment, bool $inline): StreamedResponse
    {
        if (! $this->disk()->exists($attachment->stored_path)) {
            abort(404);
        }

        $inline = $inline && $attachment->isInlineViewable();
        $disposition = HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $attachment->original_name,
            Str::ascii($attachment->original_name) ?: 'fichier.'.$attachment->extension,
        );

        return response()->stream(function () use ($attachment): void {
            $stream = $this->disk()->readStream($attachment->stored_path);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            // Les fichiers non affichables sont servis comme flux binaire.
            'Content-Type' => $inline ? $attachment->mime_type : 'application/octet-stream',
            'Content-Disposition' => $disposition,
            'Content-Length' => (string) $attachment->size,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; frame-ancestors 'none'",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Renomme un fichier sans changer son extension : l'extension a été
     * contrôlée avec le contenu réel du fichier lors de l'envoi.
     */
    public function rename(Attachment $attachment, string $baseName): void
    {
        $attachment->original_name = $this->safeName($baseName.'.'.$attachment->extension, $attachment->extension);
        $attachment->save();
    }

    private function safeName(string $name, string $extension): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', $base);
        $base = trim(mb_substr($base, 0, 150), " .\t") ?: 'fichier';

        return $base.'.'.$extension;
    }
}
