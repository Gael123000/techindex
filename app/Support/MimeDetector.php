<?php

namespace App\Support;

use finfo;

/**
 * Détection du type MIME à partir du contenu réel du fichier (libmagic),
 * indépendamment du nom et du type annoncé par le navigateur.
 */
final class MimeDetector
{
    public static function detect(string $path): string
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return $detected === false ? 'application/octet-stream' : strtolower($detected);
    }
}
