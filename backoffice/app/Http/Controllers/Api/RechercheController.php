<?php

namespace App\Http\Controllers\Api;

use App\Api\AutourDeMoi;
use App\Api\Propositions;
use App\Api\Recherche;
use App\Api\TexteCherche;
use App\Http\Controllers\Controller;
use App\Models\Ville;
use App\Support\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recherche (API §4, F4) : propositions pendant la frappe, puis résultats complets avec filtres.
 */
class RechercheController extends Controller
{
    public function propositions(Request $request, Propositions $propositions): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'max:100']]);
        $texte = TexteCherche::preparer($request->string('q'));

        return response()->json($texte === null
            ? ['spectacles' => [], 'artistes' => [], 'lieux' => [], 'villes' => []]
            : $propositions->handle($texte), options: JSON_UNESCAPED_UNICODE);
    }

    public function rechercher(Request $request, Recherche $recherche): JsonResponse
    {
        $d = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'du' => ['nullable', 'date_format:Y-m-d'],
            'au' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:du'],
            'ville_id' => ['nullable', 'integer'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lon'],
            'lon' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'rayon' => ['nullable', 'in:'.implode(',', AutourController::RAYONS_FIXES)],
            'genres' => ['nullable', 'array'], 'genres.*' => ['integer'],
            'jeune_public' => ['nullable', 'boolean'],
            'moment' => ['nullable', 'in:'.implode(',', array_keys(Recherche::MOMENTS))],
            'prix_max' => ['nullable', 'numeric', 'min:0'],
            'gratuit' => ['nullable', 'boolean'],
            'masquer_complets' => ['nullable', 'boolean'],
            'tri' => ['nullable', 'in:date,distance,prix'],
            'suivant' => ['nullable', 'string', 'max:200'],
        ]);

        // Où : une position (avec un rayon, 10 km par défaut) ou une ville (son centre et le même rayon). Sans rien : toute la France.
        $centre = isset($d['lat']) ? new Point((float) $d['lat'], (float) $d['lon']) : null;
        if ($centre === null && isset($d['ville_id'])) {
            $centre = Ville::find($d['ville_id'])?->position;
        }

        return response()->json($recherche->handle([
            'texte' => $d['q'] ?? null,
            'du' => $d['du'] ?? null,
            'au' => $d['au'] ?? null,
            'centre' => $centre,
            'rayon' => $centre ? (int) ($d['rayon'] ?? 10000) : null,
            'ville_id' => $d['ville_id'] ?? null,
            'genres' => array_map('intval', $d['genres'] ?? []),
            'jeune_public' => (bool) ($d['jeune_public'] ?? false),
            'moment' => $d['moment'] ?? null,
            'prix_max' => ($d['gratuit'] ?? false) ? 0 : ($d['prix_max'] ?? null),
            'masquer_complets' => (bool) ($d['masquer_complets'] ?? false),
            'tri' => $d['tri'] ?? 'date',
            'page' => AutourDeMoi::pageDuCurseur($d['suivant'] ?? null),
        ]), options: JSON_UNESCAPED_UNICODE);
    }
}
