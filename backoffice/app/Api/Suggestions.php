<?php

namespace App\Api;

use App\Comptes\Gouts;
use App\Enums\ChoixSuggestion;
use App\Enums\TypeRepresentation;
use App\Http\Controllers\Api\AppareilController;
use App\Models\AffichageSuggestion;
use App\Models\Appareil;
use App\Models\Campagne;
use App\Models\Genre;
use App\Models\Parametre;
use App\Models\Representation;
use App\Models\Utilisateur;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Suggestion à l'ouverture (F6, API §9) : au plus une par jour, seulement si l'utilisateur l'a acceptée.
 * Un spectacle de ce soir (à défaut demain), près de lui, dans ses goûts ; jamais complet, passé ou masqué.
 * Sponsorisée si une campagne correspond aussi au profil et que la part maximale de sponsorisé est respectée ; sinon automatique.
 */
class Suggestions
{
    /** Un spectacle déjà suggéré à ce téléphone n'est pas reproposé avant ce délai (suggestion automatique). */
    public const JOURS_SANS_REPETITION = 7;

    /** La part de sponsorisé est comptée sur les dernières suggestions du téléphone. */
    public const AFFICHAGES_COMPTES = 10;

    /** Droit d'un utilisateur qui ne voit jamais de sponsorisé (F6.6, version payante anticipée). */
    public const DROIT_SANS_SPONSORISE = 'sans_sponsorise';

    private const CANDIDATS = 50;

    /** La suggestion du jour, ou null (rien à montrer : réponse 204). */
    public function handle(string $identifiant, Point $position, array $gouts, ?Utilisateur $utilisateur): ?array
    {
        $appareil = Appareil::firstWhere('identifiant', $identifiant);

        if (! Parametre::valeur('suggestions_actives') || $appareil?->suggestion_choix !== ChoixSuggestion::Oui) {
            return null;
        }

        $fuseau = AutourDeMoi::fuseau($position);
        $jour = fn (CarbonImmutable $instant) => $instant->setTimezone($fuseau)->subHours(Representation::HEURE_FIN_DE_SOIREE)->toDateString();

        if ($appareil->derniere_suggestion_le && $jour(CarbonImmutable::parse($appareil->derniere_suggestion_le)) === $jour(CarbonImmutable::now())) {
            return null; // F6.3 : une par jour
        }

        if (Parametre::valeur('suggestions_villes_test_seulement') && ! Ville::whereKey(AppareilController::communeProche($position))->where('suggestions_test', true)->exists()) {
            return null; // F6.4 : démarrage dans des villes test
        }

        $genres = $utilisateur ? (app(Gouts::class)->pourVous($utilisateur)['genres'] ?? []) : $gouts;
        $sponsorisePossible = $this->sponsorisePossible($identifiant, $utilisateur);

        foreach (['ce_soir', 'demain'] as $quand) {
            $candidats = $this->candidats($position, Fenetre::pour($quand, $fuseau), $genres);

            if ($candidats->isEmpty()) {
                continue;
            }

            [$choisie, $campagne] = ($sponsorisePossible ? $this->sponsorisee($candidats, $position, $genres) : null)
                ?? [$this->automatique($candidats, $identifiant), null];

            if ($choisie !== null) {
                return $this->afficher($appareil, $choisie, $campagne, $genres, $quand);
            }
        }

        return null;
    }

    /** Séances visibles, non complètes, dans la distance du réglage et les goûts ; les plus proches d'abord. */
    private function candidats(Point $position, Fenetre $fenetre, array $genres): Collection
    {
        $requete = Representation::query()->visibles()
            ->where('representations.type', TypeRepresentation::Seance)
            ->where('representations.complet', false)
            ->whereRaw('ST_DWithin(representations.position, ?::geography, ?)', [$position->versEwkt(), (int) Parametre::valeur('suggestion_rayon_km') * 1000])
            ->when($genres !== [], fn ($q) => $q->whereIn('representations.genre_id', $genres));

        return $fenetre->appliquer($requete)
            ->select('representations.*')
            ->selectRaw('ST_Distance(representations.position, ?::geography) as distance_m', [$position->versEwkt()])
            ->orderBy('distance_m')->orderBy('representations.debut')
            ->limit(self::CANDIDATS)
            ->get();
    }

