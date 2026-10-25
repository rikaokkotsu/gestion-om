<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité de toutes les réponses. La politique de contenu
 * n'autorise que les fichiers servis par l'application elle-même : ni script
 * en ligne, ni ressource externe, ni affichage dans un cadre d'un autre site.
 */
class SecurityHeaders
{
    private const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; "
        ."font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // Le serveur de développement Vite sert ses scripts depuis un autre port : pas de politique de contenu en développement.
        if (! Vite::isRunningHot()) {
            $headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        }

        // HSTS seulement sur une requête déjà en https : le navigateur refusera ensuite le http pendant un an.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // PHP ajoute lui-même cet en-tête (expose_php) : on le retire aussi à sa source.
        $headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
