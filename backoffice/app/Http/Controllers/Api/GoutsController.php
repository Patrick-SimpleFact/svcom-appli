<?php

namespace App\Http\Controllers\Api;

use App\Comptes\Gouts;
use App\Http\Controllers\Controller;
use App\Models\Suivi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Préférences, favoris, suivis et nouveautés (API §8, F3) : compte requis.
 */
class GoutsController extends Controller
{
    public function preferences(Request $request, Gouts $gouts): JsonResponse
    {
        return $this->json($gouts->preferences($request->user()));
    }

    public function modifierPreferences(Request $request, Gouts $gouts): JsonResponse
    {
        $d = $request->validate([
            'genres' => ['sometimes', 'array'], 'genres.*' => ['integer'],
            'rayon_m' => ['sometimes', 'nullable', 'in:'.implode(',', AutourController::RAYONS_FIXES)],
            'zone_alertes' => ['sometimes', 'array'],
            'zone_alertes.ville_id' => ['nullable', 'integer', 'exists:villes,id'],
            'zone_alertes.lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:zone_alertes.lon'],
            'zone_alertes.lon' => ['nullable', 'numeric', 'between:-180,180', 'required_with:zone_alertes.lat'],
            'zone_alertes.rayon_km' => ['nullable', 'integer', 'between:10,100'],
            'alertes_actives' => ['sometimes', 'boolean'],
            'rappel_jour_j' => ['sometimes', 'boolean'],
        ]);

        return $this->json($gouts->modifierPreferences($request->user(), $d));
    }

    public function favoris(Request $request, Gouts $gouts): JsonResponse
    {
        return $this->json($gouts->favoris($request->user()));
    }

    public function ajouterFavori(Request $request, Gouts $gouts): JsonResponse
    {
        $d = $request->validate(['spectacle_id' => ['required', 'integer'], 'representation_id' => ['nullable', 'integer']]);
        $favori = $gouts->ajouterFavori($request->user(), (int) $d['spectacle_id'], isset($d['representation_id']) ? (int) $d['representation_id'] : null);

        return $this->json(['id' => $favori->id, 'spectacle_id' => $favori->spectacle_id, 'representation_id' => $favori->representation_id], $favori->wasRecentlyCreated ? 201 : 200);
    }

    public function retirerFavori(Request $request, int $id): JsonResponse
    {
        $request->user()->favoris()->whereKey($id)->firstOrFail()->delete();

        return response()->json(null, 204);
    }

    public function suivis(Request $request, Gouts $gouts): JsonResponse
    {
        return $this->json(['suivis' => $gouts->suivis($request->user())]);
    }

    public function suivre(Request $request, Gouts $gouts): JsonResponse
    {
        $d = $request->validate(['type' => ['required', 'in:'.implode(',', Suivi::TYPES)], 'id' => ['required', 'integer']]);
        $resultat = $gouts->suivre($request->user(), $d['type'], (int) $d['id']);

        return $this->json($resultat, $resultat['deja_suivi'] ? 200 : 201);
    }

    public function nePlusSuivre(Request $request, int $id): JsonResponse
    {
        $request->user()->suivis()->whereKey($id)->firstOrFail()->delete();

        return response()->json(null, 204);
    }

    public function nouveautes(Request $request, Gouts $gouts): JsonResponse
    {
        return $this->json($gouts->nouveautes($request->user()));
    }

    public function marquerVues(Request $request, Gouts $gouts): JsonResponse
    {
        $d = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer']]);

        return $this->json(['vues' => $gouts->marquerVues($request->user(), $d['ids'] ?? null)]);
    }

    private function json(array $donnees, int $statut = 200): JsonResponse
    {
        return response()->json($donnees, $statut, options: JSON_UNESCAPED_UNICODE);
    }
}
