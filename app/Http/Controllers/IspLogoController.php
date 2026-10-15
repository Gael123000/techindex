<?php

namespace App\Http\Controllers;

use App\Models\Isp;
use App\Services\Attachments\AttachmentStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logo d'un opérateur du catalogue (commun à tous les clients).
 *
 * Les logos fournis (SVG, PNG ou JPEG) sont servis avec une politique de sécurité
 * qui interdit tout script : un SVG ne peut rien exécuter, même ouvert seul.
 */
class IspLogoController extends Controller
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => 'private, max-age=604800',
        'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox",
    ];

    public function __invoke(Isp $isp, AttachmentStorage $storage): Response
    {
        $disk = $storage->disk();

        if ($isp->logo_path !== null && $disk->exists($isp->logo_path)) {
            $mime = $disk->mimeType($isp->logo_path);
            abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 404);

            return $disk->response($isp->logo_path, null, ['Content-Type' => $mime, ...self::HEADERS], 'inline');
        }

        $path = $isp->bundledLogoPath();
        abort_if($path === null, 404);

        return new BinaryFileResponse($path, 200, [
            'Content-Type' => match (pathinfo($path, PATHINFO_EXTENSION)) {
                'svg' => 'image/svg+xml',
                'jpg' => 'image/jpeg',
                default => 'image/png',
            },
            ...self::HEADERS,
        ], true, 'inline');
    }
}
