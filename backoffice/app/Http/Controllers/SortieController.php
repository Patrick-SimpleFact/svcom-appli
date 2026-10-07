<?php

namespace App\Http\Controllers;

use App\Actions\EnregistrerClic;
use App\Models\Offre;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sortie vers la billetterie (API §6) : enregistre le clic puis redirige tout de suite vers le lien de l'offre (affilié si possible).
 * Si l'enregistrement échoue, l'utilisateur est quand même redirigé : on ne lui fait jamais perdre son billet.
 */
class SortieController extends Controller
{
    public function __invoke(int $offre, EnregistrerClic $enregistrer): RedirectResponse|Response
    {
        $offre = Offre::with(['representation', 'source'])->find($offre);

        if ($offre === null || blank($offre->lien)) {
            return response('Ce lien de réservation n’existe plus.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        try {
            $enregistrer->handle($offre, request());
        } catch (Throwable $erreur) {
            Log::error('Clic sortant non enregistré', ['offre' => $offre->id, 'erreur' => $erreur->getMessage()]);
        }

        // 302 sans cache : chaque clic repasse par ici et peut être compté.
        return redirect()->away($offre->lien, 302, ['Cache-Control' => 'no-store']);
    }
}
