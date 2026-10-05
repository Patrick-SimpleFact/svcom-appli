<?php

namespace App\Actions;

use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Models\CorrespondanceGenre;
use App\Models\ElementATraiter;
use App\Models\Genre;
use App\Models\Offre;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Spectacle;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Une catégorie d'une source reçoit son genre (F7.6) : la correspondance est enregistrée, la catégorie sort de
 * « À classer », et les spectacles concernés restés en « Autres » changent de genre tout de suite (COLLECTE §6).
 * Un genre corrigé à la main sur un spectacle n'est jamais touché (F7.8).
 */
class EnregistrerCorrespondanceGenre
{
    /** @return int nombre de spectacles reclassés */
    public function handle(Source $source, string $categorie, Genre $genre, bool $jeunePublic = false, ?CorrespondanceGenre $existante = null): int
    {
        $categorie = trim($categorie);

        if ($categorie === '') {
            throw new InvalidArgumentException('La catégorie de la source est vide.');
        }

        return DB::transaction(function () use ($source, $categorie, $genre, $jeunePublic, $existante) {
            $valeurs = ['source_id' => $source->id, 'categorie_source' => $categorie, 'genre_id' => $genre->id, 'jeune_public' => $jeunePublic];

            $existante !== null
                ? $existante->update($valeurs)
                : CorrespondanceGenre::updateOrCreate(['source_id' => $source->id, 'categorie_source' => $categorie], $valeurs);

            ElementATraiter::where('file', FileATraiter::AClasser)
                ->where('cible_type', $source->getMorphClass())
                ->where('cible_id', $source->id)
                ->where('statut', StatutElement::EnAttente)
                ->where('donnees->categorie', $categorie)
                ->update([
                    'statut' => StatutElement::Traite,
                    'decision' => json_encode(['genre' => $genre->slug, 'jeune_public' => $jeunePublic]),
                    'traite_par' => auth()->id(),
                    'traite_le' => now(),
                ]);

            return $this->reclasser($source, $categorie, $genre, $jeunePublic);
        });
    }

    private function reclasser(Source $source, string $categorie, Genre $genre, bool $jeunePublic): int
    {
        $offres = Offre::select('representation_id')
            ->where('source_id', $source->id)
            ->whereNotNull('representation_id')
            ->whereJsonContains('donnees_normalisees->categories_source', $categorie);

        $spectacles = Spectacle::whereHas('genre', fn ($requete) => $requete->where('slug', 'autres'))
            ->whereIn('id', Representation::select('spectacle_id')->whereIn('id', $offres))
            ->get();

        $reclasses = 0;

        foreach ($spectacles as $spectacle) {
            $valeurs = array_filter(
                ['genre_id' => $genre->id, 'jeune_public' => $jeunePublic ?: null],
                fn ($valeur, string $champ) => $valeur !== null && ! $spectacle->estVerrouille($champ),
                ARRAY_FILTER_USE_BOTH,
            );

            if (isset($valeurs['genre_id'])) {
                // Conséquence automatique, pas une correction à la main : sans verrouillage ni journal (la correspondance, elle, est journalisée).
                $spectacle->fill($valeurs)->saveQuietly();
                $spectacle->representations()->update(['genre_id' => $genre->id]);
                $reclasses++;
            }
        }

        return $reclasses;
    }
}
