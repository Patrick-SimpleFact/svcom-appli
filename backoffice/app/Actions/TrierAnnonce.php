<?php

namespace App\Actions;

use App\Collecte\AnnonceNormalisee;
use App\Collecte\FiltreSpectacleVivant;
use App\Enums\FileATraiter;
use App\Enums\IssueFiltrage;
use App\Enums\StatutElement;
use App\Models\ElementATraiter;
use App\Models\Source;

/**
 * Étape « filtrage » de la chaîne (COLLECTE §5) : gardé, exclu ou mis de côté dans la file « À trier ».
 * Une annonce à trier n'est pas publiée, mais ne bloque pas la collecte (F7.2).
 * Une décision prise dans la file pour cette annonce l'emporte sur le score.
 * Une source qui publie une annonce par séance est triée une fois par spectacle (`cleSpectacle`).
 */
class TrierAnnonce
{
    /** @var array<string, IssueFiltrage> spectacles déjà triés pendant cette collecte */
    private array $memoire = [];

    public function __construct(private FiltreSpectacleVivant $filtre) {}

    public function handle(AnnonceNormalisee $annonce, Source $source): IssueFiltrage
    {
        return $this->memoire["{$source->id}:{$annonce->cleSpectacle()}"] ??= $this->trier($annonce, $source);
    }

    private function trier(AnnonceNormalisee $annonce, Source $source): IssueFiltrage
    {
        $element = ElementATraiter::where('file', FileATraiter::ATrier)
            ->where('cible_type', $source->getMorphClass())
            ->where('cible_id', $source->id)
            ->where('donnees->identifiant_externe', $annonce->cleSpectacle())
            ->first();

        if ($element?->statut === StatutElement::Traite && isset($element->decision['issue']) && empty($element->decision['automatique'])) {
            return IssueFiltrage::from($element->decision['issue']);
        }

        $resultat = $this->filtre->evaluer($annonce);

        if ($resultat->issue === IssueFiltrage::ATrier) {
            ElementATraiter::updateOrCreate(['id' => $element?->id], [
                'file' => FileATraiter::ATrier,
                'cible_type' => $source->getMorphClass(),
                'cible_id' => $source->id,
                'statut' => StatutElement::EnAttente,
                'decision' => null,
                'traite_le' => null,
                'donnees' => [
                    'identifiant_externe' => $annonce->cleSpectacle(),
                    'titre' => $annonce->titre,
                    'categories_source' => $annonce->categoriesSource,
                    'description' => $annonce->description ? mb_strimwidth($annonce->description, 0, 500, '…') : null,
                    'lieu' => $annonce->lieuNom,
                    'ville' => $annonce->lieuVille,
                    'debut' => $annonce->debut?->toIso8601String(),
                    'lien' => $annonce->lien,
                    'score' => $resultat->score,
                    'motifs' => $resultat->motifs,
                ],
            ]);
        } elseif ($element !== null) {
            // Les listes de mots ont changé depuis : l'annonce n'est plus douteuse.
            $element->update([
                'statut' => StatutElement::Traite,
                'decision' => ['issue' => $resultat->issue->value, 'automatique' => true, 'motifs' => $resultat->motifs],
                'traite_le' => now(),
            ]);
        }

        return $resultat->issue;
    }
}
