<?php

namespace App\Actions;

use App\Models\AffichageSuggestion;
use App\Models\ClicSortant;
use App\Models\Offre;
use Illuminate\Http\Request;

/**
 * Compte un clic vers une billetterie ou un organisateur (F7.13 bis, API §6), sans donnée personnelle.
 * Un même appareil qui clique plusieurs fois vers la même billetterie pour la même séance en 30 min ne compte qu'une fois ;
 * robots, aperçus de liens et appels de test (curl, Postman…) ne comptent pas. Le clic est toujours enregistré (compte = faux).
 */
class EnregistrerClic
{
    public const MINUTES_DEDOUBLONNAGE = 30;

    /** Robots, aperçus de liens dans les messageries, outils de test : jamais comptés. */
    public const NON_HUMAINS = '/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|slack|discord|curl|wget|python|postman|insomnia|httpie|go-http|java\//i';

    public function handle(Offre $offre, Request $requete): ClicSortant
    {
        $representation = $offre->representation;
        // Empreinte de l'appareil, mise dans le lien par l'API ; à défaut (page web partagée), celle de l'adresse IP.
        $empreinte = preg_match('/^[a-f0-9]{64}$/', (string) $requete->query('a')) ? $requete->query('a') : ClicSortant::empreinte('ip:'.$requete->ip());
        $agent = (string) $requete->userAgent();

        $dejaCompte = ClicSortant::where('appareil_hash', $empreinte)
            ->where('source_id', $offre->source_id)
            ->where('representation_id', $offre->representation_id)
            ->where('compte', true)
            ->where('horodatage', '>=', now()->subMinutes(self::MINUTES_DEDOUBLONNAGE))
            ->exists();

        $origine = $requete->query('origine');
        $distance = $requete->query('distance_km');

        // Clic venu d'une suggestion (F6.5) : noté sur l'affichage, pour les statistiques et le rapport annonceur.
        if (ctype_digit((string) $requete->query('affichage')) && $representation) {
            AffichageSuggestion::whereKey((int) $requete->query('affichage'))->where('representation_id', $representation->id)->update(['clic_billetterie' => true]);
        }

        return ClicSortant::create([
            'horodatage' => now(),
            'appareil_hash' => $empreinte,
            'offre_id' => $offre->id,
            'source_id' => $offre->source_id,
            'representation_id' => $representation?->id,
            'spectacle_id' => $representation?->spectacle_id ?? $offre->spectacle_id,
            'lieu_id' => $representation?->lieu_id ?? $offre->lieu_id,
            'ville_id' => $representation?->ville_id,
            'genre_id' => $representation?->genre_id,
            'origine' => in_array($origine, ClicSortant::ORIGINES, true) ? $origine : null,
            'bouton' => $requete->query('bouton') === 'autre' ? 'autre' : 'principal',
            'prix_affiche' => $offre->prix_min,
            'delai_avant_seance_min' => $representation?->debut ? (int) round(now()->diffInMinutes($representation->debut, false)) : null,
            'distance_km' => is_numeric($distance) && $distance >= 0 && $distance < 10000 ? (int) round((float) $distance) : null,
            'compte' => ! $dejaCompte && $agent !== '' && ! preg_match(self::NON_HUMAINS, $agent),
        ]);
    }
}
