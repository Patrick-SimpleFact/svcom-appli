<?php

namespace App\Http\Controllers;

use App\Api\Fiches;
use App\Exceptions\ErreurApi;
use App\Models\Spectacle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Lien partagé (F5.8, API §12) : si l'app est installée, le téléphone l'ouvre directement sur la fiche
 * (liens universels Apple, App Links Android) ; sinon cette page web minimale : une seule fiche, bouton de réservation,
 * boutons des boutiques. Pas une version web de l'app : ni recherche, ni navigation, pas d'indexation.
 */
class PartageController extends Controller
{
    public function fiche(Request $request, string $lien, Fiches $fiches): Response|RedirectResponse
    {
        $id = preg_match('/(\d+)$/', $lien, $m) ? (int) $m[1] : 0;
        $r = ctype_digit((string) $request->query('r')) ? (int) $request->query('r') : null;

        try {
            $fiche = $fiches->spectacle($id, $r);
        } catch (ErreurApi) {
            return response()->view('partage.introuvable', ['app' => config('app_mobile')], 404);
        }

        // Titre changé ou lien tronqué : on redirige vers l'adresse exacte.
        $exacte = Fiches::lienPartage(Spectacle::find($id), $r);
        if ($request->path() !== ltrim((string) parse_url($exacte, PHP_URL_PATH), '/')) {
            return redirect()->away($exacte, 301);
        }

        return response()->view('partage.fiche', ['f' => $fiche, 'app' => config('app_mobile'), 'adresse' => $exacte]);
    }

    /** iOS : quelles adresses ouvrent l'app (liens universels). */
    public function apple(): JsonResponse
    {
        $c = config('app_mobile');
        abort_if(blank($c['ios_equipe_id']) || blank($c['ios_bundle_id']), 404);

        return response()->json(['applinks' => ['details' => [[
            'appIDs' => ["{$c['ios_equipe_id']}.{$c['ios_bundle_id']}"],
            'components' => [['/' => '/s/*', 'comment' => 'Liens partagés vers une fiche']],
        ]]]], options: JSON_UNESCAPED_SLASHES);
    }

    /** Android : l'app autorisée à ouvrir nos liens (App Links). */
    public function android(): JsonResponse
    {
        $c = config('app_mobile');
        abort_if(blank($c['android_paquet']) || blank($c['android_empreintes']), 404);

        return response()->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => $c['android_paquet'], 'sha256_cert_fingerprints' => array_map('trim', explode(',', $c['android_empreintes']))],
        ]], options: JSON_UNESCAPED_SLASHES);
    }
}
