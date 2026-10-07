<?php

namespace App\Actions;

use App\Enums\StatutRepresentation;
use App\Models\Offre;
use App\Models\Representation;

/**
 * Masquer une séance (F7.8) : ex. concert annulé par l'organisateur mais encore en vente sur une billetterie.
 * Immédiat (l'app lit le statut) et réversible ; la collecte ne la réaffiche jamais d'elle-même (statut verrouillé).
 */
class MasquerRepresentation
{
    public function masquer(Representation $representation): void
    {
        $representation->update(['statut' => StatutRepresentation::Masquee]);
    }

    /** Retour au statut que la collecte lui donnerait : programmée si une billetterie la vend encore (ou saisie à la main), sinon retirée. */
    public function reafficher(Representation $representation): void
    {
        $offres = Offre::where('representation_id', $representation->id);
        $vendue = (clone $offres)->whereNull('disparue_le')->exists() || ! $offres->exists();

        $representation->statut = $vendue ? StatutRepresentation::Programmee : StatutRepresentation::Retiree;
        $representation->save();

        // Le statut redevient celui de la collecte.
        $representation->champs_verrouilles = array_values(array_diff($representation->champs_verrouilles ?? [], ['statut']));
        $representation->saveQuietly();
    }
}
