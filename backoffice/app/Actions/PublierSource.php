<?php

namespace App\Actions;

use App\Collecte\ComparaisonLieux;
use App\Enums\StatutRepresentation;
use App\Enums\TypeRepresentation;
use App\Models\Collecte;
use App\Models\Genre;
use App\Models\Offre;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Spectacle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Étape « publication » (COLLECTE §8) : à la fin d'une collecte réussie, chaque groupe de séances (K06) touché par
 * la source devient une représentation de son spectacle (K07), en une seule transaction (l'app ne voit jamais un
 * état à moitié importé ; une collecte qui échoue en route ne change rien au catalogue).
 * Valeurs retenues (§8.1) : horaire et lieu de la source la plus fiable pour ce champ ; prix min/max et « complet »
 * calculés sur toutes les offres ; description la plus longue. Un champ corrigé à la main n'est jamais écrasé (F7.8).
 * Retraits (K08b) : une offre à venir absente de la collecte est « disparue » ; une représentation qui n'a plus
 * aucune offre est retirée (elle revient si une offre réapparaît).
 */
class PublierSource
{
    private const RANG_FIABILITE = ['elevee' => 3, 'moyenne' => 2, 'faible' => 1];

    private ?int $genreAutres = null;

    /** Paquets d'identifiants : PostgreSQL limite une requête à 65 535 paramètres. */
    private const TAILLE_PAQUET = 5000;

    /**
     * @param  Collecte|null  $collecte  collecte réussie : les offres à venir de la source qu'elle n'a pas vues ont disparu
     *                                   du flux (null : aucune disparition, simple republication)
     * @param  list<int>  $aRepublier  offres nouvelles, modifiées ou jamais publiées
     * @return array{nb_nouveaux: int, nb_mis_a_jour: int, nb_retires: int}
     */
    public function handle(Source $source, ?Collecte $collecte, array $aRepublier): array
    {
        return DB::transaction(function () use ($source, $collecte, $aRepublier) {
            $premieres = collect();

            foreach (array_chunk($aRepublier, self::TAILLE_PAQUET) as $paquet) {
                $premieres = $premieres->merge(Offre::whereKey($paquet)->whereNull('disparue_le')
                    ->selectRaw('coalesce(meme_seance_que_id, id) as premiere')->pluck('premiere'));
            }

            // Offres à venir que cette collecte n'a pas vues : disparues du flux (une séance passée sort seule du flux, on la garde).
            if ($collecte !== null) {
                $disparues = $this->marquerDisparues(Offre::where('source_id', $source->id)
                    ->where(fn ($q) => $q->whereNull('derniere_collecte_id')->orWhere('derniere_collecte_id', '!=', $collecte->id)));
                $premieres = $premieres->merge($disparues->map(fn (Offre $o) => $o->meme_seance_que_id ?? $o->id));
            }

            return $this->publierPremieres($premieres->unique());
        });
    }

    /**
     * Marque disparues les offres à venir de la requête, et renvoie celles qui l'ont été.
     *
     * @return Collection<int, Offre>
     */
    public function marquerDisparues($requete): Collection
    {
        $offres = $requete->whereNull('disparue_le')
            ->whereRaw('coalesce(date_fin, date_locale) >= ?', [today()->toDateString()]) // une période en cours n'est pas passée
            ->get(['id', 'meme_seance_que_id']);

        foreach ($offres->chunk(self::TAILLE_PAQUET) as $paquet) {
            Offre::whereKey($paquet->modelKeys())->update(['disparue_le' => now()]);
        }

        return $offres;
    }

    /**
     * Republie les groupes de séances de ces offres.
     *
     * @param  Collection<int, Offre>  $offres
     * @return array{nb_nouveaux: int, nb_mis_a_jour: int, nb_retires: int}
     */
    public function publierGroupes(Collection $offres): array
    {
        return $this->publierPremieres($offres->map(fn (Offre $offre) => $offre->meme_seance_que_id ?? $offre->id)->unique());
    }

