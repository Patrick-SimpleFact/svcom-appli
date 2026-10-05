<?php

namespace App\Actions;

use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Enums\TypeDecisionDedoublonnage;
use App\Models\Admin;
use App\Models\DecisionDedoublonnage;
use App\Models\ElementATraiter;
use App\Models\Offre;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Décision manuelle sur deux offres (COLLECTE §7.2) : « fusionner » (même séance) ou « séparer » (séances différentes).
 * Elle est mémorisée et réappliquée à chaque collecte ; une séparation n'est jamais annulée par la machine.
 * Les éléments des files « Doublons probables » et « Fusions à contrôler » concernant cette paire sont traités,
 * et les séances concernées sont republiées aussitôt.
 */
class DeciderDoublon
{
    public function handle(Offre $a, Offre $b, TypeDecisionDedoublonnage $type): void
    {
        if ($a->is($b)) {
            throw new InvalidArgumentException('Une offre ne peut pas être comparée à elle-même.');
        }

        DB::transaction(function () use ($a, $b, $type) {
            [$premiere, $seconde] = $a->id < $b->id ? [$a, $b] : [$b, $a];
            $admin = auth()->user() instanceof Admin ? auth()->id() : null;

            DecisionDedoublonnage::updateOrCreate(
                ['offre_a_id' => $premiere->id, 'offre_b_id' => $seconde->id],
                ['type' => $type, 'admin_id' => $admin],
            );

            $type === TypeDecisionDedoublonnage::Fusionner ? $this->fusionner($a, $b) : $this->separer($a, $b);

            // La décision s'applique tout de suite au catalogue, sans attendre la prochaine collecte.
            app(PublierSource::class)->publierGroupes(collect([$a->fresh(), $b->fresh()]));

            ElementATraiter::whereIn('file', [FileATraiter::DoublonProbable, FileATraiter::FusionAControler])
                ->where('statut', StatutElement::EnAttente)
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('donnees->offre_a_id', $a->id)->where('donnees->offre_b_id', $b->id))
                    ->orWhere(fn ($q) => $q->where('donnees->offre_a_id', $b->id)->where('donnees->offre_b_id', $a->id)))
                ->update([
                    'statut' => StatutElement::Traite,
                    'decision' => json_encode(['type' => $type->value]),
                    'traite_par' => $admin,
                    'traite_le' => now(),
                ]);
        });
    }

    /** B (et les offres qui lui étaient rattachées) rejoint le groupe de A. */
    private function fusionner(Offre $a, Offre $b): void
    {
        $groupeA = $a->premiereDuGroupe()->id;
        $groupeB = $b->premiereDuGroupe()->id;

        if ($groupeA === $groupeB) {
            return;
        }

        Offre::where('meme_seance_que_id', $groupeB)->update(['meme_seance_que_id' => $groupeA]);
        Offre::whereKey($groupeB)->update(['meme_seance_que_id' => $groupeA]);

        // Une même séance, donc un même spectacle : celui du groupe de A (K07).
        $spectacle = Offre::whereKey($groupeA)->value('spectacle_id');
        if ($spectacle !== null) {
            Offre::where(fn ($q) => $q->whereKey($groupeA)->orWhere('meme_seance_que_id', $groupeA))->update(['spectacle_id' => $spectacle]);
        }
    }

    /** Si A et B sont dans le même groupe, celle qui n'en est pas la première en sort. */
    private function separer(Offre $a, Offre $b): void
    {
        if ($a->premiereDuGroupe()->id !== $b->premiereDuGroupe()->id) {
            return;
        }

        $sortante = $b->meme_seance_que_id !== null ? $b : $a;
        $sortante->update(['meme_seance_que_id' => null]);
    }
}
