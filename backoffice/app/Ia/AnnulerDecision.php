<?php

namespace App\Ia;

use App\Actions\DeciderDoublon;
use App\Actions\FusionnerSpectacles;
use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Enums\StatutPropositionIa;
use App\Enums\TypeDecisionDedoublonnage;
use App\Models\CorrespondanceGenre;
use App\Models\Offre;
use App\Models\PropositionIa;
use App\Models\Spectacle;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Annule une décision appliquée par l'assistant IA (liste « Décisions de l'IA »).
 * - À trier, À classer, Spectacles à contrôler : l'élément revient en attente, dans « Mes actions du jour »
 *   (la correspondance de genre créée est supprimée ; des lieux détachés rejoignent leur spectacle).
 * - Doublons et fusions : la décision inverse est enregistrée (une décision de dédoublonnage est mémorisée par la collecte,
 *   on ne peut pas revenir à « rien décidé »).
 */
class AnnulerDecision
{
    public function handle(PropositionIa $decision): string
    {
        if ($decision->statut !== StatutPropositionIa::Appliquee || $decision->modele !== 'cascade') {
            throw new InvalidArgumentException('Seule une décision appliquée par l’assistant peut être annulée.');
        }

        $element = $decision->element;
        $p = $decision->proposition;

        $message = DB::transaction(function () use ($decision, $element, $p) {
            $message = match ($element->file) {
                FileATraiter::DoublonProbable, FileATraiter::FusionAControler => $this->inverserDoublon($element->donnees, $decision),
                FileATraiter::AClasser => $this->rouvrir($element, fn () => ($p['decision'] ?? null) === 'classer'
                    ? CorrespondanceGenre::where('source_id', $element->cible_id)->where('categorie_source', $element->donnees['categorie'])->delete()
                    : null),
                FileATraiter::SpectacleAControler => $this->rouvrir($element, fn () => ($p['decision'] ?? null) === 'separer' && isset($element->decision['nouveau_spectacle_id'])
                    ? app(FusionnerSpectacles::class)->handle(Spectacle::findOrFail($element->decision['nouveau_spectacle_id']), Spectacle::findOrFail($element->cible_id))
                    : null),
                default => $this->rouvrir($element),
            };

            $decision->update(['statut' => StatutPropositionIa::Annulee, 'decidee_le' => now()]);

            return $message;
        });

        return $message;
    }

    private function rouvrir($element, ?callable $defaire = null): string
    {
        $defaire && $defaire();
        $element->update(['statut' => StatutElement::EnAttente, 'decision' => null, 'traite_par' => null, 'traite_le' => null]);

        return 'Décision annulée : l’élément est de nouveau dans « Mes actions du jour ».';
    }

    private function inverserDoublon(array $donnees, PropositionIa $decision): string
    {
        $fusionnait = in_array($decision->proposition['decision'] ?? null, ['meme_seance', 'confirmer'], true);
        $inverse = $fusionnait ? TypeDecisionDedoublonnage::Separer : TypeDecisionDedoublonnage::Fusionner;
        app(DeciderDoublon::class)->handle(Offre::findOrFail($donnees['offre_a_id']), Offre::findOrFail($donnees['offre_b_id']), $inverse);
        $decision->element->update(['decision' => ['type' => $inverse->value], 'traite_par' => auth()->id(), 'traite_le' => now()]);

        return $fusionnait ? 'Décision inversée : les deux séances sont de nouveau séparées.' : 'Décision inversée : les deux séances sont fusionnées.';
    }
}
