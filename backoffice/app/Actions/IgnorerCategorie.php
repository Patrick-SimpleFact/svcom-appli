<?php

namespace App\Actions;

use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Models\ElementATraiter;
use InvalidArgumentException;

/**
 * Une catégorie « À classer » trop large pour recevoir un genre (« Spectacles », « Arts & Theatre »…) est ignorée :
 * une correspondance passerait avant les mots du titre (ClasseurGenres) et rangerait mal beaucoup d'annonces.
 * Ses annonces gardent le genre trouvé par les mots, sinon « Autres ». La catégorie ne revient plus dans la file (ClasserAnnonce).
 */
class IgnorerCategorie
{
    public const DECISION = 'ignoree';

    public function handle(ElementATraiter $element): void
    {
        if ($element->file !== FileATraiter::AClasser) {
            throw new InvalidArgumentException('Seule une catégorie « À classer » peut être ignorée.');
        }

        if ($element->fresh()?->statut === StatutElement::Traite) {
            return;
        }

        $element->update([
            'statut' => StatutElement::Traite,
            'decision' => [self::DECISION => true],
            'traite_par' => auth()->id(),
            'traite_le' => now(),
        ]);
    }
}
