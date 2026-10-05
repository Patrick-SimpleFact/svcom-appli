<?php

namespace App\Collecte;

use App\Models\CorrespondanceGenre;
use App\Models\Genre;
use App\Models\MotGenre;
use App\Models\Source;
use App\Support\Texte;
use Illuminate\Support\Collection;

/**
 * Genre d'une annonce (COLLECTE §6, F7.6), dans l'ordre :
 * 1. correspondance « catégorie de la source → genre » ; 2. mots du titre, puis de la description ; 3. « Autres ».
 * Le marqueur « Jeune public » s'ajoute par la correspondance ou par un mot (« jeune public », « enfant »…).
 * Une instance par collecte : correspondances et mots lus une seule fois.
 */
class ClasseurGenres
{
    /** @var array<int, array<string, CorrespondanceGenre>> par source, clé = catégorie normalisée */
    private array $correspondances = [];

    /** @var Collection<int, MotGenre>|null mots actifs, les plus longs d'abord (« comedie musicale » avant « comedie ») */
    private ?Collection $mots = null;

    private ?Genre $autres = null;

    public function classer(AnnonceNormalisee $annonce, Source $source): ResultatGenre
    {
        $classificationFine = $annonce->categoriesSource[0] ?? null;
        $jeunePublic = $this->contientJeunePublic($annonce);

        // 1. Correspondance de catégorie.
        foreach ($annonce->categoriesSource as $categorie) {
            if ($correspondance = $this->correspondancesDe($source)[Texte::normaliser($categorie)] ?? null) {
                return new ResultatGenre($correspondance->genre, $jeunePublic || $correspondance->jeune_public, $classificationFine, ResultatGenre::PAR_CORRESPONDANCE);
            }
        }

        // 2. Mots du titre, puis de la description.
        foreach ([$annonce->titre, $annonce->description] as $texte) {
            if ($mot = $this->premierMot($texte, fn (MotGenre $m) => $m->genre_id !== null)) {
                return new ResultatGenre($mot->genre, $jeunePublic, $classificationFine, ResultatGenre::PAR_MOT);
            }
        }

        // 3. « Autres » : jamais nulle part (F2.3).
        return new ResultatGenre($this->autres ??= Genre::where('slug', 'autres')->firstOrFail(), $jeunePublic, $classificationFine, ResultatGenre::PAR_DEFAUT);
    }

    private function contientJeunePublic(AnnonceNormalisee $annonce): bool
    {
        $texte = implode(' ', [$annonce->titre, $annonce->description, ...$annonce->categoriesSource]);

        return $this->premierMot($texte, fn (MotGenre $m) => $m->jeune_public) !== null;
    }

    /** @return array<string, CorrespondanceGenre> */
    private function correspondancesDe(Source $source): array
    {
        return $this->correspondances[$source->id] ??= CorrespondanceGenre::with('genre')->where('source_id', $source->id)->get()
            ->keyBy(fn (CorrespondanceGenre $c) => Texte::normaliser($c->categorie_source))
            ->all();
    }

    /** Mot entier, au singulier ou au pluriel (même règle que le filtre, COLLECTE §5). */
    private function premierMot(?string $texte, callable $retenir): ?MotGenre
    {
        $texte = ' '.Texte::normaliser($texte).' ';

        if (trim($texte) === '') {
            return null;
        }

        $this->mots ??= MotGenre::with('genre')->where('actif', true)->get()
            ->sortByDesc(fn (MotGenre $m) => mb_strlen($m->mot))->values();

        return $this->mots->filter($retenir)->first(
            fn (MotGenre $m) => str_contains($texte, " {$m->mot} ") || str_contains($texte, " {$m->mot}s ") || str_contains($texte, " {$m->mot}x "),
        );
    }
}
