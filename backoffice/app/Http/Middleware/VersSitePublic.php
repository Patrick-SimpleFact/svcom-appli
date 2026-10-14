<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une page publique n'a qu'une adresse pour les moteurs de recherche : servie sur un autre domaine que celui du site
 * (le sous-domaine du back-office), elle redirige en permanence vers le domaine principal, chemin et paramètres gardés.
 */
class VersSitePublic
{
    public function handle(Request $request, Closure $suite): Response
    {
        $site = (string) config('app.url_site');
        if ($request->isMethod('GET') && $request->getHost() !== parse_url($site, PHP_URL_HOST)) {
            return redirect()->away($site.$request->getRequestUri(), 301);
        }

        return $suite($request);
    }
}
