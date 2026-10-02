<?php

namespace App\Collecte;

use App\Enums\IssueFiltrage;
use App\Enums\TypeRegleFiltrage;
use App\Models\RegleFiltrage;
use App\Support\Texte;

/**
 * Le spectacle vivant est-il au programme ? Score combinant trois signaux (COLLECTE §5, F7.4) :
 * la catégorie donnée par la source (fort), le titre (moyen), la description (faible).
 * Dans chaque zone, un mot qui inclut ajoute des points, un mot qui exclut en retire davantage.
 */
class FiltreSpectacleVivant
{
    /** Points par zone : [mot qui inclut, mot qui exclut]. */
    public const POIDS = [
        'categorie' => [3, 3],
        'titre' => [2, 3],
        'description' => [1, 1],
    ];

    /** Score à partir duquel l'annonce est gardée ; en dessous de 0 elle est exclue ; entre les deux, à trier. */
    public const SEUIL_GARDE = 2;

    /** @var array{inclure: list<string>, exclure: list<string>}|null */
    private ?array $mots = null;

    public function evaluer(AnnonceNormalisee $annonce): ResultatFiltrage
    {
        $zones = [
            'categorie' => $this->preparer(implode(' ', array_map(self::decouperCategorie(...), $annonce->categoriesSource))),
            'titre' => $this->preparer($annonce->titre),
            'description' => $this->preparer($annonce->description),
        ];

        $score = 0;
        $motifs = [];

        foreach ($zones as $zone => $texte) {
            [$poidsInclure, $poidsExclure] = self::POIDS[$zone];

            if ($mot = $this->premierMot($texte, $this->mots()['inclure'])) {
                $score += $poidsInclure;
                $motifs[] = "+{$poidsInclure} {$zone} « {$mot} »";
            }

            if ($mot = $this->premierMot($texte, $this->mots()['exclure'])) {
                $score -= $poidsExclure;
                $motifs[] = "−{$poidsExclure} {$zone} « {$mot} »";
            }
        }

        $issue = match (true) {
            $score >= self::SEUIL_GARDE => IssueFiltrage::Garde,
            $score < 0 => IssueFiltrage::Exclu,
            default => IssueFiltrage::ATrier,
        };

        return new ResultatFiltrage($issue, $score, $motifs);
    }

    /** « TheaterEvent » (DATAtourisme) → « Theater Event ». */
    private static function decouperCategorie(string $categorie): string
    {
        return preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $categorie);
    }

    private function preparer(?string $texte): string
    {
        return ' '.Texte::normaliser($texte).' ';
    }

    /** Mot entier, au singulier ou au pluriel. */
    private function premierMot(string $texte, array $mots): ?string
    {
        foreach ($mots as $mot) {
            if (str_contains($texte, " {$mot} ") || str_contains($texte, " {$mot}s ") || str_contains($texte, " {$mot}x ")) {
                return $mot;
            }
        }

        return null;
    }

    /** Listes lues une fois par collecte (une instance par collecte). */
    private function mots(): array
    {
        return $this->mots ??= [
            'inclure' => $this->liste(TypeRegleFiltrage::Inclure),
            'exclure' => $this->liste(TypeRegleFiltrage::Exclure),
        ];
    }

    private function liste(TypeRegleFiltrage $type): array
    {
        return RegleFiltrage::where('type', $type)->where('actif', true)->pluck('mot')
            ->map(fn (string $mot) => Texte::normaliser($mot))->filter()->values()->all();
    }
}
