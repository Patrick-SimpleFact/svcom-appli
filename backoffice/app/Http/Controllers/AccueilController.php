<?php

namespace App\Http\Controllers;

use App\Api\AutourDeMoi;
use App\Api\Fiches;
use App\Models\Genre;
use App\Models\Ville;
use App\Web\VilleDuVisiteur;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Page d'accueil du site (W05a) : présentation de l'app, vrais spectacles de ce soir près du visiteur
 * (sa position s'il la donne, sinon sa ville estimée par l'adresse IP, sinon Paris), villes couvertes, bloc théâtres.
 */
class AccueilController extends Controller
{
    public const CARTES = 5;

    /** Une ville pilote n'est montrée que si elle a assez de spectacles pour donner envie (page publique). */
    public const VILLE_MINIMUM = 50;

    public const VILLES_MAX = 4;

    public function accueil(Request $requete, VilleDuVisiteur $visiteur, AutourDeMoi $autour): View
    {
        $lieu = $visiteur->trouver($requete);

        return view('web.accueil', [
            'apercu' => $lieu ? $this->apercu($lieu, $autour) : null,
            'villes' => $this->villesPilotes(),
            'app' => config('app_mobile'),
        ]);
    }

    /** Bouton « Me localiser » : l'aperçu recalculé autour de la position donnée par le navigateur (jamais enregistrée). */
    public function apercuPosition(Request $requete, VilleDuVisiteur $visiteur, AutourDeMoi $autour): View
    {
        $lieu = $visiteur->trouver($requete) ?? abort(404);

        return view('web.accueil-apercu', ['apercu' => $this->apercu($lieu, $autour)]);
    }

    /** Ce soir (à défaut demain, puis ce week-end) autour du visiteur : quelques cartes et le total. */
    private function apercu(array $lieu, AutourDeMoi $autour): array
    {
        foreach (['ce_soir' => 'Ce soir', 'demain' => 'Demain', 'week_end' => 'Ce week-end'] as $quand => $libelle) {
            $resultat = $autour->handle($lieu['centre'], $quand);

            if ($resultat['total'] > 0) {
                break;
            }
        }

        $genres = Genre::pluck('libelle', 'id');
        $autres = Genre::where('slug', 'autres')->value('id');

        return [
            'ville' => $lieu['ville']->nom,
            'origine' => $lieu['origine'],
            'quand' => $libelle,
            'total' => $resultat['total'],
            'rayon_km' => (int) round($resultat['rayon_retenu_m'] / 1000),
            // Les vrais genres de spectacle d'abord (« Autres » mêle soirées DJ et événements mal classés), dans l'ordre des heures.
            'cartes' => collect($resultat['cartes'])->sortBy(fn (array $c, int $i) => [$c['spectacle']['genre_id'] === $autres || $c['spectacle']['genre_id'] === null ? 1 : 0, $i])
                ->take(self::CARTES)->sortBy(fn (array $c) => $c['seances'][0]['debut'])->values()->map(fn (array $c) => [
                    'heure' => CarbonImmutable::parse($c['seances'][0]['debut'])->format('G\hi'),
                    'plus' => count($c['seances']) > 1 ? '+ '.CarbonImmutable::parse($c['seances'][1]['debut'])->format('G\hi') : '',
                    'genre' => $genres[$c['spectacle']['genre_id']] ?? 'Spectacle',
                    'titre' => $c['spectacle']['titre'],
                    'lieu' => $c['lieu']['nom'],
                    'distance' => $c['distance_m'] < 1000 ? $c['distance_m'].' m' : str_replace('.', ',', (string) round($c['distance_m'] / 1000, 1)).' km',
                    'complet' => in_array('complet', $c['badges'], true),
                    'lien' => Fiches::lienPartageDe($c['spectacle']['id'], $c['spectacle']['titre'], $c['seances'][0]['representation_id']),
                ])->all(),
        ];
    }

    /** Dernière mesure du tableau de couverture (A05) pour chaque ville pilote. */
    private function villesPilotes(): array
    {
        return Ville::where('est_pilote', true)
            ->joinSub(DB::table('couvertures')->selectRaw('distinct on (ville_id) ville_id, ce_soir, trente_jours')->orderBy('ville_id')->orderByDesc('jour'), 'c', 'c.ville_id', '=', 'villes.id')
            ->where('c.trente_jours', '>=', self::VILLE_MINIMUM)
            ->orderByDesc('c.trente_jours')->limit(self::VILLES_MAX)
            ->get(['villes.nom', 'c.ce_soir', 'c.trente_jours'])
            ->map(fn ($v) => ['nom' => $v->nom, 'ce_soir' => (int) $v->ce_soir, 'trente_jours' => (int) $v->trente_jours])->all();
    }
}
