<?php

namespace App\Http\Controllers\Api;

use App\Api\Suggestions;
use App\Enums\ChoixSuggestion;
use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Models\AffichageSuggestion;
use App\Models\Appareil;
use App\Models\Utilisateur;
use App\Support\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Suggestion à l'ouverture (API §9, F6) : avec ou sans compte. La position sert au choix, elle n'est jamais enregistrée (F2.11).
 */
class SuggestionController extends Controller
{
    /** La suggestion du jour, ou 204 (déjà vue aujourd'hui, pas acceptée, interrupteur coupé, rien de proche). */
    public function suggestion(Request $request, Suggestions $suggestions): JsonResponse|Response
    {
        $d = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
            'gouts' => ['nullable', 'array'], 'gouts.*' => ['integer'],
        ]);
        $u = auth('sanctum')->user();

        $suggestion = $suggestions->handle(
            $request->attributes->get('appareil'),
            new Point((float) $d['lat'], (float) $d['lon']),
            array_values(array_unique(array_map('intval', $d['gouts'] ?? []))),
            $u instanceof Utilisateur ? $u : null,
        );

        return $suggestion === null ? response()->noContent() : response()->json($suggestion, options: JSON_UNESCAPED_UNICODE);
    }

    /** F6.5 : « Passer » ou « Voir le spectacle ». */
    public function action(Request $request, int $affichage): Response
    {
        $d = $request->validate(['action' => ['required', 'in:passee,fiche']]);
        $affichage = AffichageSuggestion::where('appareil', $request->attributes->get('appareil'))->findOrFail($affichage);
        $affichage->update($d['action'] === 'passee' ? ['passee' => true] : ['clic_fiche' => true]);

        return response()->noContent();
    }

    /** F6.2 : réponse à la question, lien « Ne plus me proposer » ou réglage du Profil. */
    public function choix(Request $request): JsonResponse
    {
        $d = $request->validate(['choix' => ['required', 'in:oui,non_merci,desactive']]);
        $appareil = Appareil::firstWhere('identifiant', $request->attributes->get('appareil'))
            ?? throw new ErreurApi('appareil_inconnu', 'Appareil inconnu : appeler d’abord POST /v1/appareils.', 404);

        $appareil->update([
            'suggestion_choix' => ChoixSuggestion::from($d['choix']),
            'suggestion_repondu_le' => now(),
            'suggestion_ouvertures_a_la_reponse' => $appareil->nb_ouvertures,
        ]);

        return response()->json(['choix' => $appareil->suggestion_choix->value]);
    }
}
