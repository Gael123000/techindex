<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité appliqués à toutes les réponses web : politique de
 * sécurité du contenu (scripts limités à l'application et au nonce de la
 * requête), protection contre l'intégration en iframe, HSTS facultatif.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (config('techindex.security.csp_enabled') && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        if (config('techindex.security.hsts_enabled') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $nonce = Vite::cspNonce();
        $scripts = ["'self'", "'nonce-{$nonce}'"];
        $styles = ["'self'", "'unsafe-inline'"];
        $connect = ["'self'"];
        $images = ["'self'", 'data:', 'blob:'];

        // Serveur de développement Vite (rechargement à chaud).
        if (Vite::isRunningHot()) {
            $hot = rtrim((string) file_get_contents(Vite::hotFile()));
            $scripts[] = $hot;
            $styles[] = $hot;
            $images[] = $hot;
            $connect[] = $hot;
            $connect[] = (string) preg_replace('#^http#', 'ws', $hot);
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $scripts),
            'style-src '.implode(' ', $styles),
            'img-src '.implode(' ', $images),
            "font-src 'self' data:",
            'connect-src '.implode(' ', $connect),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "frame-src 'none'",
            "worker-src 'self' blob:",
        ]);
    }
}
