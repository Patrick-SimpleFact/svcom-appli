<?php

namespace App\Actions;

use App\Collecte\AnnonceNormalisee;
use App\Collecte\ComparaisonSeances;
use App\Collecte\ResultatGenre;
use App\Models\Collecte;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Source;

/**
 * Enregistre l'offre d'une annonce gardée, avec ce que la chaîne a calculé (lieu, genre, jour local, titre comparable).
 * Elle n'est pas encore publiée : les représentations seront créées à partir des offres à l'étape K08.
 */
class EnregistrerOffre
{
    public function __construct(private ComparaisonSeances $comparaison) {}

    /**
     * @return array{0: Offre, 1: bool, 2: bool} l'offre ; vrai si la séance (jour, heure, lieu, titre) est nouvelle ou a changé ;
     *                                           vrai si quoi que ce soit a changé (prix, complet…) : à republier
     */
    public function handle(AnnonceNormalisee $annonce, Source $source, Lieu $lieu, ResultatGenre $genre, ?Collecte $collecte = null): array
    {
        // Sans heure, la date est prise telle quelle (convertie, minuit à Paris deviendrait la veille aux Antilles).
        $dateLocale = $annonce->heureConnue ? $annonce->debut->setTimezone($lieu->fuseau_horaire ?? 'Europe/Paris') : $annonce->debut;

        $seance = [
            'lieu_id' => $lieu->id,
            'debut' => $annonce->debut,
            'heure_connue' => $annonce->heureConnue,
            'date_locale' => $dateLocale->format('Y-m-d'),
            'titre_comparable' => $this->comparaison->titreComparable($annonce->titre, $annonce->lieuNom ?? $lieu->nom, $annonce->lieuVille),
        ];

        $offre = Offre::firstOrNew(['source_id' => $source->id, 'identifiant_externe' => $annonce->identifiantExterne]);
        $avant = $offre->exists ? $this->cleSeance($offre) : null;
        $empreinteAvant = $offre->exists && $offre->disparue_le === null ? $offre->empreinte : null;

        $offre->fill([
            ...$seance,
            'genre_id' => $genre->genre->id,
            'jeune_public' => $genre->jeunePublic,
            'lien' => $annonce->lien,
            'prix_min' => $annonce->prixMin,
            'prix_max' => $annonce->prixMax,
            'complet' => $annonce->complet,
            'donnees_normalisees' => $annonce->versTableau(),
            'empreinte' => $annonce->empreinte(),
            'vue_le' => now(),
            'derniere_collecte_id' => $collecte?->id,
            'disparue_le' => null,
        ])->save();

        return [$offre, $avant !== $this->cleSeance($offre), $empreinteAvant !== $offre->empreinte];
    }

    private function cleSeance(Offre $offre): string
    {
        return implode('|', [$offre->lieu_id, $offre->debut?->utc()->toIso8601String(), (int) $offre->heure_connue, $offre->date_locale?->format('Y-m-d'), $offre->titre_comparable]);
    }
}
