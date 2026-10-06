<?php

namespace App\Actions;

use App\Enums\IssueFiltrage;
use App\Enums\StatutElement;
use App\Models\Admin;
use App\Models\ElementATraiter;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Décision manuelle sur des annonces « À trier » (F7.4) : garder (le spectacle sera publié à la prochaine collecte
 * de sa source) ou exclure (écarté définitivement). Mémorisée : le tri automatique la respecte toujours (TrierAnnonce).
 */
class DeciderTri
{
    /** @param  Collection<int, ElementATraiter>  $elements */
    public function handle(Collection $elements, IssueFiltrage $issue): int
    {
        if ($issue === IssueFiltrage::ATrier) {
            throw new InvalidArgumentException('Décision attendue : garder ou exclure.');
        }

        $traites = 0;

        foreach ($elements->chunk(1000) as $paquet) {
            $traites += ElementATraiter::whereKey($paquet->modelKeys())
                ->where('statut', StatutElement::EnAttente)
                ->update([
                    'statut' => StatutElement::Traite,
                    'decision' => json_encode(['issue' => $issue->value]),
                    'traite_par' => auth()->user() instanceof Admin ? auth()->id() : null,
                    'traite_le' => now(),
                ]);
        }

        return $traites;
    }
}
