<?php

namespace App\Collecte;

use App\Models\Source;

/**
 * Contrat d'un connecteur : télécharger le flux brut de sa source, puis le traduire en annonces normalisées.
 * Tout le reste de la chaîne (tri, lieux, genres, doublons, publication) est commun à toutes les sources.
 */
interface Connecteur
{
    /** Télécharge le contenu brut du flux (gardé 30 jours pour pouvoir rejouer une collecte). */
    public function telecharger(Source $source): string;

    /**
     * Traduit le contenu brut en annonces. Une ligne impossible à traduire donne une LigneIllisible
     * (le connecteur l'attrape lui-même) : elle est comptée sans arrêter la collecte.
     *
     * @return iterable<AnnonceNormalisee|LigneIllisible>
     */
    public function lire(string $contenuBrut, Source $source): iterable;

    /** Extension du fichier brut (json, csv, gz…). */
    public function extensionBrut(): string;
}