    /**
     * F6.4 : au plus une part du réglage (0,5 = une sur deux) parmi les dernières suggestions du téléphone, celle-ci comprise ;
     * la toute première suggestion est donc toujours automatique. Jamais pour un utilisateur « sans sponsorisé » (F6.6).
     */
    private function sponsorisePossible(string $identifiant, ?Utilisateur $utilisateur): bool
    {
        if (in_array(self::DROIT_SANS_SPONSORISE, $utilisateur?->droits ?? [], true)) {
            return false;
        }

        $dernieres = AffichageSuggestion::where('appareil', $identifiant)->latest('affiche_le')->limit(self::AFFICHAGES_COMPTES)->pluck('campagne_id');

        return ($dernieres->filter()->count() + 1) / ($dernieres->count() + 1) <= (float) Parametre::valeur('suggestion_part_sponsorisee_max');
    }

    /**
     * F6.1 : une campagne n'est montrée que si elle correspond aussi au profil : l'utilisateur est dans sa zone,
     * ses goûts croisent les genres ciblés, et une séance du spectacle est parmi les candidats (proche, ce soir ou demain, dans ses goûts).
     * Entre plusieurs campagnes, la moins avancée par rapport à ce qui a été acheté passe d'abord.
     *
     * @return array{Representation, Campagne}|null
     */
    private function sponsorisee(Collection $candidats, Point $position, array $genres): ?array
    {
        $campagnes = Campagne::diffusables(CarbonImmutable::now('Europe/Paris')->toDateString())
            ->whereIn('spectacle_id', $candidats->pluck('spectacle_id')->unique())
            ->whereRaw('ST_DWithin((select position from villes where villes.id = campagnes.ville_id), ?::geography, campagnes.zone_rayon_km * 1000)', [$position->versEwkt()])
            ->withCount('affichages')
            ->get()
            ->filter(fn (Campagne $c) => $c->genres === [] || $genres === [] || array_intersect($c->genres, $genres) !== [])
            ->sortBy(fn (Campagne $c) => $c->affichages_count / max(1, $c->affichages_achetes));

        $campagne = $campagnes->first();

        return $campagne ? [$candidats->firstWhere('spectacle_id', $campagne->spectacle_id), $campagne] : null;
    }

    /** La séance la plus proche d'un spectacle pas suggéré récemment à ce téléphone. */
    private function automatique(Collection $candidats, string $identifiant): ?Representation
    {
        $recents = AffichageSuggestion::where('appareil', $identifiant)
            ->where('affiche_le', '>=', now()->subDays(self::JOURS_SANS_REPETITION))->pluck('spectacle_id')->all();

        return $candidats->first(fn (Representation $r) => ! in_array($r->spectacle_id, $recents, true));
    }

    private function afficher(Appareil $appareil, Representation $r, ?Campagne $campagne, array $genres, string $quand): array
    {
        $r->load(['spectacle', 'lieu.ville']);
        $affichage = AffichageSuggestion::create([
            'appareil' => $appareil->identifiant, 'representation_id' => $r->id, 'spectacle_id' => $r->spectacle_id,
            'campagne_id' => $campagne?->id, 'affiche_le' => now(),
        ]);
        $appareil->update(['derniere_suggestion_le' => now()]);

        $genre = $r->genre_id ? Genre::find($r->genre_id) : null;
        $debut = CarbonImmutable::parse($r->debut)->setTimezone($r->lieu->fuseau_horaire ?? 'Europe/Paris');
        $distance = (int) (round((float) $r->distance_m / 10) * 10);

        return [
            'affichage_id' => $affichage->id,
            'type' => $campagne ? 'sponsorise' : 'auto',
            'annonceur' => $campagne?->annonceur?->nom,
            'raison' => collect([
                $genre && in_array($genre->id, $genres, true) ? "Proposé car vous aimez : {$genre->libelle}" : 'Près de vous',
                'à '.($distance < 1000 ? "{$distance} m" : str_replace('.', ',', (string) round($distance / 1000, $distance < 10_000 ? 1 : 0)).' km'),
                ($quand === 'ce_soir' ? 'ce soir' : 'demain').' à '.$debut->format('G \h i'),
            ])->implode(' · '),
            'spectacle' => [
                'id' => $r->spectacle_id,
                'titre' => $r->spectacle->titre,
                'genre' => $genre ? ['id' => $genre->id, 'libelle' => $genre->libelle] : null,
                'image_url' => $campagne?->urlVisuel() ?? $r->spectacle->image_url,
            ],
            'seance' => ['representation_id' => $r->id, 'debut' => $debut->toIso8601String()],
            'lieu' => ['id' => $r->lieu_id, 'nom' => $r->lieu->nom, 'ville' => $r->lieu->ville?->nom],
            'distance_m' => $distance,
            'prix_min' => $r->prix_min !== null ? (float) $r->prix_min : null,
            'prix_max' => $r->prix_max !== null ? (float) $r->prix_max : null,
            'gratuit' => (bool) $r->gratuit,
        ];
    }
}