    /**
     * Republie les groupes de séances désignés par leur première offre.
     *
     * @param  iterable<int>  $premieres
     * @return array{nb_nouveaux: int, nb_mis_a_jour: int, nb_retires: int}
     */
    private function publierPremieres(iterable $premieres): array
    {
        $compteurs = ['nb_nouveaux' => 0, 'nb_mis_a_jour' => 0, 'nb_retires' => 0];

        foreach ($premieres as $premiereId) {
            $resultat = $this->publierSeance((int) $premiereId);

            if ($resultat !== null) {
                $compteurs[$resultat]++;
            }
        }

        return $compteurs;
    }

    /** @return 'nb_nouveaux'|'nb_mis_a_jour'|'nb_retires'|null */
    private function publierSeance(int $premiereId): ?string
    {
        $offres = Offre::with('source', 'lieu')
            ->where(fn ($q) => $q->whereKey($premiereId)->orWhere('meme_seance_que_id', $premiereId))
            ->whereNull('disparue_le')
            ->orderBy('id')
            ->get();

        if ($offres->isEmpty()) {
            return $this->retirer($premiereId);
        }

        $spectacleId = $offres->firstWhere('id', $premiereId)?->spectacle_id ?? $offres->whereNotNull('spectacle_id')->first()?->spectacle_id;

        if ($spectacleId === null) {
            return null;
        }

        $representation = $this->representationDuGroupe($premiereId) ?? new Representation;
        $nouvelle = ! $representation->exists;

        $horaire = $this->plusFiable($offres->where('heure_connue', true), 'horaire') ?? $this->plusFiable($offres, 'horaire');
        $lieu = $this->plusFiable($offres, 'lieu');
        $prixMin = $offres->whereNotNull('prix_min')->min('prix_min');
        $prixMax = $offres->map(fn (Offre $o) => $o->prix_max ?? $o->prix_min)->filter(fn ($p) => $p !== null)->max();

        $valeurs = [
            'spectacle_id' => $spectacleId,
            'lieu_id' => $lieu->lieu_id,
            'salle' => app(ComparaisonLieux::class)->salle($lieu->donnees_normalisees['lieu_nom'] ?? null, $lieu->lieu?->nom ?? ''),
            'type' => match (true) {
                $horaire->heure_connue => TypeRepresentation::Seance,
                $horaire->date_fin !== null => TypeRepresentation::Periode,
                default => TypeRepresentation::Jour,
            },
            'debut' => $horaire->heure_connue ? $horaire->debut : null,
            'date_locale' => $horaire->date_locale, // recalculée à partir de l'heure pour une séance
            'date_fin' => $horaire->heure_connue ? null : $horaire->date_fin,
            'prix_min' => $prixMin,
            'prix_max' => $prixMax,
            'gratuit' => $prixMin === null && $offres->contains(fn (Offre $o) => $o->donnees_normalisees['gratuit'] ?? false),
            'complet' => $offres->every(fn (Offre $o) => $o->complet),
        ];

        // Un champ corrigé à la main l'emporte toujours ; le statut (annulée, masquée) n'est jamais changé par la collecte.
        $representation->fill(array_filter($valeurs, fn ($v, string $champ) => ! $representation->estVerrouille($champ), ARRAY_FILTER_USE_BOTH));

        // Nouvelle, ou retirée puis revenue dans un flux : programmée (sauf statut fixé à la main).
        if ($nouvelle || ($representation->statut === StatutRepresentation::Retiree && ! $representation->estVerrouille('statut'))) {
            $representation->statut = StatutRepresentation::Programmee;
        }

        $changee = $nouvelle || $representation->isDirty();
        $representation->save();

        Offre::whereKey($offres->modelKeys())->update(['representation_id' => $representation->id]);
        $this->choisirValeursDuSpectacle(Spectacle::find($spectacleId), $offres);

        return match (true) {
            $nouvelle => 'nb_nouveaux',
            $changee => 'nb_mis_a_jour',
            default => null,
        };
    }

