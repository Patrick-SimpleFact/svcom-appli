<?php

namespace App\Actions;

use App\Collecte\AnnonceNormalisee;
use App\Collecte\ClasseurGenres;
use App\Collecte\ResultatGenre;
use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Models\ElementATraiter;
use App\Models\Source;

/**
 * Étape « genres » de la chaîne (COLLECTE §6) : une annonce sans correspondance ni mot connu est publiée en « Autres »,
 * et ses catégories inconnues vont dans la file « À classer » (une ligne par source et par catégorie).
 */
class ClasserAnnonce
{
    private const EXEMPLES_MAX = 3;

    /** Annonces distinctes comptées par catégorie (au-delà, on affiche « 1000 et plus »). */
    private const IDENTIFIANTS_MAX = 1000;

    public function __construct(private ClasseurGenres $classeur) {}

    public function handle(AnnonceNormalisee $annonce, Source $source): ResultatGenre
    {
        $resultat = $this->classeur->classer($annonce, $source);

        if ($resultat->origine === ResultatGenre::PAR_DEFAUT) {
            foreach (array_unique(array_map('trim', $annonce->categoriesSource)) as $categorie) {
                if ($categorie !== '') {
                    $this->aClasser($source, $categorie, $annonce);
                }
            }
        }

        return $resultat;
    }

    private function aClasser(Source $source, string $categorie, AnnonceNormalisee $annonce): void
    {
        $element = ElementATraiter::where('file', FileATraiter::AClasser)
            ->where('cible_type', $source->getMorphClass())
            ->where('cible_id', $source->id)
            ->where('statut', StatutElement::EnAttente)
            ->where('donnees->categorie', $categorie)
            ->first();

        $donnees = $element?->donnees ?? ['categorie' => $categorie, 'nb_annonces' => 0, 'exemples' => [], 'identifiants' => []];

        // Une même annonce (ou un même spectacle, pour une source qui publie par séance) n'est comptée qu'une fois.
        if (in_array($annonce->cleSpectacle(), $donnees['identifiants'], true)) {
            return;
        }

        if (count($donnees['identifiants']) < self::IDENTIFIANTS_MAX) {
            $donnees['identifiants'][] = $annonce->cleSpectacle();
        }
        $donnees['nb_annonces'] = count($donnees['identifiants']);

        if (count($donnees['exemples']) < self::EXEMPLES_MAX && ! in_array($annonce->titre, $donnees['exemples'], true)) {
            $donnees['exemples'][] = $annonce->titre;
        }

        if ($element === null) {
            ElementATraiter::create([
                'file' => FileATraiter::AClasser,
                'cible_type' => $source->getMorphClass(),
                'cible_id' => $source->id,
                'donnees' => $donnees,
            ]);
        } else {
            $element->update(['donnees' => $donnees]);
        }
    }
}
