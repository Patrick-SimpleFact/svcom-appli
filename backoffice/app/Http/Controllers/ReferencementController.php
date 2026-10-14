<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Fichiers lus par les moteurs de recherche (SEO 1). Le site public (domaine principal) est ouvert à l'exploration,
 * sauf les pages techniques ; le sous-domaine du back-office est fermé. Les fiches partagées restent hors index (F5.8, noindex).
 */
class ReferencementController extends Controller
{
    /** Pages techniques du site public : ni contenu utile, ni intérêt pour un moteur de recherche. */
    public const FERMEES = ['/accueil/', '/beta/', '/espace-salle/'];

    public function robots(Request $request): Response
    {
        $site = (string) config('app.url_site');
        $lignes = $request->getHost() === parse_url($site, PHP_URL_HOST)
            ? ['User-agent: *', ...array_map(fn (string $chemin) => "Disallow: {$chemin}", self::FERMEES), '', "Sitemap: {$site}/sitemap.xml"]
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lignes)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Plan du site : l'accueil pour l'instant (les pages ville viendront avec SEO 2). */
    public function plan(): Response
    {
        $site = (string) config('app.url_site');
        $pages = [['adresse' => "{$site}/", 'frequence' => 'daily', 'priorite' => '1.0']];

        return response()->view('referencement.plan', ['pages' => $pages, 'jour' => now('Europe/Paris')->toDateString()], 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
