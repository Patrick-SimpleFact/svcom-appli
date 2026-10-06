<?php

namespace App\Actions;

use App\Models\ElementATraiter;
use App\Models\Offre;
use App\Models\Source;
use App\Models\Spectacle;
use Illuminate\Support\Facades\DB;

/**
 * Entretien régulier du catalogue (COLLECTE §8.2, F7.15) :
 * - source muette depuis plus de 48 h (ni détection ni collecte réussie) : ses offres à venir sont retirées ;
 * - historique allégé : 30 jours après la séance, les offres (liens, prix, données de la source) sont supprimées ;
 *   la représentation, le spectacle et le lieu sont gardés sans limite ;
 * - spectacles restés vides (ni offre ni représentation) : supprimés.
 */
class EntretenirCatalogue
{
    public const SILENCE_MAX_HEURES = 48;

    public const HISTORIQUE_OFFRES_JOURS = 30;

    public function __construct(private PublierSource $publier) {}

    /** @return array{sources_muettes: list<string>, nb_retires: int, offres_supprimees: int, spectacles_supprimes: int} */
    public function handle(): array
    {
        $resultat = ['sources_muettes' => [], 'nb_retires' => 0, 'offres_supprimees' => 0, 'spectacles_supprimes' => 0];

        // 1. Sources muettes.
        $muettes = Source::where(fn ($q) => $q->whereNull('dernier_contact_le')->orWhere('dernier_contact_le', '<', now()->subHours(self::SILENCE_MAX_HEURES)))
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

        // 3. Spectacles vides (hors données de démonstration).
        $resultat['spectacles_supprimes'] = Spectacle::where('demo', false)->doesntHave('offres')->doesntHave('representations')->delete();

        return $resultat;
    }
}
