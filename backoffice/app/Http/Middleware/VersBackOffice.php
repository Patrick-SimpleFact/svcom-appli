<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages réservées au sous-domaine du back-office (espace salle : connexion, demande d'espace).
 * Sur le domaine principal, une page de connexion d'un domaine tout neuf a été prise pour de l'hameçonnage
 * par Google Safe Browsing (14/10/2026) : le domaine principal ne garde que la vitrine et la liste d'attente.
 */
class VersBackOffice
{
    public function handle(Request $request, Closure $suite): Response
    {
        $backOffice = rtrim((string) config('app.url'), '/');
        $hote = parse_url($backOffice, PHP_URL_HOST);
        if ($request->isMethod('GET') && $request->getHost() !== $hote && $request->getHost() === parse_url((string) config('app.url_site'), PHP_URL_HOST)) {
            return redirect()->away($backOffice.$request->getRequestUri(), 301);
        }

        return $suite($request);
    }
}
