<?php

namespace App\Rules;

use App\Support\MimeDetector;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Vérifie qu'un fichier a une extension autorisée ET que son contenu réel
 * (type MIME détecté par libmagic) correspond à cette extension. Un
 * exécutable renommé en .pdf est donc refusé.
 */
class AllowedAttachment implements ValidationRule
{
    /**
     * @param  array<string, list<string>>|null  $types
     */
    public function __construct(private ?array $types = null)
    {
        $this->types ??= config('techindex.attachments.types', []);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Le fichier n\'a pas pu être téléversé.');

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());

        if (! array_key_exists($extension, $this->types)) {
            $fail('Ce type de fichier n\'est pas autorisé. Extensions acceptées : '.implode(', ', array_keys($this->types)).'.');

            return;
        }

        $detected = MimeDetector::detect($value->getRealPath());

        if (! in_array($detected, array_map('strtolower', $this->types[$extension]), true)) {
            $fail("Le contenu du fichier ne correspond pas à l'extension .{$extension}.");
        }
    }

    /**
     * Liste "accept" pour le champ de fichier de l'interface.
     */
    public static function acceptAttribute(): string
    {
        return implode(',', array_map(fn (string $ext): string => '.'.$ext, array_keys(config('techindex.attachments.types', []))));
    }
}
