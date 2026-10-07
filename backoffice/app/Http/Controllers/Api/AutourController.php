<?php

namespace App\Http\Controllers\Api;

use App\Api\AutourDeMoi;
use App\Api\Fenetre;
use App\Enums\PrecisionPosition;
use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Models\Representation;
use App\Models\Ville;
use App\Support\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Autour de moi » (API §3) : la liste (F2.5) et la carte (F2.6). La position n'est jamais enregistrée (F2.11).
 */
class AutourController extends Controller
{
    /** Rayons qu'on peut fixer dans les filtres (F2.4), en mètres. */
    public const RAYONS_FIXES = [1000, 2000, 5000, 10000, 25000, 50000];

    public function representations(Request $request, AutourDeMoi $autour): JsonResponse
    {
        $donnees = $this->valider($request, [
            'rayon' => ['nullable', 'in:auto,'.implode(',', self::RAYONS_FIXES)],
            'tri' => ['nullable', 'in:heure,distance'],
            'suivant' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json($autour->handle($this->centre($donnees), $donnees['quand'] ?? 'ce_soir', [
            'genres' => $this->genres($donnees),
            'jeune_public' => (bool) ($donnees['jeune_public'] ?? false),
            'rayon' => in_array($donnees['rayon'] ?? 'auto', ['auto', null], true) ? null : (int) $donnees['rayon'],
            'tri' => $donnees['tri'] ?? 'heure',
            'page' => AutourDeMoi::pageDuCurseur($donnees['suivant'] ?? null),
        ]), options: JSON_UNESCAPED_UNICODE);
    }

    /** F2.6 : un repère par lieu dans la zone visible, avec son nombre de spectacles ; lieux à position approximative exclus. */
    public function carte(Request $request): JsonResponse
    {
        $donnees = $this->valider($request, [
            'sud' => ['required', 'numeric', 'between:-90,90'],
            'nord' => ['required', 'numeric', 'between:-90,90', 'gte:sud'],
            'ouest' => ['required', 'numeric', 'between:-180,180'],
            'est' => ['required', 'numeric', 'between:-180,180'],
        ], positionObligatoire: false);

        $centre = new Point(((float) $donnees['sud'] + (float) $donnees['nord']) / 2, ((float) $donnees['ouest'] + (float) $donnees['est']) / 2);
        $fenetre = Fenetre::pour($donnees['quand'] ?? 'ce_soir', AutourDeMoi::fuseau($centre));
        $genres = $this->genres($donnees);

        $requete = Representation::query()->visibles()
            ->where('vis_lieu.precision_position', '!=', PrecisionPosition::Commune->value)
            ->whereRaw('ST_Intersects(representations.position, ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography)', [$donnees['ouest'], $donnees['sud'], $donnees['est'], $donnees['nord']])
            ->when($genres !== [], fn ($q) => $q->whereIn('representations.genre_id', $genres))
            ->when((bool) ($donnees['jeune_public'] ?? false), fn ($q) => $q->where('vis_spectacle.jeune_public', true));
        $fenetre->appliquer($requete);

        $reperes = $requete->toBase()
            ->groupBy('vis_lieu.id', 'vis_lieu.nom', 'vis_lieu.position')
            ->selectRaw('vis_lieu.id as lieu_id, vis_lieu.nom, ST_Y(vis_lieu.position::geometry) as lat, ST_X(vis_lieu.position::geometry) as lon, count(distinct representations.spectacle_id) as nombre')
            ->orderByDesc('nombre')
            ->limit(1000)
            ->get()
            ->map(fn ($r) => ['lieu_id' => $r->lieu_id, 'nom' => $r->nom, 'lat' => round((float) $r->lat, 6), 'lon' => round((float) $r->lon, 6), 'nombre' => (int) $r->nombre]);

        return response()->json(['reperes' => $reperes], options: JSON_UNESCAPED_UNICODE);
    }

    /** Filtres communs (API §3) : position ou ville, quand, genres, jeune public. */
    private function valider(Request $request, array $regles, bool $positionObligatoire = true): array
    {
        $commun = [
            'quand' => ['nullable', 'string', 'max:10'],
            'genres' => ['nullable', 'array'], 'genres.*' => ['integer'],
            'pour_vous' => ['nullable', 'boolean'],
            'gouts' => ['nullable', 'array'], 'gouts.*' => ['integer'],
            'jeune_public' => ['nullable', 'boolean'],
        ];

        if ($positionObligatoire) {
            $commun += [
                'ville_id' => ['nullable', 'integer', 'required_without:lat'],
                'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_without:ville_id', 'required_with:lon'],
                'lon' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            ];
        }

        return $request->validate([...$commun, ...$regles]);
    }

    private function centre(array $donnees): Point
    {
        if (isset($donnees['lat'])) {
            return new Point((float) $donnees['lat'], (float) $donnees['lon']);
        }

        $ville = Ville::find($donnees['ville_id']) ?? throw new ErreurApi('ville_inconnue', 'Ville inconnue.', 404);

        return $ville->position;
    }

    /** « Pour vous » sans compte : les goûts gardés sur le téléphone tiennent lieu de genres (API §13, point 2). */
    private function genres(array $donnees): array
    {
        $genres = ($donnees['pour_vous'] ?? false) ? ($donnees['gouts'] ?? []) : ($donnees['genres'] ?? []);

        return array_values(array_unique(array_map('intval', $genres)));
    }
}
