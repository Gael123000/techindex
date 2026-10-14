<?php

namespace App\Services\Updates;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Canal officiel des versions : publications GitHub du dépôt configuré
 * (config/release-channel.json). Seul le manifeste signé est lu ici ;
 * l'archive est téléchargée et contrôlée par le script de déploiement.
 */
class ReleaseChannel
{
    private const MAX_MANIFEST_BYTES = 262144;

    public function isConfigured(): bool
    {
        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $this->repository()) === 1
            && strlen((string) base64_decode((string) config('techindex.updates.public_key'), true)) === 32;
    }

    public function repository(): string
    {
        return (string) config('techindex.updates.repository');
    }

    /**
     * Dernière version publiée (les préversions sont ignorées par GitHub).
     */
    public function latest(): Release
    {
        return $this->fetch('releases/latest/download');
    }

    public function find(string $version): Release
    {
        if (preg_match(Release::VERSION_PATTERN, $version) !== 1) {
            throw new UpdateException('Numéro de version invalide.');
        }

        return $this->fetch('releases/download/v'.$version);
    }

    private function fetch(string $path): Release
    {
        if (! $this->isConfigured()) {
            throw new UpdateException('Canal de mise à jour non configuré (dépôt et clé publique dans config/release-channel.json).');
        }

        $url = config('techindex.updates.base_url').'/'.$this->repository().'/'.$path;

        try {
            $manifest = $this->get($url.'/release.json');

            if ($manifest->status() === 404) {
                throw new UpdateException('Aucune version publiée n\'a été trouvée sur '.$this->repository().'.');
            }

            $signature = $this->get($url.'/release.json.sig');
        } catch (ConnectionException) {
            throw new UpdateException('Dépôt des mises à jour injoignable : vérifiez l\'accès sortant du serveur à github.com.');
        }

        if (! $manifest->successful() || ! $signature->successful() || strlen($manifest->body()) > self::MAX_MANIFEST_BYTES) {
            throw new UpdateException('Réponse inattendue du dépôt des mises à jour (code '.$manifest->status().').');
        }

        return Release::fromSignedManifest($manifest->body(), $signature->body(), (string) config('techindex.updates.public_key'));
    }

    private function get(string $url): Response
    {
        return Http::timeout(15)
            ->withUserAgent('TechIndex/'.config('techindex.version'))
            ->get($url);
    }
}
