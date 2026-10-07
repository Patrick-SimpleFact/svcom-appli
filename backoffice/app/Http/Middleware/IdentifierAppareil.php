<?php

namespace App\Http\Middleware;

use App\Exceptions\ErreurApi;
use App\Models\Parametre;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Règles communes de l'API (API §1) : chaque appel dit quel appareil l'envoie (X-Appareil, généré par l'app à l'installation)
 * et avec quelle version de l'app (X-App-Version) ; une version plus ancienne que la version minimale du BO reçoit 426 (F2.8, F7.12).
 */
class IdentifierAppareil
{
    public function handle(Request $request, Closure $suite): Response
    {
        $appareil = (string) $request->header('X-Appareil');
        $version = (string) $request->header('X-App-Version');

        if (! preg_match('/^[A-Za-z0-9-]{8,100}$/', $appareil)) {
            throw new ErreurApi('appareil_manquant', 'En-tête X-Appareil absent ou invalide.');
        }

        if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new ErreurApi('version_manquante', 'En-tête X-App-Version absent ou invalide (ex. 1.0.0).');
        }

        $minimale = (string) Parametre::valeur('version_minimale_app');

        if (version_compare($version, $minimale, '<')) {
            throw new ErreurApi('version_obsolete', "Version {$version} trop ancienne (minimum {$minimale}).", 426);
        }

        $request->attributes->set('appareil', $appareil);
        $request->attributes->set('version_app', $version);

        return $suite($request);
    }
}