    /**
     * Plus aucune billetterie ne vend la séance : sa représentation est retirée (COLLECTE §8.2),
     * sauf si elle a été corrigée à la main ou si son statut a été fixé à la main (annulée, masquée).
     *
     * @return 'nb_retires'|null
     */
    private function retirer(int $premiereId): ?string
    {
        $representationId = Offre::where(fn ($q) => $q->whereKey($premiereId)->orWhere('meme_seance_que_id', $premiereId))
            ->whereNotNull('representation_id')
            ->orderBy('id')
            ->value('representation_id');
        $representation = $representationId ? Representation::find($representationId) : null;

        $encoreVendue = $representation !== null
            && Offre::where('representation_id', $representation->id)->whereNull('disparue_le')->exists();

        if ($representation === null || $encoreVendue || $representation->statut !== StatutRepresentation::Programmee
            || ($representation->champs_verrouilles ?? []) !== []) {
            return null;
        }

        $representation->update(['statut' => StatutRepresentation::Retiree]);

        return 'nb_retires';
    }

    /**
     * La représentation déjà publiée pour ce groupe. Si des séances ont été séparées à la main entre-temps, plusieurs
     * groupes peuvent la désigner : elle reste au groupe de la plus ancienne offre qui la porte, les autres en reçoivent une nouvelle.
     */
    private function representationDuGroupe(int $premiereId): ?Representation
    {
        // Toutes les offres du groupe, disparues comprises : une séance garde sa représentation même quand l'offre qui
        // l'avait créée disparaît (ex. passage de l'export DATAtourisme à son API, N03b).
        $offres = Offre::where(fn ($q) => $q->whereKey($premiereId)->orWhere('meme_seance_que_id', $premiereId))->get(['id', 'representation_id']);

        foreach ($offres->pluck('representation_id')->filter()->unique() as $representationId) {
            $plusAncienne = Offre::where('representation_id', $representationId)->orderBy('id')->first();

            if (($plusAncienne?->meme_seance_que_id ?? $plusAncienne?->id) === $premiereId) {
                return Representation::find($representationId);
            }
        }

        return null;
    }

    /** L'offre de la source la plus fiable pour ce champ (à égalité : la plus ancienne). */
    private function plusFiable(Collection $offres, string $champ): ?Offre
    {
        return $offres->sortBy([
            fn (Offre $a, Offre $b) => $this->rang($b, $champ) <=> $this->rang($a, $champ),
            fn (Offre $a, Offre $b) => $a->id <=> $b->id,
        ])->first();
    }

    private function rang(Offre $offre, string $champ): int
    {
        return self::RANG_FIABILITE[$offre->source->fiabilite[$champ] ?? ''] ?? 0;
    }

    /** Description la plus longue et image d'une billetterie, sauf correction à la main (§8.1). */
    private function choisirValeursDuSpectacle(?Spectacle $spectacle, Collection $offres): void
    {
        if ($spectacle === null) {
            return;
        }

        $descriptions = $offres->map(fn (Offre $o) => trim(html_entity_decode(strip_tags((string) ($o->donnees_normalisees['description'] ?? '')))))
            ->push(trim((string) $spectacle->description))
            ->filter();
        $image = $this->plusFiable($offres->filter(fn (Offre $o) => filled($o->donnees_normalisees['image_url'] ?? null)), 'description')
            ?->donnees_normalisees['image_url'];

        $valeurs = array_filter([
            'description' => $descriptions->sortByDesc(fn (string $d) => mb_strlen($d))->first(),
            'image_url' => $spectacle->image_url ?? $image,
        ], fn ($v, string $champ) => $v !== null && ! $spectacle->estVerrouille($champ), ARRAY_FILTER_USE_BOTH);

        // Classé « Autres » faute de mieux par sa 1re source : prend le genre qu'une autre de ses sources sait donner.
        $autres = $this->genreAutres ??= Genre::where('slug', 'autres')->value('id');
        $meilleurGenre = $offres->pluck('genre_id')->filter(fn ($g) => $g !== null && $g !== $autres)->countBy()->sortDesc()->keys()->first();

        if ($spectacle->genre_id === $autres && $meilleurGenre !== null && ! $spectacle->estVerrouille('genre_id')) {
            $valeurs['genre_id'] = $meilleurGenre;
        }

        $spectacle->fill($valeurs);

        if ($spectacle->isDirty()) {
            // Mise à jour automatique : sans verrouillage ni journal, même lancée depuis le back-office.
            $spectacle->saveQuietly();

            if ($spectacle->wasChanged('genre_id')) {
                $spectacle->representations()->update(['genre_id' => $spectacle->genre_id]);
            }
        }
    }
}
