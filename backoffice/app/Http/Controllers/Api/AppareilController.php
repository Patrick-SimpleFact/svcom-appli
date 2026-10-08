<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChoixSuggestion;
use App\Http\Controllers\Controller;
use App\Models\Appareil;
use App\Models\Genre;
use App\Models\MessageService;
use App\Models\Parametre;
use App\Models\Ville;
use App\Support\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Démarrage de l'app (API §2) : enregistre l'ouverture de l'appareil et renvoie la configuration du moment,
 * pour que l'app n'ait rien de figé (paramètres, genres, messages de service, question des suggestions).
 */
class AppareilController extends Controller
{
    /** Paramètres du BO transmis à l'app (F7.12). */
    public const PARAMETRES = ['rayon_auto_min_representations', 'seuil_couverture_faible', 'delais_messages_s', 'paliers_prix', 'pistes_max_par_jour'];

    /** Motifs d'un signalement (F5.6). */
    public const MOTIFS_SIGNALEMENT = ['horaire_faux', 'annule', 'mauvais_lieu', 'doublon', 'autre'];

    /** Distance maximale pour rattacher la position de l'utilisateur à une commune (messages de service d'une ville). */
    public const RAYON_COMMUNE_METRES = 20_000;

    public function enregistrer(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'plateforme' => ['required', 'in:ios,android'],
            'jeton_push' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ]);

        $appareil = Appareil::firstOrNew(['identifiant' => $request->attributes->get('appareil')]);
        $appareil->fill([
            'plateforme' => $donnees['plateforme'],
            'version_app' => $request->attributes->get('version_app'),
            'premiere_ouverture' => $appareil->premiere_ouverture ?? now(),
            'derniere_ouverture' => now(),
        ]);
        if (array_key_exists('jeton_push', $donnees)) {
            $appareil->jeton_push = $donnees['jeton_push'];
        }
        $appareil->nb_ouvertures++;

        $poserQuestion = $appareil->doitPoserQuestionSuggestion();
        if ($poserQuestion) {
            // Relance après un « Non merci » : comptée dès qu'elle est posée (un 2e refus est définitif, F6.2).
            if ($appareil->suggestion_choix === ChoixSuggestion::NonMerci) {
                $appareil->suggestion_relances++;
            }
            $appareil->suggestion_question_le = now();
        }
        $appareil->save();

        $villeId = isset($donnees['latitude']) ? self::communeProche(new Point((float) $donnees['latitude'], (float) $donnees['longitude'])) : null;

        return response()->json([
            'version_minimale' => Parametre::valeur('version_minimale_app'),
            'parametres' => collect(self::PARAMETRES)->mapWithKeys(fn (string $cle) => [$cle => Parametre::valeur($cle)])->all(),
            'genres' => Genre::orderBy('ordre')->get(['id', 'slug', 'libelle']),
            'motifs_signalement' => self::MOTIFS_SIGNALEMENT,
            'messages_service' => MessageService::aAfficher($villeId)->get()
                ->map(fn (MessageService $m) => ['id' => $m->id, 'type' => $m->type->value, 'texte' => $m->texte])->values(),
            'poser_question_suggestion' => $poserQuestion,
            // Profil › À propos (F1.6) et demande d'espace salle (F9.1) : pages web du BO.
            'liens' => [
                'confidentialite' => url('/confidentialite'), 'conditions' => url('/conditions'), 'mentions_legales' => url('/mentions-legales'),
                'espace_salle' => url('/espace-salle/demande'),
            ],
        ], options: JSON_UNESCAPED_UNICODE);
    }

    /** Commune dont le centre est le plus proche de la position (index géographique). */
    public static function communeProche(Point $position): ?int
    {
        return Ville::whereRaw('ST_DWithin(position, ?::geography, ?)', [$position->versEwkt(), self::RAYON_COMMUNE_METRES])
            ->orderByRaw('position <-> ?::geography', [$position->versEwkt()])
            ->value('id');
    }
}
