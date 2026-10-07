<?php

namespace App\Actions;

use App\Enums\StatutCollecte;
use App\Jobs\CollecterSource;
use App\Models\Collecte;
use App\Models\ElementATraiter;
use App\Models\Offre;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Spectacle;
use App\Support\Horizon;
use Illuminate\Support\Facades\DB;

/**
 * Entretien régulier du catalogue (COLLECTE §8.2, F7.15) :
 * - source muette depuis plus de 48 h (ni détection ni collecte réussie) alors que le détecteur l'interroge bien : ses offres à venir sont retirées ;
 * - historique allégé : 30 jours après la séance, les offres (liens, prix, données de la source) sont supprimées ;
 *   la représentation, le spectacle et le lieu sont gardés sans limite ;
 * - spectacles restés vides (ni offre ni représentation) : supprimés ;
 * - collectes restées « en cours » au-delà de leur durée maximale (worker arrêté en route) : marquées interrompues ;
 * - séances au-delà de l'horizon (réglage « horizon_mois », réduit par exemple) : supprimées, sauf corrections manuelles.
 */
class EntretenirCatalogue
{
    public const SILENCE_MAX_HEURES = 48;

    /** Le détecteur passe toutes les 30 min : au-delà d'une heure sans passage, il était arrêté. */
    public const VERIFICATION_RECENTE_HEURES = 1;

    public const HISTORIQUE_OFFRES_JOURS = 30;

    public function __construct(private PublierSource $publier) {}

    /** @return array{sources_muettes: list<string>, nb_retires: int, offres_supprimees: int, spectacles_supprimes: int, collectes_interrompues: int, hors_horizon_supprimees: int} */
    public function handle(): array
    {
        $resultat = ['sources_muettes' => [], 'nb_retires' => 0, 'offres_supprimees' => 0, 'spectacles_supprimes' => 0];

        // 0. Collectes orphelines : au-delà de la durée maximale d'une tâche de collecte, elle ne tourne plus.
        $resultat['collectes_interrompues'] = Collecte::where('statut', StatutCollecte::EnCours)
            ->where('debut', '<', now()->subSeconds((new CollecterSource(new Source))->timeout))
            ->update(['statut' => StatutCollecte::Echouee, 'fin' => now(), 'erreur' => 'Interrompue (le worker s’est arrêté pendant la collecte).']);

        // 1. Sources muettes : seulement si le détecteur les a interrogées récemment. Si c'est notre côté qui était arrêté
        // (workers coupés, source désactivée), le silence ne vient pas de la source : on ne retire rien.
        $muettes = Source::where('actif', true)
            ->where('derniere_verification_le', '>=', now()->subHours(self::VERIFICATION_RECENTE_HEURES))
            ->where(fn ($q) => $q->whereNull('dernier_contact_le')->orWhere('dernier_contact_le', '<', now()->subHours(self::SILENCE_MAX_HEURES)))
            ->whereHas('offres', fn ($q) => $q->whereNull('disparue_le')->whereRaw('coalesce(date_fin, date_locale) >= ?', [today()->toDateString()]))
            ->get();

        foreach ($muettes as $source) {
            DB::transaction(function () use ($source, &$resultat) {
                $disparues = $this->publier->marquerDisparues(Offre::where('source_id', $source->id));
                $resultat['nb_retires'] += $this->publier->publierGroupes($disparues)['nb_retires'];
                $resultat['sources_muettes'][] = $source->code;
            });
        }

        // 2. Historique allégé.
        $limite = today()->subDays(self::HISTORIQUE_OFFRES_JOURS)->toDateString();
        Offre::whereRaw('coalesce(date_fin, date_locale) < ?', [$limite])->select('id')->chunkById(1000, function ($offres) use (&$resultat) {
            DB::transaction(function () use ($offres, &$resultat) {
                ElementATraiter::where('cible_type', (new Offre)->getMorphClass())->whereIn('cible_id', $offres->modelKeys())->delete();
                $resultat['offres_supprimees'] += Offre::whereKey($offres->modelKeys())->delete();
            });
        });

        // 2 bis. Au-delà de l'horizon des séances : ni offres ni représentations (sauf représentation corrigée à la main).
        $resultat['hors_horizon_supprimees'] = 0;
        if (($limite = Horizon::dateLimite()) !== null) {
            Offre::where('date_locale', '>', $limite->toDateString())->select('id')->chunkById(1000, function ($offres) use (&$resultat) {
                DB::transaction(function () use ($offres, &$resultat) {
                    ElementATraiter::where('cible_type', (new Offre)->getMorphClass())->whereIn('cible_id', $offres->modelKeys())->delete();
                    $resultat['hors_horizon_supprimees'] += Offre::whereKey($offres->modelKeys())->delete();
                });
            });
            Representation::where('date_locale', '>', $limite->toDateString())
                ->whereRaw("champs_verrouilles = '[]'::jsonb")
                ->doesntHave('offres')
                ->delete();
        }

        // 3. Spectacles vides (hors données de démonstration).
        $resultat['spectacles_supprimes'] = Spectacle::where('demo', false)->doesntHave('offres')->doesntHave('representations')->delete();

        return $resultat;
    }
}
