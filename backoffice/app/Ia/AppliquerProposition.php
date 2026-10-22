<?php

namespace App\Ia;

use App\Enums\StatutElement;
use App\Enums\StatutPropositionIa;
use App\Models\PropositionIa;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Applique une proposition de l'assistant IA par l'action de sa file : en mode Actif (par l'assistant),
 * ou quand un admin clique « Accepter » (l'admin connecté est alors l'auteur de la décision).
 */
class AppliquerProposition
{
    public function __construct(private Assistant $assistant) {}

    public function handle(PropositionIa $proposition): void
    {
        if ($proposition->statut !== StatutPropositionIa::Proposee) {
            throw new InvalidArgumentException('Cette proposition n’attend plus de décision.');
        }

        DB::transaction(function () use ($proposition) {
            if ($proposition->element->statut === StatutElement::EnAttente) {
                $this->assistant->tache($proposition->file)->appliquer($proposition);
            }

            $proposition->update(['statut' => StatutPropositionIa::Appliquee, 'decidee_le' => now()]);
        });
    }
}
